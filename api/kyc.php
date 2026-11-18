<?php
/**
 * CStore — KYC API
 * GET  ?action=status      (user's KYC status)
 * POST ?action=submit      (multipart — KYC application)
 * GET  ?action=admin_list  (admin DataTable)
 * POST ?action=approve     { id }
 * POST ?action=deny        { id, reason }
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../telegram/bot.php';

$action = $_GET['action'] ?? '';
$db = DB::get();

const KYC_DENY_COOLDOWN = 24 * 3600;

/** SQLite datetime('now') values are UTC — parse them as such regardless of PHP's timezone. */
function kyc_utc_ts(?string $dt): int {
    return $dt ? (int)strtotime($dt . ' UTC') : 0;
}

/**
 * Validate a KYC text field: trimmed, length-bounded, no control chars and no '<' / '>'.
 * Calls json_error() on failure.
 */
function kyc_text(string $field, string $label, int $min, int $max): string {
    $v = $_POST[$field] ?? '';
    if (!is_string($v)) json_error("{$label} غير صالح");
    $v = trim($v);
    if ($v === '') json_error("{$label} مطلوب");
    $len = mb_strlen($v);
    if ($len < $min || $len > $max) json_error("{$label} يجب أن يكون بين {$min} و {$max} حرفاً");
    if (!mb_check_encoding($v, 'UTF-8') || preg_match('~[\x00-\x1F\x7F<>]~u', $v)) {
        json_error("{$label} يحتوي على رموز غير مسموحة");
    }
    return $v;
}

/** Was a file actually sent for $field? */
function kyc_file_sent(string $field): bool {
    return !empty($_FILES[$field]) && is_array($_FILES[$field])
        && !is_array($_FILES[$field]['error'] ?? null)
        && ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
}

/** Why the user may not submit now (or null if allowed). Uses the latest application. */
function kyc_block_reason(PDO $db, int $userId): ?string {
    $stmt = $db->prepare("SELECT status, resolved_at FROM kyc_applications WHERE user_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$userId]);
    $last = $stmt->fetch();
    if (!$last) return null;
    if ($last['status'] === 'pending') return 'لديك طلب تحقق قيد المراجعة بالفعل';
    if ($last['status'] === 'approved') return 'حسابك موثق بالفعل';
    if ($last['status'] === 'denied' && $last['resolved_at']) {
        $cooldownEnd = kyc_utc_ts($last['resolved_at']) + KYC_DENY_COOLDOWN;
        if (time() < $cooldownEnd) {
            $hours = (int)ceil(($cooldownEnd - time()) / 3600);
            return "يجب الانتظار {$hours} ساعة قبل تقديم طلب جديد";
        }
    }
    return null;
}

switch ($action) {
    case 'status':
        $user = require_auth();
        release_session();

        $stmt = $db->prepare("SELECT id, status, deny_reason, created_at, resolved_at FROM kyc_applications WHERE user_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$user['id']]);
        $kyc = $stmt->fetch();

        $response = ['status' => 'none', 'application' => null, 'can_reapply' => false, 'cooldown_remaining' => 0];
        if ($kyc) {
            $response['status'] = $kyc['status'];
            $response['application'] = $kyc;
            if ($kyc['status'] === 'denied' && $kyc['resolved_at']) {
                $cooldownEnd = kyc_utc_ts($kyc['resolved_at']) + KYC_DENY_COOLDOWN;
                $remaining = $cooldownEnd - time();
                $response['can_reapply'] = $remaining <= 0;
                $response['cooldown_remaining'] = max(0, $remaining);
            }
        }
        json_success($response);
        break;

    case 'submit':
        require_method('POST');
        require_csrf();
        $user = require_auth();
        release_session();
        $userId = (int)$user['id'];
        rate_limit('kyc:' . $userId, 5, 3600);

        // Fast fail before touching any files (re-checked atomically on insert).
        $block = kyc_block_reason($db, $userId);
        if ($block !== null) json_error($block);

        // Validate fields
        $firstName = kyc_text('first_name', 'الاسم الأول', 1, 50);
        $lastName  = kyc_text('last_name', 'الاسم الأخير', 1, 50);
        $fullLen = mb_strlen($firstName . ' ' . $lastName);
        if ($fullLen < 3 || $fullLen > 100) json_error('الاسم الكامل يجب أن يكون بين 3 و 100 حرف');
        $address = kyc_text('address', 'العنوان', 3, 255);
        $phone   = kyc_text('phone', 'رقم الهاتف', 6, 20);
        if (!preg_match('/^\+?[0-9 \-]{6,20}$/', $phone)) json_error('رقم الهاتف غير صالح');
        $idType = $_POST['id_type'] ?? '';
        if (!is_string($idType) || !in_array($idType, ['citizen_id', 'passport'], true)) json_error('نوع الهوية غير صالح');
        $idNumber = kyc_text('id_number', 'رقم الهوية', 4, 30);
        if (!preg_match('/^[A-Za-z0-9\-]{4,30}$/', $idNumber)) json_error('رقم الهوية غير صالح (أحرف لاتينية وأرقام فقط)');

        // All three documents are required — check before storing any of them.
        $docs = [
            'front_image'  => 'صورة الوجه الأمامي للهوية مطلوبة',
            'back_image'   => 'صورة الوجه الخلفي للهوية مطلوبة',
            'selfie_image' => 'صورة سيلفي مع الهوية مطلوبة',
        ];
        foreach ($docs as $field => $msg) {
            if (!kyc_file_sent($field)) json_error($msg);
        }

        // Store uploads. handle_upload() exits via json_error() on a bad file, so a shutdown
        // hook removes whatever this request already stored unless the insert committed.
        $stored = [];
        $committed = false;
        register_shutdown_function(function () use (&$stored, &$committed) {
            if (!$committed) foreach ($stored as $p) delete_upload($p);
        });
        foreach ($docs as $field => $msg) {
            $path = handle_upload($field, UPLOAD_KYC);
            if (!$path) json_error($msg);
            $stored[$field] = $path;
        }

        try {
            $kycId = DB::transaction(function (PDO $db) use ($userId, $firstName, $lastName, $address, $phone, $idType, $idNumber, $stored) {
                // Atomic re-check: no second pending/approved application can slip in concurrently.
                $block = kyc_block_reason($db, $userId);
                if ($block !== null) return $block;
                $stmt = $db->prepare("INSERT INTO kyc_applications (user_id, first_name, last_name, address, phone, id_type, id_number, front_image, back_image, selfie_image) VALUES (?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$userId, $firstName, $lastName, $address, $phone, $idType, $idNumber, $stored['front_image'], $stored['back_image'], $stored['selfie_image']]);
                return (int)$db->lastInsertId();
            });
        } catch (Throwable $e) {
            server_error($e);
        }
        if (is_string($kycId)) json_error($kycId);
        $committed = true;

        // Telegram notification (parse_mode=HTML: escape every value)
        $idTypeLabel = $idType === 'citizen_id' ? 'بطاقة هوية' : 'جواز سفر';
        $text = "🪪 طلب تحقق (KYC) #{$kycId}\n";
        $text .= '👤 ' . tg_esc($user['username']) . "\n";
        $text .= '📛 ' . tg_esc($firstName . ' ' . $lastName) . "\n";
        $text .= '📱 ' . tg_esc($phone) . "\n";
        $text .= '🏠 ' . tg_esc($address) . "\n";
        $text .= '🆔 ' . tg_esc($idTypeLabel) . ': ' . tg_esc($idNumber) . "\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ موافقة', 'callback_data' => "kyc_approve:{$kycId}"],
                    ['text' => '❌ رفض', 'callback_data' => "kyc_deny:{$kycId}"],
                ],
            ],
        ];

        $msgId = null;
        try {
            $msgId = telegram_send_message($text, $keyboard);

            // Send the documents as uploaded photos (files are private — never send links)
            foreach ([
                ['front_image', '🖼 الوجه الأمامي'],
                ['back_image', '🖼 الوجه الخلفي'],
                ['selfie_image', '🤳 سيلفي مع الهوية'],
            ] as [$field, $caption]) {
                $fullPath = ROOT_DIR . '/' . $stored[$field];
                if (is_file($fullPath)) {
                    telegram_send_photo($fullPath, tg_esc($caption) . ' — KYC #' . (int)$kycId);
                }
            }
        } catch (Throwable $e) {
            error_log('[CStore] KYC telegram notify failed: ' . $e->getMessage());
        }

        if ($msgId) {
            $db->prepare("UPDATE kyc_applications SET telegram_message_id=? WHERE id=?")->execute([$msgId, $kycId]);
        }

        json_success(['kyc_id' => $kycId], 'تم تقديم طلب التحقق بنجاح');
        break;

    case 'admin_list':
        require_admin();
        release_session();
        if (isset($_GET['draw'])) {
            $esc = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            datatable_response($db, 'kyc_applications', [
                ['db' => 'kyc_applications.id', 'searchable' => false],
                ['db' => 'users.username'],
                ['db' => 'kyc_applications.first_name', 'select' => "kyc_applications.first_name || ' ' || kyc_applications.last_name as full_name", 'render' => fn($r) => $esc($r['full_name'])],
                ['db' => 'kyc_applications.phone'],
                ['db' => 'kyc_applications.id_type', 'render' => fn($r) => $r['id_type'] === 'citizen_id' ? 'بطاقة هوية' : 'جواز سفر'],
                ['db' => 'kyc_applications.id_number'],
                ['db' => 'kyc_applications.status', 'render' => function($r) {
                    $map = ['pending' => ['معلق','pending'], 'approved' => ['موافق','accepted'], 'denied' => ['مرفوض','denied']];
                    $info = $map[$r['status']] ?? ['غير معروف','pending'];
                    return '<span class="badge badge-' . $info[1] . '">' . $info[0] . '</span>';
                }],
                ['db' => 'kyc_applications.created_at', 'searchable' => false],
                ['db' => 'kyc_applications.id', 'select' => 'kyc_applications.id as action_id, kyc_applications.status as action_status, kyc_applications.front_image, kyc_applications.back_image, kyc_applications.selfie_image', 'searchable' => false, 'render' => function($r) use ($esc) {
                    $btns = '<button class="btn btn-outline btn-sm view-kyc-docs" data-front="' . $esc($r['front_image']) . '" data-back="' . $esc($r['back_image']) . '" data-selfie="' . $esc($r['selfie_image']) . '">📄</button> ';
                    if ($r['action_status'] === 'pending') {
                        $btns .= '<button class="btn btn-success btn-sm approve-kyc" data-id="' . (int)$r['action_id'] . '">✅</button> ';
                        $btns .= '<button class="btn btn-danger btn-sm deny-kyc" data-id="' . (int)$r['action_id'] . '">❌</button>';
                    }
                    return $btns;
                }],
            ], '1=1', [], ['LEFT JOIN users ON kyc_applications.user_id=users.id']);
        }
        $stmt = $db->query("SELECT kyc_applications.*, users.username FROM kyc_applications LEFT JOIN users ON kyc_applications.user_id=users.id ORDER BY kyc_applications.created_at DESC LIMIT 100");
        json_success(['applications' => $stmt->fetchAll()]);
        break;

    case 'approve':
    case 'deny':
        require_method('POST');
        require_csrf();
        require_admin();
        release_session();
        $data = json_body();
        $id = intval($data['id'] ?? 0);
        if ($id <= 0) json_error('معرف الطلب مطلوب');
        $isApprove = ($action === 'approve');

        $reason = '';
        if (!$isApprove) {
            $reason = is_scalar($data['reason'] ?? '') ? trim((string)($data['reason'] ?? '')) : '';
            if (mb_strlen($reason) > 500) json_error('سبب الرفض طويل جداً (الحد الأقصى 500 حرف)');
            if (preg_match('~[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]~', $reason)) json_error('سبب الرفض يحتوي على رموز غير مسموحة');
        }

        try {
            $kyc = DB::transaction(function (PDO $db) use ($id, $isApprove, $reason) {
                $stmt = $db->prepare("SELECT id, telegram_message_id FROM kyc_applications WHERE id=? AND status='pending'");
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                if (!$row) return null;
                if ($isApprove) {
                    $upd = $db->prepare("UPDATE kyc_applications SET status='approved', resolved_at=datetime('now') WHERE id=? AND status='pending'");
                    $upd->execute([$id]);
                } else {
                    $upd = $db->prepare("UPDATE kyc_applications SET status='denied', deny_reason=?, resolved_at=datetime('now') WHERE id=? AND status='pending'");
                    $upd->execute([$reason, $id]);
                }
                return $upd->rowCount() === 1 ? $row : null;
            });
        } catch (Throwable $e) {
            server_error($e);
        }
        if (!$kyc) json_error('الطلب غير موجود أو تم معالجته');

        // Update telegram message (parse_mode=HTML)
        if (!empty($kyc['telegram_message_id'])) {
            try {
                if ($isApprove) {
                    telegram_edit_message((int)$kyc['telegram_message_id'], "🪪 طلب تحقق #{$id}\n\n✅ تم الموافقة");
                } else {
                    $reasonText = $reason !== '' ? "\nالسبب: " . tg_esc($reason) : '';
                    telegram_edit_message((int)$kyc['telegram_message_id'], "🪪 طلب تحقق #{$id}\n\n❌ تم الرفض{$reasonText}");
                }
            } catch (Throwable $e) {
                error_log('[CStore] KYC telegram edit failed: ' . $e->getMessage());
            }
        }

        json_success([], $isApprove ? 'تم الموافقة على طلب التحقق' : 'تم رفض طلب التحقق');
        break;

    default:
        json_error('إجراء غير معروف', 404);
}
