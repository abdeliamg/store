<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../telegram/bot.php';
$action = $_GET['action'] ?? '';
$db = DB::get();
release_session();

/** User-facing error thrown inside a transaction (rolls it back). */
class TopupUserError extends RuntimeException {}

switch ($action) {
    case 'list':
        require_admin();
        if (isset($_GET['draw'])) {
            datatable_response($db, 'topup_requests', [
                ['db' => 'topup_requests.id', 'searchable' => false],
                ['db' => 'users.username'],
                ['db' => 'topup_methods.name', 'select' => 'topup_methods.name as method_name'],
                ['db' => 'topup_requests.amount', 'searchable' => false, 'render' => fn($r) => '$' . number_format((float)$r['amount'], 2)],
                ['db' => 'topup_requests.proof_image', 'searchable' => false, 'render' => fn($r) => $r['proof_image'] ? '<a href="/' . htmlspecialchars($r['proof_image'], ENT_QUOTES) . '" target="_blank" rel="noopener" class="btn btn-outline btn-sm">🖼 عرض</a>' : '-'],
                ['db' => 'topup_requests.status', 'render' => function($r) {
                    $map = ['pending' => ['معلق','pending'], 'accepted' => ['مقبول','accepted'], 'denied' => ['مرفوض','denied']];
                    $info = $map[$r['status']] ?? ['غير معروف','pending'];
                    return '<span class="badge badge-' . $info[1] . '">' . $info[0] . '</span>';
                }],
                ['db' => 'topup_requests.created_at', 'searchable' => false],
                ['db' => 'topup_requests.id', 'select' => 'topup_requests.id as action_id, topup_requests.status as action_status', 'searchable' => false, 'render' => function($r) {
                    if ($r['status'] === 'pending') {
                        $id = (int)$r['id'];
                        return '<button class="btn btn-success btn-sm accept-topup" data-id="' . $id . '">✅</button> ' .
                               '<button class="btn btn-danger btn-sm deny-topup" data-id="' . $id . '">❌</button>';
                    }
                    return '';
                }],
            ], '1=1', [], [
                'LEFT JOIN users ON topup_requests.user_id=users.id',
                'LEFT JOIN topup_methods ON topup_requests.method_id=topup_methods.id',
            ]);
        }
        $stmt = $db->query("SELECT topup_requests.*, users.username, topup_methods.name as method_name FROM topup_requests LEFT JOIN users ON topup_requests.user_id=users.id LEFT JOIN topup_methods ON topup_requests.method_id=topup_methods.id ORDER BY topup_requests.created_at DESC LIMIT 100");
        json_success(['requests' => $stmt->fetchAll()]);
        break;

    case 'accept':
        require_method('POST');
        require_admin();
        require_csrf();
        $data = json_body();
        $id = intval($data['id'] ?? 0);

        try {
            $req = DB::transaction(function (PDO $db) use ($id) {
                $stmt = $db->prepare("SELECT * FROM topup_requests WHERE id=? AND status='pending'");
                $stmt->execute([$id]);
                $req = $stmt->fetch();
                if (!$req) throw new TopupUserError('الطلب غير موجود أو تم معالجته');

                $amount = parse_money($req['amount']);
                if ($amount === null) throw new TopupUserError('مبلغ طلب الشحن غير صالح');

                // Status-guarded transition: only one accept can ever credit the wallet
                $upd = $db->prepare("UPDATE topup_requests SET status='accepted', accepted_amount=?, resolved_at=datetime('now') WHERE id=? AND status='pending'");
                $upd->execute([$amount, $id]);
                if ($upd->rowCount() !== 1) throw new TopupUserError('الطلب غير موجود أو تم معالجته');

                $db->prepare("UPDATE users SET wallet_balance = ROUND(wallet_balance + ?, 2) WHERE id=?")->execute([$amount, $req['user_id']]);
                $req['accepted_amount'] = $amount;
                return $req;
            });
        } catch (TopupUserError $e) {
            json_error($e->getMessage());
        } catch (Throwable $e) {
            server_error($e);
        }

        if (!empty($req['telegram_message_id'])) {
            try {
                telegram_edit_message((int)$req['telegram_message_id'], "💰 طلب شحن #{$id}\n\n✅ تم القبول من لوحة التحكم — المبلغ: $" . tg_esc(number_format($req['accepted_amount'], 2)));
            } catch (Throwable $e) {
                error_log('[CStore] telegram edit failed: ' . $e->getMessage());
            }
        }
        json_success([], 'تم قبول طلب الشحن');
        break;

    case 'deny':
        require_method('POST');
        require_admin();
        require_csrf();
        $data = json_body();
        $id = intval($data['id'] ?? 0);

        try {
            $req = DB::transaction(function (PDO $db) use ($id) {
                $stmt = $db->prepare("SELECT * FROM topup_requests WHERE id=?");
                $stmt->execute([$id]);
                $req = $stmt->fetch();
                $upd = $db->prepare("UPDATE topup_requests SET status='denied', resolved_at=datetime('now') WHERE id=? AND status='pending'");
                $upd->execute([$id]);
                if (!$req || $upd->rowCount() !== 1) throw new TopupUserError('الطلب غير موجود أو تم معالجته');
                return $req;
            });
        } catch (TopupUserError $e) {
            json_error($e->getMessage());
        } catch (Throwable $e) {
            server_error($e);
        }

        if (!empty($req['telegram_message_id'])) {
            try {
                telegram_edit_message((int)$req['telegram_message_id'], "💰 طلب شحن #{$id}\n\n❌ تم الرفض من لوحة التحكم");
            } catch (Throwable $e) {
                error_log('[CStore] telegram edit failed: ' . $e->getMessage());
            }
        }
        json_success([], 'تم رفض طلب الشحن');
        break;

    default:
        json_error('إجراء غير معروف', 404);
}
