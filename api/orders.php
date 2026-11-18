<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../telegram/bot.php';

/** User-facing error thrown inside a transaction: rolls it back, message is safe to show. */
if (!class_exists('OrderUserError')) {
    class OrderUserError extends RuntimeException {}
}

/**
 * Track a file uploaded during this request. Unless orders_keep_uploads() is
 * called (after the DB work succeeded), tracked files are deleted at shutdown,
 * so validation errors / failed transactions never leave orphan uploads.
 */
function orders_track_upload(?string $path): ?string {
    static $registered = false;
    if (!$path) return $path;
    $GLOBALS['__orders_uploads'][] = $path;
    if (!$registered) {
        $registered = true;
        register_shutdown_function(function () {
            if (!empty($GLOBALS['__orders_uploads_keep'])) return;
            foreach ($GLOBALS['__orders_uploads'] ?? [] as $p) {
                delete_upload($p);
            }
        });
    }
    return $path;
}

function orders_keep_uploads(): void {
    $GLOBALS['__orders_uploads_keep'] = true;
}

/** Fire-and-forget Telegram call: a Telegram failure must never break the request. */
function orders_tg(callable $fn) {
    try {
        return $fn();
    } catch (Throwable $e) {
        error_log('[CStore] telegram: ' . $e->getMessage());
        return null;
    }
}

if (!defined('QUEUE_PROCESS_ONLY')) {

$action = $_GET['action'] ?? '';
$db = DB::get();
release_session();

$statusLabelsAr = [
    'pending' => 'قيد الانتظار',
    'accepted' => 'مقبول',
    'delivering' => 'جارٍ التسليم',
    'delivered' => 'تم التسليم',
    'canceled_by_admin' => 'ملغي من المشرف',
    'canceled_by_user' => 'ملغي من المستخدم',
];
$statusBadge = [
    'pending' => 'pending',
    'accepted' => 'accepted',
    'delivering' => 'delivering',
    'delivered' => 'delivered',
    'canceled_by_admin' => 'canceled',
    'canceled_by_user' => 'canceled',
];

switch ($action) {
    case 'create':
        require_method('POST');
        require_csrf();
        $user = require_auth_fresh();
        $itemId = intval($_POST['item_id'] ?? 0);

        $stmt = $db->prepare("SELECT * FROM items WHERE id=? AND is_active=1");
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();
        if (!$item) json_error('المنتج غير موجود');
        if ($item['contact_only']) json_error('هذا المنتج للتواصل فقط');

        // Price is always calculated server-side, never taken from the client
        $pricingType = $item['pricing_type'] ?? 'fixed';
        $totalPrice = money_round($item['price']);
        $option = null;
        $priceOptionId = null;
        $priceOptionLabel = null;
        $pricingInfo = ''; // For Telegram notification (already HTML-escaped)
        $calcFieldId = null;
        $calcNormalized = null;

        if ($pricingType === 'options') {
            $optionId = intval($_POST['price_option_id'] ?? 0);
            if (!$optionId) json_error('يرجى اختيار خيار السعر');
            $stmtOpt = $db->prepare("SELECT * FROM item_price_options WHERE id=? AND item_id=?");
            $stmtOpt->execute([$optionId, $itemId]);
            $option = $stmtOpt->fetch();
            if (!$option) json_error('خيار السعر غير صالح');
            $totalPrice = money_round($option['price']);
            $priceOptionId = (int)$option['id'];
            $priceOptionLabel = $option['label'];
            $pricingInfo = '📋 الخيار: ' . tg_esc($option['label']);
        } elseif ($pricingType === 'calculated') {
            $calcFieldId = (int)$item['calc_field_id'];
            $stmtCF = $db->prepare("SELECT id, label FROM item_fields WHERE id=? AND item_id=? AND sort_order >= 0");
            $stmtCF->execute([$calcFieldId, $itemId]);
            $calcField = $stmtCF->fetch();
            if (!$calcField) json_error('حقل الحساب غير موجود');
            $calcOperator = $item['calc_operator'] ?? '*';
            $calcValue = (float)($item['calc_value'] ?? 0);

            $fieldInput = $_POST["field_{$calcFieldId}"] ?? '';
            $fieldInput = is_string($fieldInput) ? trim($fieldInput) : '';
            // Plain decimal only: no exponent, sign, hex, INF/NAN; max 2 decimals
            if (!preg_match('/^\d{1,9}(\.\d{1,2})?$/D', $fieldInput)) {
                json_error('قيمة الحقل يجب أن تكون رقمًا موجبًا (بحد أقصى خانتين عشريتين)');
            }
            $fieldVal = (float)$fieldInput;
            if ($fieldVal <= 0 || $fieldVal > 1000000) {
                json_error('قيمة الحقل يجب أن تكون أكبر من صفر ولا تتجاوز 1000000');
            }
            // Normalized representation (e.g. "007.50" -> "7.5") used everywhere downstream
            $calcNormalized = rtrim(rtrim(number_format($fieldVal, 2, '.', ''), '0'), '.');

            switch ($calcOperator) {
                case '+': $raw = $fieldVal + $calcValue; break;
                case '-': $raw = $fieldVal - $calcValue; break;
                case '*': $raw = $fieldVal * $calcValue; break;
                case '/': $raw = $calcValue != 0 ? $fieldVal / $calcValue : 0; break;
                default:  $raw = $fieldVal * $calcValue;
            }
            $totalPrice = is_finite($raw) ? money_round($raw) : 0.0;
            if ($totalPrice <= 0 || $totalPrice > 1000000) json_error('السعر المحسوب غير صالح');
            $fieldLabel = $calcField['label'] ?: 'القيمة';
            $pricingInfo = '🔢 ' . tg_esc($fieldLabel) . ": {$calcNormalized} " . tg_esc($calcOperator) . ' $' . tg_esc($calcValue) . ' = $' . number_format($totalPrice, 2);
        }

        if (!is_finite($totalPrice) || $totalPrice < 0) json_error('سعر المنتج غير صالح');

        // Cheap early check (authoritative check happens atomically inside the transaction)
        if ((float)$user['wallet_balance'] < $totalPrice) {
            json_error('رصيدك غير كافٍ. الرصيد الحالي: $' . number_format($user['wallet_balance'], 2));
        }

        if ($item['delivery_type'] === 'auto_stock') {
            $stmtCheck = $db->prepare("SELECT COUNT(*) FROM item_stock WHERE item_id=? AND is_sold=0 AND (expires_at IS NULL OR expires_at > datetime('now'))");
            $stmtCheck->execute([$itemId]);
            if ((int)$stmtCheck->fetchColumn() === 0) {
                json_error('المنتج غير متوفر حالياً، يرجى المحاولة لاحقاً');
            }
        }

        // Collect + validate custom fields BEFORE the transaction (uploads are tracked
        // and removed automatically if anything below fails).
        $stmtFields = $db->prepare("SELECT * FROM item_fields WHERE item_id=? AND sort_order >= 0 ORDER BY sort_order ASC");
        $stmtFields->execute([$itemId]);
        $fields = $stmtFields->fetchAll();
        $fieldValues = [];
        foreach ($fields as $f) {
            $key = "field_{$f['id']}";
            if ($f['type'] === 'image') {
                $val = orders_track_upload(handle_upload($key, UPLOAD_FIELDS)) ?? '';
            } elseif ($calcFieldId !== null && (int)$f['id'] === $calcFieldId) {
                $val = $calcNormalized;
            } else {
                $val = $_POST[$key] ?? '';
                $val = is_string($val) ? trim($val) : '';
                if (mb_strlen($val) > 1000) json_error("الحقل '{$f['label']}' طويل جداً");
            }
            if ($f['is_required'] && $val === '') {
                json_error("الحقل '{$f['label']}' مطلوب");
            }
            $rawVal = $val; // Raw customer input (before prefix/suffix)
            if ($f['type'] !== 'image' && $val !== '') {
                $val = ($f['prefix'] ?? '') . $val . ($f['suffix'] ?? '');
            }
            $fieldValues[] = ['field_id' => (int)$f['id'], 'label' => $f['label'], 'value' => $val, 'type' => $f['type'], 'raw_value' => $rawVal];
        }

        // Supplier API URL: every substituted user value is rawurlencode()d
        $apiUrlTemplate = '';
        $apiUrlResolved = '';
        if ($item['delivery_type'] === 'api_json') {
            $apiUrlTemplate = (string)($item['api_url'] ?? '');
            if ($pricingType === 'options' && $option && !empty($option['api_url'])) {
                $apiUrlTemplate = (string)$option['api_url'];
            }
            $apiUrlResolved = $apiUrlTemplate;
            if ($apiUrlResolved !== '') {
                foreach ($fieldValues as $fv) {
                    $placeholder = '{' . $fv['label'] . '}';
                    if (strpos($apiUrlResolved, $placeholder) !== false) {
                        $apiUrlResolved = str_replace($placeholder, rawurlencode((string)$fv['raw_value']), $apiUrlResolved);
                    }
                }
            }
        }

        $cancelDeadline = null;
        if ($item['cancel_window_seconds'] > 0 && $item['delivery_type'] === 'manual') {
            $cancelDeadline = gmdate('Y-m-d H:i:s', time() + (int)$item['cancel_window_seconds']);
        }

        try {
            $result = DB::transaction(function (PDO $db) use ($user, $itemId, $item, $totalPrice, $cancelDeadline, $priceOptionId, $priceOptionLabel, $fieldValues, $apiUrlTemplate, $apiUrlResolved) {
                // Atomic, guarded debit
                $debit = $db->prepare("UPDATE users SET wallet_balance = ROUND(wallet_balance - ?, 2) WHERE id=? AND ROUND(wallet_balance, 2) >= CAST(? AS REAL)");
                $debit->execute([$totalPrice, $user['id'], $totalPrice]);
                if ($debit->rowCount() !== 1) {
                    $stmtBal = $db->prepare("SELECT wallet_balance FROM users WHERE id=?");
                    $stmtBal->execute([$user['id']]);
                    throw new OrderUserError('رصيدك غير كافٍ. الرصيد الحالي: $' . number_format((float)$stmtBal->fetchColumn(), 2));
                }

                $stmt = $db->prepare("INSERT INTO orders (user_id, item_id, quantity, total_price, cancel_deadline, price_option_id, price_option_label) VALUES (?,?,1,?,?,?,?)");
                $stmt->execute([$user['id'], $itemId, $totalPrice, $cancelDeadline, $priceOptionId, $priceOptionLabel]);
                $orderId = (int)$db->lastInsertId();

                $stmtInsert = $db->prepare("INSERT INTO order_field_values (order_id, field_id, value) VALUES (?,?,?)");
                foreach ($fieldValues as $fv) {
                    $stmtInsert->execute([$orderId, $fv['field_id'], $fv['value']]);
                }

                $autoDelivered = false;
                if ($item['delivery_type'] === 'auto_stock') {
                    $pick = $db->prepare("SELECT * FROM item_stock WHERE item_id=? AND is_sold=0 AND (expires_at IS NULL OR expires_at > datetime('now')) ORDER BY id ASC LIMIT 1");
                    $claim = $db->prepare("UPDATE item_stock SET is_sold=1, sold_to_order_id=? WHERE id=? AND is_sold=0");
                    $stockItem = null;
                    for ($i = 0; $i < 5 && !$stockItem; $i++) {
                        $pick->execute([$itemId]);
                        $row = $pick->fetch();
                        $pick->closeCursor();
                        if (!$row) break;
                        $claim->execute([$orderId, $row['id']]);
                        if ($claim->rowCount() === 1) $stockItem = $row;
                    }
                    if (!$stockItem) throw new OrderUserError('المنتج غير متوفر حالياً');

                    if ($stockItem['content']) {
                        $db->prepare("INSERT INTO order_deliveries (order_id, type, content) VALUES (?, 'text', ?)")->execute([$orderId, $stockItem['content']]);
                    }
                    if ($stockItem['image_path']) {
                        $db->prepare("INSERT INTO order_deliveries (order_id, type, content) VALUES (?, 'image', ?)")->execute([$orderId, $stockItem['image_path']]);
                    }
                    $db->prepare("UPDATE orders SET status='delivered', delivered_at=datetime('now'), resolved_at=datetime('now') WHERE id=? AND status='pending'")->execute([$orderId]);
                    $autoDelivered = true;
                } elseif ($item['delivery_type'] === 'api_json') {
                    // Queue-based API delivery — order stays pending
                    $db->prepare("INSERT INTO api_queue (order_id, api_url_template, api_url_resolved) VALUES (?, ?, ?)")
                        ->execute([$orderId, $apiUrlTemplate, $apiUrlResolved]);
                }

                $stmtBal = $db->prepare("SELECT wallet_balance FROM users WHERE id=?");
                $stmtBal->execute([$user['id']]);
                return [
                    'order_id' => $orderId,
                    'auto_delivered' => $autoDelivered,
                    'new_balance' => (float)$stmtBal->fetchColumn(),
                ];
            });
        } catch (OrderUserError $e) {
            json_error($e->getMessage());
        } catch (Throwable $e) {
            server_error($e);
        }
        orders_keep_uploads();
        $orderId = $result['order_id'];
        $autoDelivered = $result['auto_delivered'];

        // Telegram notification (manual / queued orders)
        if (!$autoDelivered) {
            orders_tg(function () use ($db, $orderId, $user, $item, $totalPrice, $fieldValues, $pricingInfo) {
                $msgId = sendOrderNotification($orderId, $user['username'], $item['name'], $totalPrice, $fieldValues, $pricingInfo, $item['delivery_type']);
                if ($msgId) {
                    $db->prepare("UPDATE orders SET telegram_message_id=? WHERE id=?")->execute([$msgId, $orderId]);
                }
            });
        }

        json_success([
            'order_id' => $orderId,
            'new_balance' => floatval($result['new_balance']),
            'auto_delivered' => $autoDelivered,
        ], $autoDelivered ? 'تم إنشاء الطلب وتسليم المنتج تلقائياً' : 'تم إنشاء الطلب بنجاح');
        break;

    // Lightweight status check for polling (avoids loading full order data)
    case 'check_status':
        $user = require_auth();
        $id = intval($_GET['id'] ?? 0);
        $stmt = $db->prepare("SELECT status, delivered_at FROM orders WHERE id=? AND user_id=?");
        $stmt->execute([$id, $user['id']]);
        $order = $stmt->fetch();
        if (!$order) json_error('الطلب غير موجود', 404);
        $stmtDC = $db->prepare("SELECT COUNT(*) FROM order_deliveries WHERE order_id=?");
        $stmtDC->execute([$id]);
        $stmtRP = $db->prepare("SELECT status FROM order_reports WHERE order_id=? ORDER BY id DESC LIMIT 1");
        $stmtRP->execute([$id]);
        $reportRow = $stmtRP->fetch();
        // Queue position for api_json orders
        $queuePosition = 0;
        $stmtQ = $db->prepare("SELECT id, api_url_template, status FROM api_queue WHERE order_id=?");
        $stmtQ->execute([$id]);
        $qEntry = $stmtQ->fetch();
        if ($qEntry && in_array($qEntry['status'], ['pending', 'processing'])) {
            $stmtPos = $db->prepare("SELECT COUNT(*) FROM api_queue WHERE api_url_template=? AND status IN ('pending','processing') AND id <= ?");
            $stmtPos->execute([$qEntry['api_url_template'], $qEntry['id']]);
            $queuePosition = (int)$stmtPos->fetchColumn();
        }

        // Send response and close connection, then process queue in background
        $responseData = [
            'success' => true,
            'status' => $order['status'],
            'delivery_count' => (int)$stmtDC->fetchColumn(),
            'report_status' => $reportRow ? $reportRow['status'] : null,
            'queue_position' => $queuePosition,
        ];
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        header('Connection: close');
        $json = json_encode($responseData, JSON_UNESCAPED_UNICODE);
        header('Content-Length: ' . strlen($json));
        echo $json;

        // Close connection to client — response is fully sent
        if (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        } elseif (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            if (ob_get_level()) ob_end_flush();
            flush();
        }

        // Background: process API queue (invisible to user)
        ignore_user_abort(true);
        set_time_limit(300);
        process_api_queue();
        break;

    case 'cancel':
        require_method('POST');
        require_csrf();
        $user = require_auth_fresh();
        $data = json_body();
        $orderId = intval($data['order_id'] ?? 0);

        try {
            $res = DB::transaction(function (PDO $db) use ($orderId, $user) {
                $stmt = $db->prepare("SELECT * FROM orders WHERE id=? AND user_id=?");
                $stmt->execute([$orderId, $user['id']]);
                $order = $stmt->fetch();
                if (!$order) throw new OrderUserError('الطلب غير موجود');
                if ($order['status'] !== 'pending') throw new OrderUserError('لا يمكن إلغاء هذا الطلب');
                if ($order['cancel_deadline']) {
                    $deadline = strtotime($order['cancel_deadline'] . ' UTC');
                    if ($deadline !== false && time() < $deadline) {
                        throw new OrderUserError('لا يمكن إلغاء الطلب بعد، يرجى الانتظار حتى انتهاء فترة الحماية');
                    }
                }
                $stmtQ = $db->prepare("SELECT status FROM api_queue WHERE order_id=?");
                $stmtQ->execute([$orderId]);
                if ($stmtQ->fetchColumn() === 'processing') {
                    throw new OrderUserError('الطلب قيد المعالجة حالياً ولا يمكن إلغاؤه');
                }

                // Status-guarded transition: only one request can ever win the refund
                $upd = $db->prepare("UPDATE orders SET status='canceled_by_user', resolved_at=datetime('now')
                    WHERE id=? AND user_id=? AND status='pending'
                      AND NOT EXISTS (SELECT 1 FROM api_queue WHERE api_queue.order_id=orders.id AND api_queue.status='processing')");
                $upd->execute([$orderId, $user['id']]);
                if ($upd->rowCount() !== 1) throw new OrderUserError('لا يمكن إلغاء هذا الطلب');

                $db->prepare("UPDATE users SET wallet_balance = ROUND(wallet_balance + ?, 2) WHERE id=?")->execute([money_round($order['total_price']), $user['id']]);
                $db->prepare("DELETE FROM api_queue WHERE order_id=?")->execute([$orderId]);

                $stmtBal = $db->prepare("SELECT wallet_balance FROM users WHERE id=?");
                $stmtBal->execute([$user['id']]);
                return ['balance' => (float)$stmtBal->fetchColumn(), 'tg' => $order['telegram_message_id']];
            });
        } catch (OrderUserError $e) {
            json_error($e->getMessage());
        } catch (Throwable $e) {
            server_error($e, 'فشل في إلغاء الطلب');
        }

        if ($res['tg']) {
            orders_tg(fn() => editOrderMessage((int)$res['tg'], $orderId, 'canceled_by_user'));
        }
        json_success(['new_balance' => floatval($res['balance'])], 'تم إلغاء الطلب وإرجاع المبلغ');
        break;

    // ==========================================
    // Order Report System
    // ==========================================
    case 'report':
        require_method('POST');
        require_csrf();
        $user = require_auth();
        $orderId = intval($_POST['order_id'] ?? 0);
        $details = $_POST['details'] ?? '';
        $details = is_string($details) ? trim($details) : '';

        $stmt = $db->prepare("SELECT * FROM orders WHERE id=? AND user_id=?");
        $stmt->execute([$orderId, $user['id']]);
        $order = $stmt->fetch();
        if (!$order) json_error('الطلب غير موجود');
        if ($order['status'] !== 'delivered') json_error('لا يمكن الإبلاغ عن هذا الطلب');

        // Report window — without a known delivery time the window is treated as expired
        $reportWindow = max(0, intval(DB::setting('report_window_seconds', '180')));
        $deliveredAt = $order['delivered_at'] ?: $order['resolved_at'];
        $deliveredTime = $deliveredAt ? strtotime($deliveredAt . ' UTC') : false;
        if ($deliveredTime === false || time() > $deliveredTime + $reportWindow) {
            json_error('انتهت فترة الإبلاغ');
        }

        if ($details === '') json_error('يرجى كتابة تفاصيل المشكلة');
        if (mb_strlen($details) > 2000) json_error('تفاصيل المشكلة طويلة جداً');

        $existingReport = $db->prepare("SELECT id FROM order_reports WHERE order_id=? AND status='pending'");
        $existingReport->execute([$orderId]);
        if ($existingReport->fetch()) json_error('يوجد بلاغ معلق بالفعل لهذا الطلب');

        $screenshot = orders_track_upload(handle_upload('screenshot', UPLOAD_PROOFS));

        try {
            $reportId = DB::transaction(function (PDO $db) use ($orderId, $user, $details, $screenshot) {
                $chk = $db->prepare("SELECT id FROM order_reports WHERE order_id=? AND status='pending'");
                $chk->execute([$orderId]);
                if ($chk->fetch()) throw new OrderUserError('يوجد بلاغ معلق بالفعل لهذا الطلب');
                $stmt = $db->prepare("INSERT INTO order_reports (order_id, user_id, details, screenshot) VALUES (?,?,?,?)");
                $stmt->execute([$orderId, $user['id'], $details, $screenshot]);
                return (int)$db->lastInsertId();
            });
        } catch (OrderUserError $e) {
            json_error($e->getMessage());
        } catch (Throwable $e) {
            server_error($e);
        }
        orders_keep_uploads();

        orders_tg(function () use ($db, $order, $orderId, $reportId, $user, $details, $screenshot) {
            $stmtItem = $db->prepare("SELECT name FROM items WHERE id=?");
            $stmtItem->execute([$order['item_id']]);
            $itemName = (string)$stmtItem->fetchColumn();

            $text = "⚠️ بلاغ جديد #{$reportId}\n";
            $text .= "📦 طلب #{$orderId} — " . tg_esc($itemName) . "\n";
            $text .= "👤 المستخدم: " . tg_esc($user['username']) . "\n";
            $text .= "💰 المبلغ: $" . number_format($order['total_price'], 2) . "\n";
            $text .= "📝 التفاصيل: " . tg_esc($details) . "\n";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🔄 إرسال بديل', 'callback_data' => "report_alt:{$reportId}"],
                        ['text' => '❌ رفض البلاغ', 'callback_data' => "report_deny:{$reportId}"],
                    ],
                    [
                        ['text' => '💰 إلغاء واسترداد', 'callback_data' => "report_cancel:{$reportId}"],
                    ],
                ],
            ];

            $msgId = telegram_send_message($text, $keyboard);
            if ($screenshot) {
                $photoPath = ROOT_DIR . '/' . $screenshot;
                if (file_exists($photoPath)) {
                    telegram_send_photo($photoPath, "🖼 لقطة شاشة — بلاغ #{$reportId}");
                }
            }
            if ($msgId) {
                $db->prepare("UPDATE order_reports SET telegram_message_id=? WHERE id=?")->execute([$msgId, $reportId]);
            }
        });

        json_success(['report_id' => $reportId], 'تم إرسال البلاغ');
        break;

    case 'report_send_message':
        require_method('POST');
        require_csrf();
        $user = require_auth();
        $reportId = intval($_POST['report_id'] ?? 0);
        $message = $_POST['message'] ?? '';
        $message = is_string($message) ? trim($message) : '';
        $hasImage = !empty($_FILES['image']) && is_array($_FILES['image']) && !empty($_FILES['image']['name']);

        if ($message === '' && !$hasImage) json_error('يرجى كتابة رسالة أو إرفاق صورة');
        if (mb_strlen($message) > 2000) json_error('الرسالة طويلة جداً');

        // Verify report belongs to user and is active
        $stmt = $db->prepare("SELECT order_reports.* FROM order_reports LEFT JOIN orders ON order_reports.order_id=orders.id WHERE order_reports.id=? AND orders.user_id=? AND order_reports.status IN ('pending','alternative_sent')");
        $stmt->execute([$reportId, $user['id']]);
        $report = $stmt->fetch();
        if (!$report) json_error('البلاغ غير موجود أو تم حله');

        $image = orders_track_upload(handle_upload('image', UPLOAD_PROOFS));
        if ($message === '' && !$image) json_error('يرجى كتابة رسالة أو إرفاق صورة');

        try {
            $msgId = DB::transaction(function (PDO $db) use ($reportId, $message, $image) {
                $chk = $db->prepare("SELECT 1 FROM order_reports WHERE id=? AND status IN ('pending','alternative_sent')");
                $chk->execute([$reportId]);
                if (!$chk->fetchColumn()) throw new OrderUserError('البلاغ غير موجود أو تم حله');
                $stmt = $db->prepare("INSERT INTO report_messages (report_id, sender, message, image) VALUES (?, 'user', ?, ?)");
                $stmt->execute([$reportId, $message !== '' ? $message : null, $image]);
                return (int)$db->lastInsertId();
            });
        } catch (OrderUserError $e) {
            json_error($e->getMessage());
        } catch (Throwable $e) {
            server_error($e);
        }
        orders_keep_uploads();

        orders_tg(function () use ($reportId, $report, $user, $message, $image) {
            $text = "💬 رسالة جديدة على بلاغ #{$reportId}\n";
            $text .= "📦 طلب #" . (int)$report['order_id'] . "\n";
            $text .= "👤 " . tg_esc($user['username']) . "\n";
            if ($message !== '') $text .= "📝 " . tg_esc($message) . "\n";
            if ($image) $text .= "🖼️ [صورة مرفقة]\n";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '✉️ رد', 'callback_data' => "report_reply:{$reportId}"],
                        ['text' => '🔄 إرسال بديل', 'callback_data' => "report_alt:{$reportId}"],
                    ],
                    [
                        ['text' => '❌ رفض', 'callback_data' => "report_deny:{$reportId}"],
                        ['text' => '💰 إلغاء واسترداد', 'callback_data' => "report_cancel:{$reportId}"],
                    ],
                ],
            ];
            telegram_send_message($text, $keyboard);

            if ($image) {
                $photoPath = ROOT_DIR . '/' . $image;
                if (file_exists($photoPath)) {
                    telegram_send_photo($photoPath, "🖼️ صورة مرفقة — بلاغ #{$reportId}");
                }
            }
        });

        // Return the created message
        $stmt = $db->prepare("SELECT * FROM report_messages WHERE id=?");
        $stmt->execute([$msgId]);
        json_success(['message' => $stmt->fetch()], 'تم إرسال الرسالة');
        break;

    case 'report_messages':
        $user = require_auth();
        $reportId = intval($_GET['report_id'] ?? 0);

        // Verify report belongs to user
        $stmt = $db->prepare("SELECT order_reports.id FROM order_reports LEFT JOIN orders ON order_reports.order_id=orders.id WHERE order_reports.id=? AND orders.user_id=?");
        $stmt->execute([$reportId, $user['id']]);
        if (!$stmt->fetch()) json_error('البلاغ غير موجود', 404);

        $stmt = $db->prepare("SELECT * FROM report_messages WHERE report_id=? ORDER BY created_at ASC");
        $stmt->execute([$reportId]);
        json_success(['messages' => $stmt->fetchAll()]);
        break;

    case 'report_resolve':
        require_method('POST');
        require_csrf();
        $user = require_auth();
        $data = json_body();
        $orderId = intval($data['order_id'] ?? 0);
        $stmt = $db->prepare("SELECT order_reports.* FROM order_reports LEFT JOIN orders ON order_reports.order_id=orders.id WHERE order_reports.order_id=? AND orders.user_id=? AND order_reports.status='alternative_sent' ORDER BY order_reports.created_at DESC LIMIT 1");
        $stmt->execute([$orderId, $user['id']]);
        $report = $stmt->fetch();
        if (!$report) json_error('لا يوجد بلاغ يمكن تأكيده');
        $upd = $db->prepare("UPDATE order_reports SET status='resolved', resolved_at=datetime('now') WHERE id=? AND status='alternative_sent'");
        $upd->execute([$report['id']]);
        if ($upd->rowCount() !== 1) json_error('لا يوجد بلاغ يمكن تأكيده');
        json_success([], 'تم تأكيد الحل');
        break;

    case 'report_status':
        $user = require_auth();
        $orderId = intval($_GET['order_id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM order_reports WHERE order_id=? AND user_id=? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$orderId, $user['id']]);
        $report = $stmt->fetch();
        json_success(['report' => $report ?: null]);
        break;

    case 'my_orders':
        $user = require_auth();
        $limit = intval($_GET['limit'] ?? 10);
        $offset = max(0, intval($_GET['offset'] ?? 0));
        if ($limit < 1 || $limit > 50) $limit = 10;
        $stmt = $db->prepare("SELECT orders.*, items.name as item_name FROM orders LEFT JOIN items ON orders.item_id=items.id WHERE orders.user_id=? ORDER BY orders.created_at DESC LIMIT ? OFFSET ?");
        $stmt->execute([$user['id'], $limit + 1, $offset]);
        $rows = $stmt->fetchAll();
        $hasMore = count($rows) > $limit;
        if ($hasMore) array_pop($rows);
        json_success(['orders' => $rows, 'has_more' => $hasMore]);
        break;

    case 'get':
        $user = require_auth();
        $id = intval($_GET['id'] ?? 0);
        $stmt = $db->prepare("SELECT orders.*, items.name as item_name, items.delivery_type FROM orders LEFT JOIN items ON orders.item_id=items.id WHERE orders.id=? AND orders.user_id=?");
        $stmt->execute([$id, $user['id']]);
        $order = $stmt->fetch();
        if (!$order) json_error('الطلب غير موجود', 404);

        $stmtD = $db->prepare("SELECT * FROM order_deliveries WHERE order_id=? ORDER BY created_at ASC");
        $stmtD->execute([$id]);
        $deliveries = $stmtD->fetchAll();

        $stmtFV = $db->prepare("SELECT order_field_values.*, item_fields.label, item_fields.type as field_type FROM order_field_values LEFT JOIN item_fields ON order_field_values.field_id=item_fields.id WHERE order_field_values.order_id=?");
        $stmtFV->execute([$id]);
        $fieldValues = $stmtFV->fetchAll();

        // Report info
        $stmtR = $db->prepare("SELECT * FROM order_reports WHERE order_id=? ORDER BY created_at DESC LIMIT 1");
        $stmtR->execute([$id]);
        $report = $stmtR->fetch();

        $reportWindow = intval(DB::setting('report_window_seconds', '180'));

        // Report messages
        $reportMessages = [];
        if ($report) {
            $stmtRM = $db->prepare("SELECT * FROM report_messages WHERE report_id=? ORDER BY created_at ASC");
            $stmtRM->execute([$report['id']]);
            $reportMessages = $stmtRM->fetchAll();
        }

        // Queue position for api_json orders
        $queuePosition = 0;
        if ($order['status'] === 'pending') {
            $stmtQ = $db->prepare("SELECT id, api_url_template, status FROM api_queue WHERE order_id=?");
            $stmtQ->execute([$id]);
            $qEntry = $stmtQ->fetch();
            if ($qEntry && in_array($qEntry['status'], ['pending', 'processing'])) {
                $stmtPos = $db->prepare("SELECT COUNT(*) FROM api_queue WHERE api_url_template=? AND status IN ('pending','processing') AND id <= ?");
                $stmtPos->execute([$qEntry['api_url_template'], $qEntry['id']]);
                $queuePosition = (int)$stmtPos->fetchColumn();
            }
        }

        json_success([
            'order' => $order,
            'deliveries' => $deliveries,
            'field_values' => $fieldValues,
            'report' => $report ?: null,
            'report_messages' => $reportMessages,
            'report_window_seconds' => $reportWindow,
            'queue_position' => $queuePosition,
        ]);
        break;

    case 'admin_list':
        require_admin();
        if (isset($_GET['draw'])) {
            datatable_response($db, 'orders', [
                ['db' => 'orders.id', 'searchable' => false],
                ['db' => 'users.username'],
                ['db' => 'items.name', 'select' => 'items.name as item_name'],
                ['db' => 'orders.total_price', 'searchable' => false, 'render' => fn($r) => '$' . number_format($r['total_price'], 2)],
                ['db' => 'orders.status', 'render' => function($r) use ($statusLabelsAr, $statusBadge) {
                    $cls = $statusBadge[$r['status']] ?? 'pending';
                    $lbl = $statusLabelsAr[$r['status']] ?? $r['status'];
                    return '<span class="badge badge-' . htmlspecialchars($cls, ENT_QUOTES) . '">' . htmlspecialchars((string)$lbl, ENT_QUOTES) . '</span>';
                }],
                ['db' => 'orders.created_at', 'searchable' => false],
                ['db' => 'orders.id', 'select' => 'orders.id as action_id, orders.status as action_status', 'searchable' => false, 'render' => function($r) {
                    if ($r['status'] === 'pending') {
                        $id = (int)$r['id'];
                        return '<button class="btn btn-success btn-sm accept-order" data-id="' . $id . '">✅</button> ' .
                               '<button class="btn btn-danger btn-sm cancel-order" data-id="' . $id . '">❌</button>';
                    }
                    return '';
                }],
            ], '1=1', [], [
                'LEFT JOIN users ON orders.user_id=users.id',
                'LEFT JOIN items ON orders.item_id=items.id',
            ]);
        }
        $stmt = $db->query("SELECT orders.*, users.username, items.name as item_name FROM orders LEFT JOIN users ON orders.user_id=users.id LEFT JOIN items ON orders.item_id=items.id ORDER BY orders.created_at DESC LIMIT 100");
        json_success(['orders' => $stmt->fetchAll()]);
        break;

    case 'admin_accept':
        require_method('POST');
        require_admin();
        require_csrf();
        $data = json_body();
        $orderId = intval($data['order_id'] ?? 0);
        try {
            $res = DB::transaction(function (PDO $db) use ($orderId) {
                $stmtQ = $db->prepare("SELECT status FROM api_queue WHERE order_id=?");
                $stmtQ->execute([$orderId]);
                $queueStatus = $stmtQ->fetchColumn();

                $upd = $db->prepare("UPDATE orders SET status='delivering' WHERE id=? AND status='pending'");
                $upd->execute([$orderId]);
                if ($upd->rowCount() !== 1) return null;
                // Admin takes over: the order must not stay in the API queue
                $db->prepare("DELETE FROM api_queue WHERE order_id=?")->execute([$orderId]);

                $stmtTg = $db->prepare("SELECT telegram_message_id FROM orders WHERE id=?");
                $stmtTg->execute([$orderId]);
                return ['queue_status' => $queueStatus, 'tg' => $stmtTg->fetchColumn()];
            });
        } catch (Throwable $e) {
            server_error($e);
        }
        if (!$res) json_error('الطلب غير موجود أو تمت معالجته بالفعل');
        if ($res['tg']) {
            orders_tg(fn() => editOrderMessage((int)$res['tg'], $orderId, 'delivering'));
        }
        if ($res['queue_status'] === 'processing') {
            orders_tg(fn() => telegram_send_message("⚠️ تم قبول الطلب #{$orderId} يدوياً بينما كان طلب المورد (API) قيد التنفيذ.\nقد يكون المورد قد نفّذ الطلب بالفعل — يرجى التحقق قبل التسليم."));
        }
        json_success([], 'تم قبول الطلب');
        break;

    case 'admin_cancel':
        require_method('POST');
        require_admin();
        require_csrf();
        $data = json_body();
        $orderId = intval($data['order_id'] ?? 0);

        try {
            $res = DB::transaction(function (PDO $db) use ($orderId) {
                $stmt = $db->prepare("SELECT * FROM orders WHERE id=?");
                $stmt->execute([$orderId]);
                $order = $stmt->fetch();
                if (!$order) throw new OrderUserError('الطلب غير موجود');

                $stmtQ = $db->prepare("SELECT status FROM api_queue WHERE order_id=?");
                $stmtQ->execute([$orderId]);
                $queueStatus = $stmtQ->fetchColumn();

                $upd = $db->prepare("UPDATE orders SET status='canceled_by_admin', resolved_at=datetime('now') WHERE id=? AND status IN ('pending','delivering')");
                $upd->execute([$orderId]);
                if ($upd->rowCount() !== 1) throw new OrderUserError('لا يمكن إلغاء هذا الطلب');

                $db->prepare("UPDATE users SET wallet_balance = ROUND(wallet_balance + ?, 2) WHERE id=?")->execute([money_round($order['total_price']), $order['user_id']]);
                $db->prepare("DELETE FROM api_queue WHERE order_id=?")->execute([$orderId]);
                $db->prepare("DELETE FROM bot_state WHERE key='awaiting_delivery' AND value=?")->execute([(string)$orderId]);
                return ['queue_status' => $queueStatus, 'tg' => $order['telegram_message_id']];
            });
        } catch (OrderUserError $e) {
            json_error($e->getMessage());
        } catch (Throwable $e) {
            server_error($e, 'فشل في إلغاء الطلب');
        }
        if ($res['tg']) {
            orders_tg(fn() => editOrderMessage((int)$res['tg'], $orderId, 'canceled_by_admin'));
        }
        if ($res['queue_status'] === 'processing') {
            orders_tg(fn() => telegram_send_message("⚠️ تم إلغاء الطلب #{$orderId} وإرجاع المبلغ بينما كان طلب المورد (API) قيد التنفيذ.\nقد يكون المورد قد نفّذ الطلب بالفعل — يرجى التحقق."));
        }
        json_success([], 'تم إلغاء الطلب وإرجاع المبلغ');
        break;

    default:
        json_error('إجراء غير معروف', 404);
}

} // end !QUEUE_PROCESS_ONLY

function sendOrderNotification(int $orderId, string $username, string $itemName, float $price, array $fieldValues, string $pricingInfo = '', string $deliveryType = 'manual'): ?int {
    // $pricingInfo is pre-escaped by the caller
    $text = "📦 طلب جديد #{$orderId}\n";
    $text .= "👤 المستخدم: " . tg_esc($username) . "\n";
    $text .= "🛒 المنتج: " . tg_esc($itemName) . "\n";
    if ($pricingInfo) {
        $text .= "{$pricingInfo}\n";
    }
    $text .= "💰 السعر: $" . number_format($price, 2) . "\n";
    $text .= "📅 التاريخ: " . date('Y-m-d H:i') . "\n";

    if (!empty($fieldValues)) {
        $text .= "\n📝 معلومات إضافية:\n";
        foreach ($fieldValues as $fv) {
            if ($fv['type'] !== 'image') {
                $text .= "• " . tg_esc($fv['label']) . ": " . tg_esc($fv['value']) . "\n";
            } else {
                $text .= "• " . tg_esc($fv['label']) . ": [صورة مرفقة]\n";
            }
        }
    }

    $silent = false;
    $keyboard = null;

    if ($deliveryType === 'api_json') {
        $silent = true;
        // Check queue position
        $db = DB::get();
        $queueStmt = $db->prepare("SELECT COUNT(*) FROM api_queue WHERE status='pending' AND id <= (SELECT id FROM api_queue WHERE order_id=?)");
        $queueStmt->execute([$orderId]);
        $queuePos = (int)$queueStmt->fetchColumn();

        if ($queuePos <= 1) {
            $text .= "\n⚙️ جارٍ المعالجة تلقائياً...";
            // Empty inline keyboard — keeps message editable by editMessageText
            $keyboard = ['inline_keyboard' => []];
        } else {
            $text .= "\n⏳ في قائمة الانتظار (#{$queuePos})";
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '✅ قبول', 'callback_data' => "order_accept:{$orderId}"],
                        ['text' => '❌ إلغاء', 'callback_data' => "order_cancel:{$orderId}"],
                    ],
                ],
            ];
        }
    } else {
        // Manual delivery — always show buttons
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ قبول', 'callback_data' => "order_accept:{$orderId}"],
                    ['text' => '❌ إلغاء', 'callback_data' => "order_cancel:{$orderId}"],
                ],
            ],
        ];
    }

    $msgId = telegram_send_message($text, $keyboard, $silent);

    foreach ($fieldValues as $fv) {
        if ($fv['type'] === 'image' && $fv['value']) {
            $photoPath = ROOT_DIR . '/' . $fv['value'];
            if (file_exists($photoPath)) {
                telegram_send_photo($photoPath, "📷 " . tg_esc($fv['label']) . " — طلب #{$orderId}");
            }
        }
    }

    return $msgId;
}

function editOrderMessage(int $messageId, int $orderId, string $status): void {
    $statusLabels = [
        'pending' => 'قيد الانتظار',
        'delivering' => 'جارٍ التسليم',
        'delivered' => '✅ تم التسليم',
        'canceled_by_admin' => '❌ ملغي من المشرف',
        'canceled_by_user' => '🔄 ملغي من المستخدم',
    ];
    $label = $statusLabels[$status] ?? tg_esc($status);
    telegram_edit_message($messageId, "📦 طلب #{$orderId}\n\nالحالة: {$label}");
}

// ==========================================
// Supplier API queue
// ==========================================

function api_queue_lock_path(): string {
    return sys_get_temp_dir() . '/cstore_queue.lock';
}

/**
 * Call a supplier URL. Only an HTTP 2xx response with a non-empty body is a success.
 * Returns ['ok' => bool, 'code' => int, 'body' => ?string, 'error' => string].
 * Never logs the URL (it usually carries a supplier token).
 */
function api_supplier_call(string $url): array {
    if ($url === '' || !preg_match('~^https?://~i', $url)) {
        return ['ok' => false, 'code' => 0, 'body' => null, 'error' => 'رابط API غير مضبوط أو غير صالح'];
    }
    $ctx = stream_context_create(['http' => [
        'timeout' => 20,
        'ignore_errors' => true,   // needed to read the status line; checked explicitly below
        'follow_location' => 1,
        'max_redirects' => 3,
        'header' => "User-Agent: CStore/1.0\r\n",
    ]]);
    $http_response_header = [];
    $body = @file_get_contents($url, false, $ctx, 0, 1048576);
    $code = 0;
    foreach ((array)$http_response_header as $h) {
        // Last status line wins (after redirects)
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', (string)$h, $m)) $code = (int)$m[1];
    }
    if ($body === false) {
        return ['ok' => false, 'code' => $code, 'body' => null, 'error' => $code ? "HTTP {$code}" : 'تعذر الاتصال بالمورد أو انتهت المهلة'];
    }
    if ($code < 200 || $code >= 300) {
        return ['ok' => false, 'code' => $code, 'body' => $body, 'error' => "HTTP {$code}"];
    }
    if (trim($body) === '') {
        return ['ok' => false, 'code' => $code, 'body' => $body, 'error' => 'استجابة فارغة من المورد'];
    }
    return ['ok' => true, 'code' => $code, 'body' => $body, 'error' => ''];
}

/** Fetch one pending entry per unique api_url_template (oldest first). */
function api_queue_fetch_batch(PDO $db): array {
    $stmt = $db->prepare("
        SELECT q.* FROM api_queue q
        INNER JOIN (
            SELECT api_url_template, MIN(id) as min_id
            FROM api_queue
            WHERE status = 'pending'
            GROUP BY api_url_template
        ) first ON q.id = first.min_id
        WHERE q.status = 'pending'
        ORDER BY q.id ASC
    ");
    $stmt->execute();
    return $stmt->fetchAll();
}

/** Order context for admin notifications. */
function api_queue_order_info(int $orderId): array {
    $stmt = DB::get()->prepare("SELECT orders.status, orders.telegram_message_id, users.username, items.name AS item_name FROM orders LEFT JOIN users ON orders.user_id=users.id LEFT JOIN items ON orders.item_id=items.id WHERE orders.id=?");
    $stmt->execute([$orderId]);
    return $stmt->fetch() ?: [];
}

/**
 * Process a single queue entry: claim it atomically, call the supplier, then
 * finalize with status-guarded updates. Returns 'delivered' | 'failed' | 'late' | 'skipped'.
 */
function api_queue_process_entry(array $entry): string {
    $queueId = (int)$entry['id'];
    $orderId = (int)$entry['order_id'];

    // 1) Claim the job only if it is still pending AND the order is still pending
    $claimed = DB::transaction(function (PDO $db) use ($queueId, $orderId) {
        $st = $db->prepare("SELECT status FROM orders WHERE id=?");
        $st->execute([$orderId]);
        if ($st->fetchColumn() !== 'pending') {
            $db->prepare("UPDATE api_queue SET status='skipped', response='order not pending', processed_at=datetime('now') WHERE id=? AND status='pending'")->execute([$queueId]);
            return false;
        }
        $u = $db->prepare("UPDATE api_queue SET status='processing' WHERE id=? AND status='pending'");
        $u->execute([$queueId]);
        return $u->rowCount() === 1;
    });
    if (!$claimed) return 'skipped';

    // 2) Call the supplier (outside any transaction)
    $res = api_supplier_call((string)$entry['api_url_resolved']);
    $storedResponse = $res['ok']
        ? mb_substr((string)$res['body'], 0, 4000)
        : mb_substr('Error: ' . $res['error'] . ($res['body'] !== null ? ' | ' . (string)$res['body'] : ''), 0, 4000);

    // 3) Finalize — never mark delivered unless the order is still pending
    try {
        $outcome = DB::transaction(function (PDO $db) use ($queueId, $orderId, $res, $storedResponse) {
            if ($res['ok']) {
                $u = $db->prepare("UPDATE orders SET status='delivered', delivered_at=datetime('now'), resolved_at=datetime('now') WHERE id=? AND status='pending'");
                $u->execute([$orderId]);
                $db->prepare("UPDATE api_queue SET status='done', response=?, processed_at=datetime('now') WHERE id=?")->execute([$storedResponse, $queueId]);
                if ($u->rowCount() === 1) {
                    $db->prepare("INSERT INTO order_deliveries (order_id, type, content) VALUES (?, 'text', ?)")->execute([$orderId, $res['body']]);
                    return 'delivered';
                }
                return 'late';
            }
            $db->prepare("UPDATE api_queue SET status='failed', response=?, processed_at=datetime('now') WHERE id=?")->execute([$storedResponse, $queueId]);
            return 'failed';
        });
    } catch (Throwable $e) {
        error_log('[CStore] api queue finalize failed for order #' . $orderId . ': ' . $e->getMessage());
        try {
            DB::get()->prepare("UPDATE api_queue SET status='failed', response=?, processed_at=datetime('now') WHERE id=?")
                ->execute(['Error: finalize failed' . ($res['ok'] ? ' (supplier call succeeded)' : ''), $queueId]);
        } catch (Throwable $ignored) {}
        orders_tg(fn() => telegram_send_message("⚠️ خطأ داخلي أثناء إنهاء معالجة الطلب #{$orderId} عبر API" . ($res['ok'] ? " — تم تنفيذ طلب المورد بنجاح لكن لم يُسجَّل التسليم." : ".") . "\nيرجى التحقق ومعالجة الطلب يدوياً."));
        return 'failed';
    }

    $info = api_queue_order_info($orderId);
    $who = "👤 " . tg_esc($info['username'] ?? '-') . "\n🛒 " . tg_esc($info['item_name'] ?? '-') . "\n";

    if ($outcome === 'delivered') {
        if (!empty($info['telegram_message_id'])) {
            orders_tg(fn() => telegram_edit_message((int)$info['telegram_message_id'], "📦 طلب #{$orderId}\n\n✅ تم التسليم تلقائياً (API)"));
        }
    } elseif ($outcome === 'late') {
        $snippet = tg_esc(mb_substr((string)$res['body'], 0, 500));
        orders_tg(fn() => telegram_send_message(
            "⚠️ طلب #{$orderId}: تم تنفيذ طلب المورد (API) بنجاح، لكن الطلب لم يعد قيد الانتظار (الحالة: " . tg_esc($info['status'] ?? '-') . ").\n" .
            $who . "لم يتم تسجيل التسليم تلقائياً — يرجى التحقق ومعالجة الأمر يدوياً.\n\nاستجابة المورد:\n<code>{$snippet}</code>"
        ));
    } else { // failed
        $stillPending = ($info['status'] ?? '') === 'pending';
        $text = "❌ فشل التسليم التلقائي (API) للطلب #{$orderId}\n" . $who;
        $text .= "السبب: " . tg_esc($res['error']) . "\n";
        if ($res['body'] !== null && trim((string)$res['body']) !== '') {
            $text .= "استجابة المورد:\n<code>" . tg_esc(mb_substr((string)$res['body'], 0, 300)) . "</code>\n";
        }
        $text .= $stillPending
            ? "\nالطلب لا يزال قيد الانتظار — يرجى التحقق لدى المورد ثم قبوله وتسليمه يدوياً أو إلغاؤه."
            : "\nحالة الطلب الحالية: " . tg_esc($info['status'] ?? '-');
        $keyboard = $stillPending ? [
            'inline_keyboard' => [[
                ['text' => '✅ قبول', 'callback_data' => "order_accept:{$orderId}"],
                ['text' => '❌ إلغاء', 'callback_data' => "order_cancel:{$orderId}"],
            ]],
        ] : null;
        orders_tg(fn() => telegram_send_message($text, $keyboard));
    }
    return $outcome;
}

/**
 * Run one pass over the queue (no locking). Stops early once $deadline (unix ts) is reached.
 * Returns ['found' => n, 'processed' => n, 'failed' => n].
 */
function api_queue_run_batch(int $deadline = 0): array {
    $entries = api_queue_fetch_batch(DB::get());
    $stats = ['found' => count($entries), 'processed' => 0, 'failed' => 0];
    foreach ($entries as $entry) {
        if ($deadline && time() >= $deadline) break;
        try {
            $r = api_queue_process_entry($entry);
        } catch (Throwable $e) {
            error_log('[CStore] api queue entry #' . (int)$entry['id'] . ': ' . $e->getMessage());
            $r = 'failed';
        }
        if ($r === 'delivered') $stats['processed']++;
        elseif ($r === 'failed' || $r === 'late') $stats['failed']++;
    }
    return $stats;
}

/**
 * Process the API queue — one entry per template URL.
 * Called after check_status response is sent to client.
 * Uses flock to prevent concurrent processing.
 */
function process_api_queue(): void {
    $lockFp = @fopen(api_queue_lock_path(), 'w');
    if (!$lockFp) return;
    if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
        fclose($lockFp);
        return; // Another request is already processing
    }
    try {
        api_queue_run_batch();
    } catch (Throwable $e) {
        error_log('[CStore] process_api_queue: ' . $e->getMessage());
    } finally {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
    }
}
