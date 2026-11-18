<?php
require_once __DIR__ . '/../config.php';
$action = $_GET['action'] ?? '';
$db = DB::get();

/** Common guard for every mutating admin action: POST + admin + CSRF. Returns the admin row. */
function users_mutation_guard(): array {
    require_method('POST');
    $admin = require_admin();
    require_csrf();
    release_session();
    return $admin;
}

/** Positive integer user id from the request body, or json_error. */
function users_target_id(array $data): int {
    $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false || $id === null) json_error('معرّف المستخدم غير صالح');
    return (int)$id;
}

try {
switch ($action) {
    case 'list':
        require_admin();
        release_session();
        if (isset($_GET['draw'])) {
            datatable_response($db, 'users', [
                ['db' => 'id', 'searchable' => false],
                ['db' => 'username'],
                ['db' => 'email'],
                ['db' => 'role', 'render' => fn($r) => $r['role'] === 'admin' ? '<span class="badge badge-delivering">مشرف</span>' : 'مستخدم'],
                ['db' => 'wallet_balance', 'searchable' => false, 'render' => fn($r) => '$' . number_format($r['wallet_balance'], 2)],
                ['db' => 'is_active', 'searchable' => false, 'render' => fn($r) => $r['is_active'] ? '<span class="badge badge-accepted">مفعّل</span>' : '<span class="badge badge-canceled">محظور</span>'],
                ['db' => 'id', 'select' => 'id as action_id, role as action_role, is_active as action_active, wallet_balance as action_balance, username as action_username', 'searchable' => false, 'render' => function($r) {
                    $id = (int)$r['id'];
                    $uname = htmlspecialchars((string)$r['username'], ENT_QUOTES, 'UTF-8');
                    $bal = htmlspecialchars((string)$r['wallet_balance'], ENT_QUOTES, 'UTF-8');
                    $btns = '<button class="btn btn-primary btn-sm set-balance" data-id="' . $id . '" data-username="' . $uname . '" data-balance="' . $bal . '">💰</button> ';
                    $btns .= '<button class="btn btn-outline btn-sm set-password" data-id="' . $id . '" data-username="' . $uname . '">🔑</button> ';
                    if ($r['role'] !== 'admin') {
                        if ($r['is_active']) {
                            $btns .= '<button class="btn btn-danger btn-sm ban-user" data-id="' . $id . '">🚫</button>';
                        } else {
                            $btns .= '<button class="btn btn-success btn-sm unban-user" data-id="' . $id . '">✅</button>';
                        }
                    }
                    return $btns;
                }],
            ]);
        }
        $stmt = $db->query("SELECT id, username, email, role, wallet_balance, is_active, ban_reason, created_at FROM users ORDER BY created_at DESC");
        json_success(['users' => $stmt->fetchAll()]);
        break;

    case 'ban':
        $admin = users_mutation_guard();
        $data = json_body();
        $id = users_target_id($data);
        if ($id === (int)$admin['id']) json_error('لا يمكنك حظر حسابك');
        $reason = $data['ban_reason'] ?? '';
        $reason = is_string($reason) ? trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', '', $reason) ?? '') : '';
        if (mb_strlen($reason, 'UTF-8') > 500) json_error('سبب الحظر يجب ألا يتجاوز 500 حرف');
        $banned = DB::transaction(function (PDO $tx) use ($id, $reason) {
            $stmt = $tx->prepare("UPDATE users SET is_active=0, ban_reason=? WHERE id=? AND role != 'admin'");
            $stmt->execute([$reason, $id]);
            if ($stmt->rowCount() !== 1) return false;
            // A banned user must not be auto-logged-in by a remember-me cookie
            $tx->prepare("DELETE FROM remember_tokens WHERE user_id=?")->execute([$id]);
            return true;
        });
        if (!$banned) json_error('المستخدم غير موجود أو لا يمكن حظره', 404);
        json_success([], 'تم حظر المستخدم');
        break;

    case 'unban':
        users_mutation_guard();
        $data = json_body();
        $id = users_target_id($data);
        $stmt = $db->prepare("UPDATE users SET is_active=1, ban_reason=NULL WHERE id=?");
        $stmt->execute([$id]);
        if ($stmt->rowCount() !== 1) json_error('المستخدم غير موجود', 404);
        json_success([], 'تم إلغاء حظر المستخدم');
        break;

    case 'dashboard':
        require_admin();
        release_session();
        $stats = [
            'total_users' => $db->query("SELECT COUNT(*) FROM users WHERE role='user'")->fetchColumn(),
            'total_orders' => $db->query("SELECT COUNT(*) FROM orders")->fetchColumn(),
            'pending_orders' => $db->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetchColumn(),
            'pending_topups' => $db->query("SELECT COUNT(*) FROM topup_requests WHERE status='pending'")->fetchColumn(),
            'total_revenue' => $db->query("SELECT COALESCE(SUM(total_price),0) FROM orders WHERE status IN ('delivered','delivering','accepted')")->fetchColumn(),
            'today_orders' => $db->query("SELECT COUNT(*) FROM orders WHERE date(created_at)=date('now')")->fetchColumn(),
        ];
        json_success(['stats' => $stats]);
        break;

    case 'set_balance':
        users_mutation_guard();
        $data = json_body();
        $id = users_target_id($data);
        $raw = $data['balance'] ?? null;
        if (is_string($raw)) $raw = trim($raw);
        if (!is_int($raw) && !is_float($raw) && !(is_string($raw) && is_numeric($raw))) {
            json_error('قيمة الرصيد غير صالحة');
        }
        $balance = (float)$raw;
        if (!is_finite($balance)) json_error('قيمة الرصيد غير صالحة');
        if ($balance < 0) json_error('الرصيد لا يمكن أن يكون سالباً');
        if ($balance > 10000000) json_error('قيمة الرصيد كبيرة جداً');
        $balance = round($balance, 2);

        $updated = DB::transaction(function (PDO $tx) use ($balance, $id) {
            $stmt = $tx->prepare("UPDATE users SET wallet_balance=? WHERE id=?");
            $stmt->execute([$balance, $id]);
            return $stmt->rowCount() === 1;
        });
        if (!$updated) json_error('المستخدم غير موجود', 404);
        json_success([], 'تم تحديث الرصيد');
        break;

    case 'set_password':
        users_mutation_guard();
        $data = json_body();
        $id = users_target_id($data);
        $password = $data['password'] ?? '';
        if (!is_string($password)) $password = '';
        if ($err = auth_password_error($password)) json_error($err);
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $updated = DB::transaction(function (PDO $tx) use ($hash, $id) {
            $stmt = $tx->prepare("UPDATE users SET password_hash=? WHERE id=?");
            $stmt->execute([$hash, $id]);
            if ($stmt->rowCount() !== 1) return false;
            // Invalidate every remembered device of that user
            $tx->prepare("DELETE FROM remember_tokens WHERE user_id=?")->execute([$id]);
            return true;
        });
        if (!$updated) json_error('المستخدم غير موجود', 404);
        json_success([], 'تم تحديث كلمة المرور');
        break;

    default:
        json_error('إجراء غير معروف', 404);
}
} catch (Throwable $e) {
    server_error($e);
}
