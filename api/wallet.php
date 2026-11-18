<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../telegram/bot.php';
$action = $_GET['action'] ?? '';
$db = DB::get();
release_session();

switch ($action) {
    case 'topup':
        require_method('POST');
        require_csrf();
        $user = require_auth();
        rate_limit('topup:' . $user['id'], 10, 3600);
        $methodId = intval($_POST['method_id'] ?? 0);
        if (!$methodId) json_error('طريقة الدفع مطلوبة');
        $amount = parse_money($_POST['amount'] ?? null);
        if ($amount === null) json_error('المبلغ غير صالح، يجب أن يكون أكبر من صفر ولا يتجاوز 1000000');

        $stmt = $db->prepare("SELECT * FROM topup_methods WHERE id=? AND is_active=1");
        $stmt->execute([$methodId]);
        $method = $stmt->fetch();
        if (!$method) json_error('طريقة الدفع غير متاحة');

        // KYC gate: if method requires KYC, user must be approved
        if (!empty($method['require_kyc'])) {
            $kycStatus = get_user_kyc_status($user['id']);
            if ($kycStatus !== 'approved') {
                json_error('هذه الطريقة تتطلب توثيق الحساب (KYC)');
            }
        }

        $proofImage = handle_upload('proof_image', UPLOAD_PROOFS);
        if (!$proofImage) json_error('إثبات الدفع مطلوب');

        try {
            $stmt = $db->prepare("INSERT INTO topup_requests (user_id, method_id, amount, proof_image) VALUES (?,?,?,?)");
            $stmt->execute([$user['id'], $methodId, $amount, $proofImage]);
            $requestId = (int)$db->lastInsertId();
        } catch (Throwable $e) {
            delete_upload($proofImage); // never leave an orphan proof file
            server_error($e);
        }

        // Telegram notification. The proof is private: it is sent as a photo, never as a public link.
        try {
            $text = "💰 طلب شحن رصيد #{$requestId}\n";
            $text .= "👤 المستخدم: " . tg_esc($user['username']) . "\n";
            $text .= "💳 الطريقة: " . tg_esc($method['name']) . "\n";
            $text .= "💵 المبلغ: $" . tg_esc(number_format($amount, 2)) . "\n";
            $text .= "🖼 الإثبات: مرفق في الصورة التالية";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '✅ قبول', 'callback_data' => "topup_accept:{$requestId}"],
                        ['text' => '✅ قبول بمبلغ مخصص', 'callback_data' => "topup_custom:{$requestId}"],
                        ['text' => '❌ رفض', 'callback_data' => "topup_deny:{$requestId}"],
                    ],
                ],
            ];

            $msgId = telegram_send_message($text, $keyboard);

            $proofPath = ROOT_DIR . '/' . $proofImage;
            if (is_file($proofPath)) {
                telegram_send_photo($proofPath, "🖼 إثبات الدفع — طلب شحن #{$requestId}");
            }

            if ($msgId) {
                $db->prepare("UPDATE topup_requests SET telegram_message_id=? WHERE id=?")->execute([$msgId, $requestId]);
            }
        } catch (Throwable $e) {
            error_log('[CStore] topup telegram notification failed: ' . $e->getMessage());
        }

        json_success(['request_id' => $requestId], 'تم إرسال طلب الشحن');
        break;

    case 'history':
        $user = require_auth();
        $limit = intval($_GET['limit'] ?? 10);
        $offset = max(0, intval($_GET['offset'] ?? 0));
        if ($limit < 1 || $limit > 50) $limit = 10;
        $stmt = $db->prepare("SELECT topup_requests.*, topup_methods.name as method_name FROM topup_requests LEFT JOIN topup_methods ON topup_requests.method_id=topup_methods.id WHERE topup_requests.user_id=? ORDER BY topup_requests.created_at DESC LIMIT ? OFFSET ?");
        $stmt->execute([$user['id'], $limit + 1, $offset]);
        $rows = $stmt->fetchAll();
        $hasMore = count($rows) > $limit;
        if ($hasMore) array_pop($rows);
        json_success(['requests' => $rows, 'has_more' => $hasMore]);
        break;

    case 'balance':
        $user = require_auth_fresh();
        json_success(['balance' => floatval($user['wallet_balance'])]);
        break;

    default:
        json_error('إجراء غير معروف', 404);
}
