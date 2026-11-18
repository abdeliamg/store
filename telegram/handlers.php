<?php
/**
 * CStore — Telegram Bot Handlers
 * Processes callback queries and messages (admin chat only — see webhook.php).
 *
 * Every money/status change runs inside DB::transaction() with a status-guarded
 * UPDATE and a rowCount() check, so double taps / Telegram retries can never
 * credit or refund twice.
 */

/** Thrown inside DB::transaction() to roll back when a status guard fails. */
class BotAlreadyProcessed extends RuntimeException {}

const BOT_MSG_ALREADY = '⚠️ تمت معالجة هذا الطلب مسبقاً';
const BOT_MSG_REPORT_ALREADY = '⚠️ البلاغ غير موجود أو تمت معالجته مسبقاً';

/** Conversational states — mutually exclusive: at most one exists at any time. */
const BOT_AWAITING_KEYS = [
    'awaiting_delivery',
    'awaiting_custom_amount',
    'awaiting_report_alt',
    'awaiting_report_reply',
    'awaiting_kyc_deny_reason',
];

// =====================================================================
// Bot state helpers (table bot_state: key TEXT PK, value TEXT, created_at)
// =====================================================================

function bot_awaiting_placeholders(): string {
    return implode(',', array_fill(0, count(BOT_AWAITING_KEYS), '?'));
}

/** The current conversational state, or null. */
function bot_get_awaiting(): ?array {
    $stmt = DB::get()->prepare("SELECT key, value FROM bot_state WHERE key IN (" . bot_awaiting_placeholders() . ") ORDER BY created_at DESC LIMIT 1");
    $stmt->execute(BOT_AWAITING_KEYS);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Value of one state key, or null. */
function bot_get_state(string $key): ?string {
    $stmt = DB::get()->prepare("SELECT value FROM bot_state WHERE key=?");
    $stmt->execute([$key]);
    $v = $stmt->fetchColumn();
    return $v === false ? null : (string)$v;
}

/** Remove every awaiting_* state. Returns the number of removed states. */
function bot_clear_awaiting(): int {
    $stmt = DB::get()->prepare("DELETE FROM bot_state WHERE key IN (" . bot_awaiting_placeholders() . ")");
    $stmt->execute(BOT_AWAITING_KEYS);
    return $stmt->rowCount();
}

/** Remove one state (optionally only when it points at $value). */
function bot_clear_state(string $key, ?string $value = null): void {
    if ($value === null) {
        DB::get()->prepare("DELETE FROM bot_state WHERE key=?")->execute([$key]);
    } else {
        DB::get()->prepare("DELETE FROM bot_state WHERE key=? AND value=?")->execute([$key, $value]);
    }
}

/** Refresh a state's timestamp so an active conversation is not expired. */
function bot_touch_state(string $key): void {
    DB::get()->prepare("UPDATE bot_state SET created_at=datetime('now') WHERE key=?")->execute([$key]);
}

/**
 * Set a conversational state, clearing every other awaiting_* state first.
 * Returns the previous state if it was a different one, else null.
 */
function bot_set_state(string $key, string $value): ?array {
    if (!in_array($key, BOT_AWAITING_KEYS, true)) {
        throw new InvalidArgumentException('Unknown bot state');
    }
    return DB::transaction(function (PDO $db) use ($key, $value) {
        $prev = bot_get_awaiting();
        bot_clear_awaiting();
        $db->prepare("INSERT OR REPLACE INTO bot_state (key, value, created_at) VALUES (?, ?, datetime('now'))")->execute([$key, $value]);
        if ($prev && !($prev['key'] === $key && (string)$prev['value'] === $value)) {
            return $prev;
        }
        return null;
    });
}

/** Human-readable (Arabic) description of a state. */
function bot_state_label(array $state): string {
    $id = (int)$state['value'];
    switch ($state['key']) {
        case 'awaiting_delivery':        return "تسليم الطلب #{$id}";
        case 'awaiting_custom_amount':   return "إدخال مبلغ مخصص لطلب الشحن #{$id}";
        case 'awaiting_report_alt':      return "إرسال بديل للبلاغ #{$id}";
        case 'awaiting_report_reply':    return "الرد على البلاغ #{$id}";
        case 'awaiting_kyc_deny_reason': return "إدخال سبب رفض طلب التحقق #{$id}";
    }
    return 'عملية غير معروفة';
}

/** Tell the admin about a state that was replaced/cancelled. */
function bot_notify_replaced_state(?array $prev, string $verb = 'تم إلغاء العملية السابقة'): void {
    if (!$prev) return;
    if (bot_warn_unfinished_delivery($prev)) return;
    telegram_send_message("ℹ️ {$verb}: " . tg_esc(bot_state_label($prev)));
}

/**
 * If $state was a delivery whose order is still 'delivering', warn the admin (with the
 * order id and how to resume). Returns true if a warning was sent.
 */
function bot_warn_unfinished_delivery(array $state): bool {
    if ($state['key'] !== 'awaiting_delivery') return false;
    $orderId = (int)$state['value'];
    $stmt = DB::get()->prepare("SELECT status FROM orders WHERE id=?");
    $stmt->execute([$orderId]);
    if ($stmt->fetchColumn() !== 'delivering') return false;
    telegram_send_message("⚠️ الطلب السابق #{$orderId} لا يزال في حالة «جارٍ التسليم» ولم يتم إنهاؤه بـ /done.\nلاستكمال تسليمه لاحقاً أرسل: <code>/deliver {$orderId}</code>");
    return true;
}

/** Set a state and notify the admin if another operation was replaced. */
function bot_begin_state(string $key, string $value): void {
    $prev = bot_set_state($key, $value);
    bot_notify_replaced_state($prev);
}

function bot_edit(int $messageId, string $text): void {
    if ($messageId > 0) {
        telegram_edit_message($messageId, $text);
    }
}

function bot_money(float $amount): string {
    return '$' . number_format($amount, 2);
}

// =====================================================================
// Callback queries (inline buttons)
// =====================================================================

function handle_callback_query(array $callback): void {
    $db = DB::get();
    $data = is_string($callback['data'] ?? null) ? $callback['data'] : '';
    $callbackId = (string)($callback['id'] ?? '');
    $messageId = (int)($callback['message']['message_id'] ?? 0);

    $parts = explode(':', $data, 2);
    $action = $parts[0] ?? '';
    $id = intval($parts[1] ?? 0);

    $pageActions = ['orders_page', 'topups_page', 'reports_page'];
    if (!in_array($action, $pageActions, true) && $id <= 0) {
        telegram_answer_callback($callbackId, '⚠️ طلب غير صالح');
        return;
    }

    switch ($action) {
        case 'order_accept':
            $res = DB::transaction(function (PDO $db) use ($id) {
                $q = $db->prepare("SELECT status FROM api_queue WHERE order_id=?");
                $q->execute([$id]);
                if ($q->fetchColumn() === 'processing') return 'processing';

                $u = $db->prepare("UPDATE orders SET status='delivering' WHERE id=? AND status='pending'");
                $u->execute([$id]);
                if ($u->rowCount() !== 1) return 'already';

                // Admin takes over: drop the queued API job (never one already being processed)
                $db->prepare("DELETE FROM api_queue WHERE order_id=? AND status != 'processing'")->execute([$id]);
                return 'ok';
            });
            if ($res === 'processing') {
                telegram_answer_callback($callbackId, '⏳ الطلب قيد المعالجة التلقائية (API) حالياً، حاول لاحقاً');
                return;
            }
            if ($res !== 'ok') {
                telegram_answer_callback($callbackId, BOT_MSG_ALREADY);
                return;
            }

            bot_begin_state('awaiting_delivery', (string)$id);

            bot_edit($messageId, "📦 طلب #{$id}\n\n✅ تم القبول — جارٍ التسليم");
            telegram_send_message("📬 أرسل محتوى التسليم للطلب #{$id} الآن.\n\nيمكنك إرسال: نص، صورة، مستند، فيديو، صوت.\n\nأرسل /done عند الانتهاء، أو /cancel لإيقاف الإدخال.");
            telegram_answer_callback($callbackId, '✅ تم قبول الطلب');
            break;

        case 'order_cancel':
            $order = DB::transaction(function (PDO $db) use ($id) {
                $s = $db->prepare("SELECT id, user_id, total_price, status FROM orders WHERE id=?");
                $s->execute([$id]);
                $order = $s->fetch();
                if (!$order || !in_array($order['status'], ['pending', 'delivering'], true)) return null;

                // Never refund while the supplier API call is in flight (customer could get both)
                $q = $db->prepare("SELECT status FROM api_queue WHERE order_id=?");
                $q->execute([$id]);
                if ($q->fetchColumn() === 'processing') return 'processing';

                $u = $db->prepare("UPDATE orders SET status='canceled_by_admin', resolved_at=datetime('now') WHERE id=? AND status IN ('pending','delivering')");
                $u->execute([$id]);
                if ($u->rowCount() !== 1) return null;

                $db->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id=?")
                    ->execute([money_round($order['total_price']), $order['user_id']]);
                // Remove queued API job (a job already 'processing' is left alone; the queue
                // worker will see the order is no longer pending and discard its result)
                $db->prepare("DELETE FROM api_queue WHERE order_id=? AND status != 'processing'")->execute([$id]);
                return $order;
            });
            if ($order === 'processing') {
                telegram_answer_callback($callbackId, '⏳ الطلب قيد المعالجة التلقائية (API) حالياً، لا يمكن إلغاؤه الآن');
                return;
            }
            if (!$order) {
                telegram_answer_callback($callbackId, BOT_MSG_ALREADY);
                return;
            }
            bot_clear_state('awaiting_delivery', (string)$id);

            bot_edit($messageId, "📦 طلب #{$id}\n\n❌ تم الإلغاء وإرجاع المبلغ (" . bot_money((float)$order['total_price']) . ")");
            telegram_answer_callback($callbackId, '❌ تم إلغاء الطلب');
            break;

        case 'topup_accept':
            $request = DB::transaction(function (PDO $db) use ($id) {
                $s = $db->prepare("SELECT id, user_id, amount FROM topup_requests WHERE id=? AND status='pending'");
                $s->execute([$id]);
                $request = $s->fetch();
                if (!$request) return null;
                $amount = money_round($request['amount']);
                if ($amount <= 0) return null;

                $u = $db->prepare("UPDATE topup_requests SET status='accepted', accepted_amount=?, resolved_at=datetime('now') WHERE id=? AND status='pending'");
                $u->execute([$amount, $id]);
                if ($u->rowCount() !== 1) return null;

                $db->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id=?")->execute([$amount, $request['user_id']]);
                $request['amount'] = $amount;
                return $request;
            });
            if (!$request) {
                telegram_answer_callback($callbackId, BOT_MSG_ALREADY);
                return;
            }
            bot_clear_state('awaiting_custom_amount', (string)$id);

            bot_edit($messageId, "💰 طلب شحن #{$id}\n\n✅ تم القبول — المبلغ: " . bot_money((float)$request['amount']));
            telegram_answer_callback($callbackId, '✅ تم قبول الشحن');
            break;

        case 'topup_custom':
            $stmt = $db->prepare("SELECT id, amount FROM topup_requests WHERE id=? AND status='pending'");
            $stmt->execute([$id]);
            $request = $stmt->fetch();
            if (!$request) {
                telegram_answer_callback($callbackId, BOT_MSG_ALREADY);
                return;
            }
            bot_begin_state('awaiting_custom_amount', (string)$id);
            telegram_send_message("💵 أدخل المبلغ المخصص لطلب الشحن #{$id} (المبلغ المطلوب: " . bot_money((float)$request['amount']) . "):\n\nأرسل /cancel للإلغاء.");
            telegram_answer_callback($callbackId, '✍️ أدخل المبلغ المخصص');
            break;

        case 'topup_deny':
            $ok = DB::transaction(function (PDO $db) use ($id) {
                $u = $db->prepare("UPDATE topup_requests SET status='denied', resolved_at=datetime('now') WHERE id=? AND status='pending'");
                $u->execute([$id]);
                return $u->rowCount() === 1;
            });
            if (!$ok) {
                telegram_answer_callback($callbackId, BOT_MSG_ALREADY);
                return;
            }
            bot_clear_state('awaiting_custom_amount', (string)$id);
            bot_edit($messageId, "💰 طلب شحن #{$id}\n\n❌ تم الرفض");
            telegram_answer_callback($callbackId, '❌ تم رفض الشحن');
            break;

        case 'orders_page':
            send_pending_orders_list($id);
            telegram_answer_callback($callbackId);
            break;

        case 'topups_page':
            send_pending_topups_list($id);
            telegram_answer_callback($callbackId);
            break;

        // === Report Callbacks ===
        case 'report_alt':
            $stmtR = $db->prepare("SELECT id, order_id FROM order_reports WHERE id=? AND status IN ('pending','alternative_sent')");
            $stmtR->execute([$id]);
            $report = $stmtR->fetch();
            if (!$report) {
                telegram_answer_callback($callbackId, BOT_MSG_REPORT_ALREADY);
                return;
            }
            bot_begin_state('awaiting_report_alt', (string)$id);
            $orderId = (int)$report['order_id'];
            telegram_send_message("📬 أرسل المحتوى البديل للبلاغ #{$id} (طلب #{$orderId})\n\nأرسل نص أو صورة أو مستند. ثم أرسل /done_report للإنهاء، أو /cancel للإلغاء.");
            telegram_answer_callback($callbackId, '✍️ أرسل المحتوى البديل');
            break;

        case 'report_deny':
            $ok = DB::transaction(function (PDO $db) use ($id) {
                $u = $db->prepare("UPDATE order_reports SET status='denied', resolved_at=datetime('now') WHERE id=? AND status IN ('pending','alternative_sent')");
                $u->execute([$id]);
                return $u->rowCount() === 1;
            });
            if (!$ok) {
                telegram_answer_callback($callbackId, BOT_MSG_REPORT_ALREADY);
                return;
            }
            bot_clear_state('awaiting_report_alt', (string)$id);
            bot_clear_state('awaiting_report_reply', (string)$id);
            bot_edit($messageId, "⚠️ بلاغ #{$id}\n\n❌ تم رفض البلاغ");
            telegram_answer_callback($callbackId, '❌ تم رفض البلاغ');
            break;

        case 'report_cancel':
            try {
                $report = DB::transaction(function (PDO $db) use ($id) {
                    $s = $db->prepare("SELECT order_reports.id, order_reports.order_id, orders.total_price, orders.user_id AS order_user_id
                        FROM order_reports JOIN orders ON order_reports.order_id=orders.id
                        WHERE order_reports.id=? AND order_reports.status IN ('pending','alternative_sent') AND orders.status='delivered'");
                    $s->execute([$id]);
                    $report = $s->fetch();
                    if (!$report) throw new BotAlreadyProcessed();

                    $u1 = $db->prepare("UPDATE order_reports SET status='canceled', resolved_at=datetime('now') WHERE id=? AND status IN ('pending','alternative_sent')");
                    $u1->execute([$id]);
                    if ($u1->rowCount() !== 1) throw new BotAlreadyProcessed();

                    $u2 = $db->prepare("UPDATE orders SET status='canceled_by_admin', resolved_at=datetime('now') WHERE id=? AND status='delivered'");
                    $u2->execute([$report['order_id']]);
                    if ($u2->rowCount() !== 1) throw new BotAlreadyProcessed();

                    $db->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id=?")
                        ->execute([money_round($report['total_price']), $report['order_user_id']]);
                    return $report;
                });
            } catch (BotAlreadyProcessed $e) {
                telegram_answer_callback($callbackId, BOT_MSG_REPORT_ALREADY);
                return;
            }
            bot_clear_state('awaiting_report_alt', (string)$id);
            bot_clear_state('awaiting_report_reply', (string)$id);
            bot_edit($messageId, "⚠️ بلاغ #{$id}\n\n💰 تم إلغاء الطلب واسترداد المبلغ (" . bot_money((float)$report['total_price']) . ")");
            telegram_answer_callback($callbackId, '💰 تم الإلغاء والاسترداد');
            break;

        case 'report_reply':
            $stmtR = $db->prepare("SELECT id, order_id FROM order_reports WHERE id=? AND status IN ('pending','alternative_sent')");
            $stmtR->execute([$id]);
            $report = $stmtR->fetch();
            if (!$report) {
                telegram_answer_callback($callbackId, BOT_MSG_REPORT_ALREADY);
                return;
            }
            bot_begin_state('awaiting_report_reply', (string)$id);
            $orderId = (int)$report['order_id'];
            telegram_send_message("✉️ اكتب ردك على بلاغ #{$id} (طلب #{$orderId})\n\nاكتب رسالة نصية وسيتم إرسالها للعميل، أو أرسل /cancel للإلغاء.");
            telegram_answer_callback($callbackId, '✉️ اكتب ردك');
            break;

        case 'reports_page':
            send_unresolved_reports_list($id);
            telegram_answer_callback($callbackId);
            break;

        case 'report_view':
            $stmtR = $db->prepare("SELECT order_reports.*, orders.total_price, users.username FROM order_reports LEFT JOIN orders ON order_reports.order_id=orders.id LEFT JOIN users ON order_reports.user_id=users.id WHERE order_reports.id=?");
            $stmtR->execute([$id]);
            $report = $stmtR->fetch();
            if (!$report || !in_array($report['status'], ['pending', 'alternative_sent'], true)) {
                telegram_answer_callback($callbackId, BOT_MSG_REPORT_ALREADY);
                return;
            }
            $rid = (int)$report['id'];
            $statusTxt = $report['status'] === 'alternative_sent' ? '📨 تم إرسال بديل' : '⏳ معلق';
            $details = (string)($report['details'] ?? '');
            $details = mb_strlen($details) > 500 ? mb_substr($details, 0, 500) . '...' : $details;
            $text = "⚠️ بلاغ #{$rid}\n";
            $text .= "📦 طلب #" . (int)$report['order_id'] . "\n";
            $text .= "👤 " . tg_esc($report['username'] ?? '') . "\n";
            $text .= "💰 " . bot_money((float)$report['total_price']) . "\n";
            $text .= "📄 " . tg_esc($details) . "\n";
            $text .= "📊 الحالة: {$statusTxt}\n";
            $keyboard = ['inline_keyboard' => [
                [
                    ['text' => '✉️ رد', 'callback_data' => "report_reply:{$rid}"],
                    ['text' => '🔄 إرسال بديل', 'callback_data' => "report_alt:{$rid}"],
                ],
                [
                    ['text' => '❌ رفض', 'callback_data' => "report_deny:{$rid}"],
                    ['text' => '💰 إلغاء واسترداد', 'callback_data' => "report_cancel:{$rid}"],
                ],
                [
                    ['text' => '◀ العودة للقائمة', 'callback_data' => 'reports_page:1'],
                ],
            ]];
            telegram_send_message($text, $keyboard);
            telegram_answer_callback($callbackId);
            break;

        // === KYC Callbacks ===
        case 'kyc_approve':
            $ok = DB::transaction(function (PDO $db) use ($id) {
                $u = $db->prepare("UPDATE kyc_applications SET status='approved', resolved_at=datetime('now') WHERE id=? AND status='pending'");
                $u->execute([$id]);
                return $u->rowCount() === 1;
            });
            if (!$ok) {
                telegram_answer_callback($callbackId, BOT_MSG_ALREADY);
                return;
            }
            bot_clear_state('awaiting_kyc_deny_reason', (string)$id);
            bot_edit($messageId, "🪪 طلب تحقق #{$id}\n\n✅ تم الموافقة");
            telegram_answer_callback($callbackId, '✅ تم الموافقة');
            break;

        case 'kyc_deny':
            $stmtK = $db->prepare("SELECT id FROM kyc_applications WHERE id=? AND status='pending'");
            $stmtK->execute([$id]);
            if (!$stmtK->fetch()) {
                telegram_answer_callback($callbackId, BOT_MSG_ALREADY);
                return;
            }
            bot_begin_state('awaiting_kyc_deny_reason', (string)$id);
            telegram_send_message("✍️ أدخل سبب رفض طلب التحقق #{$id}:\n\nأرسل /cancel للإلغاء.");
            telegram_answer_callback($callbackId, '✍️ أدخل سبب الرفض');
            break;

        default:
            telegram_answer_callback($callbackId, '⚠️ إجراء غير معروف');
    }
}

// =====================================================================
// Messages
// =====================================================================

function handle_message(array $message): void {
    $db = DB::get();
    // Clean up stale bot_state entries older than 1 hour (active ones are touched on use)
    $db->exec("DELETE FROM bot_state WHERE created_at < datetime('now', '-1 hour')");

    $rawText = is_string($message['text'] ?? null) ? $message['text'] : '';
    $text = trim($rawText);

    // Parse a "/command[@BotName] [arg]"
    $command = '';
    $commandArg = '';
    if ($text !== '' && $text[0] === '/') {
        $cmdParts = preg_split('/\s+/u', $text, 2);
        $command = strtolower((string)preg_replace('/@\w+$/', '', $cmdParts[0]));
        $commandArg = trim($cmdParts[1] ?? '');
    }

    // /start — Welcome message (also cancels any running operation)
    if ($command === '/start') {
        $prev = bot_get_awaiting();
        bot_clear_awaiting();

        $siteName = DB::setting('site_name', 'CStore');
        $pendingOrders = (int)$db->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetchColumn();
        $pendingTopups = (int)$db->query("SELECT COUNT(*) FROM topup_requests WHERE status='pending'")->fetchColumn();
        $totalUsers = (int)$db->query("SELECT COUNT(*) FROM users WHERE role='user'")->fetchColumn();

        $welcome  = "🎉 مرحباً بك في بوت إدارة <b>" . tg_esc($siteName) . "</b>!\n\n";
        $welcome .= "📊 <b>إحصائيات سريعة:</b>\n";
        $welcome .= "👥 عدد المستخدمين: {$totalUsers}\n";
        $welcome .= "📦 طلبات معلقة: {$pendingOrders}\n";
        $welcome .= "💰 طلبات شحن معلقة: {$pendingTopups}\n\n";
        $welcome .= "📌 <b>الأوامر المتاحة:</b>\n";
        $welcome .= "• 📋 <b>الطلبات المعلقة</b> — عرض الطلبات\n";
        $welcome .= "• 💰 <b>طلبات الشحن المعلقة</b> — عرض طلبات الشحن\n";
        $welcome .= "• /done — إنهاء تسليم الطلب الحالي\n";
        $welcome .= "• /done_report — إنهاء إرسال بديل للبلاغ\n";
        $welcome .= "• /deliver — استكمال تسليم طلب في حالة «جارٍ التسليم»\n";
        $welcome .= "• /cancel — إلغاء العملية الجارية\n\n";
        $welcome .= "💡 يمكنك استخدام الأزرار أسفل الشاشة للتنقل السريع.";

        telegram_send_message($welcome);
        bot_notify_replaced_state($prev, 'تم إلغاء العملية الجارية');
        return;
    }

    // /cancel — Cancel any running operation
    if ($command === '/cancel') {
        $prev = bot_get_awaiting();
        bot_clear_awaiting();
        if ($prev) {
            telegram_send_message('✅ تم إلغاء العملية الجارية: ' . tg_esc(bot_state_label($prev)));
            bot_warn_unfinished_delivery($prev);
        } else {
            telegram_send_message('ℹ️ لا توجد عملية جارية لإلغائها.');
        }
        return;
    }

    // /done_report — Finish sending report alternative
    if ($command === '/done_report') {
        $value = bot_get_state('awaiting_report_alt');
        if ($value === null) {
            telegram_send_message('ℹ️ لا يوجد بلاغ قيد إرسال بديل حالياً.');
            return;
        }
        $reportId = (int)$value;
        $ok = DB::transaction(function (PDO $db) use ($reportId) {
            $u = $db->prepare("UPDATE order_reports SET status='alternative_sent' WHERE id=? AND status IN ('pending','alternative_sent')");
            $u->execute([$reportId]);
            bot_clear_state('awaiting_report_alt');
            return $u->rowCount() === 1;
        });
        if ($ok) {
            telegram_send_message("✅ تم إرسال البديل للبلاغ #{$reportId} بنجاح!\nℹ️ سيتم الحل عند تأكيد العميل.");
        } else {
            telegram_send_message(BOT_MSG_REPORT_ALREADY);
        }
        return;
    }

    // /done — Finish delivery of the current order
    if ($command === '/done') {
        $value = bot_get_state('awaiting_delivery');
        if ($value === null) {
            telegram_send_message('ℹ️ لا يوجد طلب قيد التسليم حالياً.');
            return;
        }
        $orderId = (int)$value;

        $cnt = $db->prepare("SELECT COUNT(*) FROM order_deliveries WHERE order_id=?");
        $cnt->execute([$orderId]);
        if ((int)$cnt->fetchColumn() === 0) {
            telegram_send_message("⚠️ لم يتم حفظ أي محتوى للطلب #{$orderId} بعد.\nأرسل محتوى التسليم أولاً، أو /cancel للإلغاء.");
            return;
        }

        $order = DB::transaction(function (PDO $db) use ($orderId) {
            // Only an order that is being delivered can become delivered (never a canceled/refunded one)
            $u = $db->prepare("UPDATE orders SET status='delivered', delivered_at=datetime('now'), resolved_at=datetime('now') WHERE id=? AND status='delivering'");
            $u->execute([$orderId]);
            bot_clear_state('awaiting_delivery');
            if ($u->rowCount() !== 1) return null;
            $s = $db->prepare("SELECT id, telegram_message_id FROM orders WHERE id=?");
            $s->execute([$orderId]);
            return $s->fetch() ?: ['id' => $orderId, 'telegram_message_id' => null];
        });

        if (!$order) {
            telegram_send_message("⚠️ تعذر إنهاء الطلب #{$orderId}: تمت معالجته مسبقاً أو تم إلغاؤه.");
            return;
        }
        if (!empty($order['telegram_message_id'])) {
            telegram_edit_message((int)$order['telegram_message_id'], "📦 طلب #{$orderId}\n\n✅ تم التسليم");
        }
        telegram_send_message("✅ تم تسليم الطلب #{$orderId} بنجاح!");
        return;
    }

    // /deliver [id] — Resume delivery of an order that is still 'delivering'
    if ($command === '/deliver') {
        $orderId = ctype_digit($commandArg) ? (int)$commandArg : 0;
        if ($orderId <= 0) {
            $rows = $db->query("SELECT id FROM orders WHERE status='delivering' ORDER BY id ASC LIMIT 20")->fetchAll(PDO::FETCH_COLUMN);
            if (!$rows) {
                telegram_send_message('✅ لا توجد طلبات في حالة «جارٍ التسليم».');
            } else {
                $list = implode("\n", array_map(fn($oid) => "• <code>/deliver " . (int)$oid . "</code>", $rows));
                telegram_send_message("📦 طلبات في حالة «جارٍ التسليم»:\n\n{$list}");
            }
            return;
        }
        $s = $db->prepare("SELECT status FROM orders WHERE id=?");
        $s->execute([$orderId]);
        if ($s->fetchColumn() !== 'delivering') {
            telegram_send_message("⚠️ الطلب #{$orderId} ليس في حالة «جارٍ التسليم».");
            return;
        }
        bot_begin_state('awaiting_delivery', (string)$orderId);
        telegram_send_message("📬 أرسل محتوى التسليم للطلب #{$orderId} الآن.\n\nأرسل /done عند الانتهاء، أو /cancel لإيقاف الإدخال.");
        return;
    }

    // Persistent keyboard buttons
    if ($text === '📋 الطلبات المعلقة') {
        send_pending_orders_list(1);
        return;
    }
    if ($text === '💰 طلبات الشحن المعلقة') {
        send_pending_topups_list(1);
        return;
    }
    if ($text === '📝 التقارير المعلقة') {
        send_unresolved_reports_list(1);
        return;
    }

    // Conversational state (at most one is active — see bot_set_state)
    $state = bot_get_awaiting();
    if (!$state) {
        telegram_send_message('ℹ️ لا توجد عملية جارية. استخدم الأزرار أو أرسل /start لعرض الأوامر.');
        return;
    }
    $stateId = (int)$state['value'];

    switch ($state['key']) {
        case 'awaiting_custom_amount':
            bot_handle_custom_amount($text, $stateId);
            return;

        case 'awaiting_kyc_deny_reason':
            bot_handle_kyc_deny_reason($text, $stateId);
            return;

        case 'awaiting_report_reply':
            bot_handle_report_reply($rawText, $stateId);
            return;

        case 'awaiting_report_alt':
            $stmtR = $db->prepare("SELECT order_id FROM order_reports WHERE id=? AND status IN ('pending','alternative_sent')");
            $stmtR->execute([$stateId]);
            $orderId = $stmtR->fetchColumn();
            if ($orderId === false) {
                bot_clear_state('awaiting_report_alt');
                telegram_send_message(BOT_MSG_REPORT_ALREADY);
                return;
            }
            bot_touch_state('awaiting_report_alt');
            save_delivery_content($message, (int)$orderId, '/done_report');
            return;

        case 'awaiting_delivery':
            $s = $db->prepare("SELECT status FROM orders WHERE id=?");
            $s->execute([$stateId]);
            if ($s->fetchColumn() !== 'delivering') {
                bot_clear_state('awaiting_delivery');
                telegram_send_message("⚠️ الطلب #{$stateId} لم يعد في حالة «جارٍ التسليم» (تمت معالجته أو إلغاؤه). لم يتم حفظ المحتوى.");
                return;
            }
            bot_touch_state('awaiting_delivery');
            save_delivery_content($message, $stateId, '/done');
            return;
    }
}

/** Custom top-up amount entered by the admin. */
function bot_handle_custom_amount(string $text, int $topupId): void {
    $amount = parse_money($text);
    if ($amount === null) {
        telegram_send_message("⚠️ يرجى إدخال مبلغ صحيح (أكبر من صفر وبحد أقصى 1,000,000)، أو أرسل /cancel للإلغاء.");
        return;
    }

    $request = DB::transaction(function (PDO $db) use ($topupId, $amount) {
        bot_clear_state('awaiting_custom_amount');
        $s = $db->prepare("SELECT id, user_id, telegram_message_id FROM topup_requests WHERE id=? AND status='pending'");
        $s->execute([$topupId]);
        $request = $s->fetch();
        if (!$request) return null;

        $u = $db->prepare("UPDATE topup_requests SET status='accepted', accepted_amount=?, resolved_at=datetime('now') WHERE id=? AND status='pending'");
        $u->execute([$amount, $topupId]);
        if ($u->rowCount() !== 1) return null;

        $db->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id=?")->execute([$amount, $request['user_id']]);
        return $request;
    });

    if (!$request) {
        telegram_send_message(BOT_MSG_ALREADY);
        return;
    }
    if (!empty($request['telegram_message_id'])) {
        telegram_edit_message((int)$request['telegram_message_id'], "💰 طلب شحن #{$topupId}\n\n✅ تم القبول بمبلغ مخصص: " . bot_money($amount));
    }
    telegram_send_message("✅ تم قبول طلب الشحن #{$topupId} بمبلغ " . bot_money($amount));
}

/** KYC deny reason entered by the admin. */
function bot_handle_kyc_deny_reason(string $text, int $kycId): void {
    if ($text === '') {
        telegram_send_message("⚠️ يرجى إدخال سبب الرفض نصاً، أو أرسل /cancel للإلغاء.");
        return;
    }
    $reason = mb_substr($text, 0, 500);

    $kyc = DB::transaction(function (PDO $db) use ($kycId, $reason) {
        bot_clear_state('awaiting_kyc_deny_reason');
        $s = $db->prepare("SELECT id, telegram_message_id FROM kyc_applications WHERE id=? AND status='pending'");
        $s->execute([$kycId]);
        $kyc = $s->fetch();
        if (!$kyc) return null;

        $u = $db->prepare("UPDATE kyc_applications SET status='denied', deny_reason=?, resolved_at=datetime('now') WHERE id=? AND status='pending'");
        $u->execute([$reason, $kycId]);
        if ($u->rowCount() !== 1) return null;
        return $kyc;
    });

    if (!$kyc) {
        telegram_send_message(BOT_MSG_ALREADY);
        return;
    }
    $reasonEsc = tg_esc($reason);
    if (!empty($kyc['telegram_message_id'])) {
        telegram_edit_message((int)$kyc['telegram_message_id'], "🪪 طلب تحقق #{$kycId}\n\n❌ تم الرفض\nالسبب: {$reasonEsc}");
    }
    telegram_send_message("❌ تم رفض طلب التحقق #{$kycId}\nالسبب: {$reasonEsc}");
}

/** Admin reply to a report (stored as a report message for the customer). */
function bot_handle_report_reply(string $rawText, int $reportId): void {
    $replyText = trim($rawText);
    if ($replyText === '') {
        telegram_send_message("⚠️ يرجى إرسال رسالة نصية للرد، أو أرسل /cancel للإلغاء.");
        return;
    }
    $replyText = mb_substr($replyText, 0, 4000);

    $ok = DB::transaction(function (PDO $db) use ($reportId, $replyText) {
        bot_clear_state('awaiting_report_reply');
        $s = $db->prepare("SELECT id FROM order_reports WHERE id=? AND status IN ('pending','alternative_sent')");
        $s->execute([$reportId]);
        if (!$s->fetch()) return false;
        $db->prepare("INSERT INTO report_messages (report_id, sender, message) VALUES (?, 'admin', ?)")->execute([$reportId, $replyText]);
        return true;
    });

    if ($ok) {
        telegram_send_message("✅ تم إرسال ردك على البلاغ #{$reportId} بنجاح!");
    } else {
        telegram_send_message(BOT_MSG_REPORT_ALREADY);
    }
}

/** Arabic error message for a failed Telegram file download. */
function bot_download_error_message(?string $error): string {
    switch ($error) {
        case 'type': return '⚠️ نوع الملف غير مسموح. الأنواع المسموحة: صور (JPG/PNG/GIF/WEBP)، PDF، TXT، ZIP، MP4، MP3، OGG، M4A.';
        case 'size': return '⚠️ حجم الملف كبير جداً (الحد الأقصى 20MB).';
    }
    return '⚠️ تعذر تنزيل الملف من تيليجرام، يرجى المحاولة مرة أخرى.';
}

/**
 * Save one piece of delivery content (text or media) for $orderId.
 * $doneCmd is the command that finishes the current flow (/done or /done_report).
 */
function save_delivery_content(array $message, int $orderId, string $doneCmd = '/done'): bool {
    $db = DB::get();
    $more = "أرسل المزيد أو {$doneCmd} للإنهاء.";

    // Text message
    if (isset($message['text']) && is_string($message['text']) && trim($message['text']) !== '') {
        $db->prepare("INSERT INTO order_deliveries (order_id, type, content) VALUES (?, 'text', ?)")->execute([$orderId, $message['text']]);
        telegram_send_message("✅ تم حفظ النص. {$more}");
        return true;
    }

    // Media: [message key, delivery type, saved label]
    $media = null;
    if (!empty($message['photo']) && is_array($message['photo'])) {
        $photo = end($message['photo']); // Highest resolution
        $media = ['file' => $photo, 'type' => 'image', 'label' => 'الصورة'];
    } elseif (!empty($message['document']) && is_array($message['document'])) {
        $media = ['file' => $message['document'], 'type' => 'document', 'label' => 'المستند'];
    } elseif (!empty($message['video']) && is_array($message['video'])) {
        $media = ['file' => $message['video'], 'type' => 'video', 'label' => 'الفيديو'];
    } elseif (!empty($message['audio']) && is_array($message['audio'])) {
        $media = ['file' => $message['audio'], 'type' => 'audio', 'label' => 'الصوت'];
    } elseif (!empty($message['voice']) && is_array($message['voice'])) {
        $media = ['file' => $message['voice'], 'type' => 'audio', 'label' => 'الصوت'];
    }

    if (!$media) {
        telegram_send_message("⚠️ نوع الرسالة غير مدعوم. أرسل نصاً أو صورة أو مستنداً أو فيديو أو صوتاً، أو {$doneCmd} للإنهاء.");
        return false;
    }

    $fileId = is_array($media['file']) && is_string($media['file']['file_id'] ?? null) ? $media['file']['file_id'] : '';
    if ($fileId === '') {
        telegram_send_message(bot_download_error_message('download'));
        return false;
    }
    if ((int)($media['file']['file_size'] ?? 0) > TELEGRAM_DOWNLOAD_MAX_BYTES) {
        telegram_send_message(bot_download_error_message('size'));
        return false;
    }

    $error = null;
    $filePath = telegram_download_file($fileId, UPLOAD_DELIVERIES, $error);
    if ($filePath === null) {
        telegram_send_message(bot_download_error_message($error));
        return false;
    }

    if ($media['type'] === 'document') {
        $fileName = is_string($media['file']['file_name'] ?? null) ? $media['file']['file_name'] : 'document';
        $fileName = preg_replace('/[\x00-\x1F\x7F\/\\\\]/u', '_', $fileName);
        $fileName = mb_substr(trim((string)$fileName), 0, 200) ?: 'document';
        $db->prepare("INSERT INTO order_deliveries (order_id, type, content, file_name, telegram_file_id) VALUES (?, 'document', ?, ?, ?)")->execute([$orderId, $filePath, $fileName, $fileId]);
    } else {
        $db->prepare("INSERT INTO order_deliveries (order_id, type, content, telegram_file_id) VALUES (?, ?, ?, ?)")->execute([$orderId, $media['type'], $filePath, $fileId]);
    }
    telegram_send_message("✅ تم حفظ {$media['label']}. {$more}");
    return true;
}

// =====================================================================
// Lists
// =====================================================================

function send_pending_orders_list(int $page): void {
    $db = DB::get();
    $perPage = 10;

    $total = (int)$db->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetchColumn();
    if ($total === 0) {
        telegram_send_message("✅ لا توجد طلبات معلقة");
        return;
    }

    $totalPages = (int)ceil($total / $perPage);
    $page = max(1, min($page, $totalPages));
    $offset = ($page - 1) * $perPage;

    $stmt = $db->prepare("SELECT orders.id, orders.total_price, orders.created_at, users.username, items.name as item_name FROM orders LEFT JOIN users ON orders.user_id=users.id LEFT JOIN items ON orders.item_id=items.id WHERE orders.status='pending' ORDER BY orders.created_at ASC LIMIT ? OFFSET ?");
    $stmt->execute([$perPage, $offset]);
    $orders = $stmt->fetchAll();

    $text = "📋 الطلبات المعلقة (صفحة {$page}/{$totalPages})\n\n";
    $keyboard = [];

    foreach ($orders as $i => $o) {
        $num = $offset + $i + 1;
        $oid = (int)$o['id'];
        $ago = time_ago_ar($o['created_at']);
        $text .= "{$num}. #{$oid} — " . tg_esc($o['item_name'] ?? '') . "\n   👤 " . tg_esc($o['username'] ?? '') . " · 💰 " . bot_money((float)$o['total_price']) . " · 📅 {$ago}\n\n";
        $keyboard[] = [
            ['text' => "✅ #{$oid}", 'callback_data' => "order_accept:{$oid}"],
            ['text' => "❌ #{$oid}", 'callback_data' => "order_cancel:{$oid}"],
        ];
    }

    // Pagination buttons
    $navRow = [];
    if ($page > 1) $navRow[] = ['text' => '◀ السابق', 'callback_data' => "orders_page:" . ($page - 1)];
    if ($page < $totalPages) $navRow[] = ['text' => 'التالي ▶', 'callback_data' => "orders_page:" . ($page + 1)];
    if ($navRow) $keyboard[] = $navRow;

    telegram_send_message($text, ['inline_keyboard' => $keyboard]);
}

function send_pending_topups_list(int $page): void {
    $db = DB::get();
    $perPage = 10;

    $total = (int)$db->query("SELECT COUNT(*) FROM topup_requests WHERE status='pending'")->fetchColumn();
    if ($total === 0) {
        telegram_send_message("✅ لا توجد طلبات شحن معلقة");
        return;
    }

    $totalPages = (int)ceil($total / $perPage);
    $page = max(1, min($page, $totalPages));
    $offset = ($page - 1) * $perPage;

    $stmt = $db->prepare("SELECT topup_requests.id, topup_requests.amount, topup_requests.created_at, users.username, topup_methods.name as method_name FROM topup_requests LEFT JOIN users ON topup_requests.user_id=users.id LEFT JOIN topup_methods ON topup_requests.method_id=topup_methods.id WHERE topup_requests.status='pending' ORDER BY topup_requests.created_at ASC LIMIT ? OFFSET ?");
    $stmt->execute([$perPage, $offset]);
    $requests = $stmt->fetchAll();

    $text = "💰 طلبات الشحن المعلقة (صفحة {$page}/{$totalPages})\n\n";
    $keyboard = [];

    foreach ($requests as $i => $r) {
        $num = $offset + $i + 1;
        $rid = (int)$r['id'];
        $ago = time_ago_ar($r['created_at']);
        $text .= "{$num}. #{$rid} — " . tg_esc($r['method_name'] ?? '') . "\n   👤 " . tg_esc($r['username'] ?? '') . " · 💵 " . bot_money((float)$r['amount']) . " · 📅 {$ago}\n\n";
        $keyboard[] = [
            ['text' => "✅ #{$rid}", 'callback_data' => "topup_accept:{$rid}"],
            ['text' => "✅💵 #{$rid}", 'callback_data' => "topup_custom:{$rid}"],
            ['text' => "❌ #{$rid}", 'callback_data' => "topup_deny:{$rid}"],
        ];
    }

    $navRow = [];
    if ($page > 1) $navRow[] = ['text' => '◀ السابق', 'callback_data' => "topups_page:" . ($page - 1)];
    if ($page < $totalPages) $navRow[] = ['text' => 'التالي ▶', 'callback_data' => "topups_page:" . ($page + 1)];
    if ($navRow) $keyboard[] = $navRow;

    telegram_send_message($text, ['inline_keyboard' => $keyboard]);
}

function send_unresolved_reports_list(int $page): void {
    $db = DB::get();
    $perPage = 10;

    $total = (int)$db->query("SELECT COUNT(*) FROM order_reports WHERE status IN ('pending','alternative_sent')")->fetchColumn();
    if ($total === 0) {
        telegram_send_message("✅ لا توجد بلاغات معلقة");
        return;
    }

    $totalPages = (int)ceil($total / $perPage);
    $page = max(1, min($page, $totalPages));
    $offset = ($page - 1) * $perPage;

    $stmt = $db->prepare("SELECT order_reports.id, order_reports.order_id, order_reports.status, order_reports.created_at, users.username FROM order_reports LEFT JOIN users ON order_reports.user_id=users.id WHERE order_reports.status IN ('pending','alternative_sent') ORDER BY order_reports.created_at ASC LIMIT ? OFFSET ?");
    $stmt->execute([$perPage, $offset]);
    $reports = $stmt->fetchAll();

    $text = "📝 البلاغات المعلقة ({$page}/{$totalPages})\n\n";
    $keyboard = [];

    foreach ($reports as $i => $r) {
        $num = $offset + $i + 1;
        $rid = (int)$r['id'];
        $statusIcon = $r['status'] === 'alternative_sent' ? '📨' : '⏳';
        $ago = time_ago_ar($r['created_at']);
        $text .= "{$num}. {$statusIcon} بلاغ #{$rid} — طلب #" . (int)$r['order_id'] . "\n   👤 " . tg_esc($r['username'] ?? '') . " · 📅 {$ago}\n\n";
        $keyboard[] = [
            ['text' => "{$statusIcon} بلاغ #{$rid}", 'callback_data' => "report_view:{$rid}"],
        ];
    }

    $navRow = [];
    if ($page > 1) $navRow[] = ['text' => '◀ السابق', 'callback_data' => 'reports_page:' . ($page - 1)];
    if ($page < $totalPages) $navRow[] = ['text' => 'التالي ▶', 'callback_data' => 'reports_page:' . ($page + 1)];
    if ($navRow) $keyboard[] = $navRow;

    telegram_send_message($text, ['inline_keyboard' => $keyboard]);
}
