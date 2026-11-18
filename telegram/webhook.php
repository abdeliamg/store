<?php
/**
 * CStore — Telegram Webhook Handler
 * Receives updates from Telegram and routes them to handlers.
 *
 * Authentication: Telegram sends the secret registered via setWebhook (see
 * api/settings.php?action=set_webhook) in the X-Telegram-Bot-Api-Secret-Token
 * header. Requests without the correct secret are rejected before the body is read.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/bot.php';
require_once __DIR__ . '/handlers.php';

// Only POST is accepted
if (request_method() !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

// Verify the webhook secret (constant-time comparison)
$webhookSecret = DB::setting('telegram_webhook_secret');
$givenSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if ($webhookSecret === '' || !is_string($givenSecret) || !hash_equals($webhookSecret, $givenSecret)) {
    http_response_code(403);
    exit;
}

// Admin chat must be configured
$adminChatId = trim(telegram_get_chat_id());
if ($adminChatId === '') {
    http_response_code(200);
    exit;
}

// Read the incoming update (Telegram updates are small; cap at 1MB)
$input = file_get_contents('php://input', false, null, 0, 1024 * 1024);
$update = is_string($input) ? json_decode($input, true) : null;

if (!is_array($update)) {
    http_response_code(200);
    exit;
}

/**
 * Is this update coming from the admin? The chat must be the admin chat and, when the
 * admin chat is a private chat (positive id), the sender must be the admin as well.
 */
function webhook_is_admin(?array $chat, ?array $from, string $adminChatId): bool {
    if (!is_array($chat) || !isset($chat['id']) || !is_scalar($chat['id'])) return false;
    if ((string)$chat['id'] !== $adminChatId) return false;
    if (ctype_digit($adminChatId)) {
        // Private chat: the sender must be the admin user
        if (!is_array($from) || !isset($from['id']) || !is_scalar($from['id'])) return false;
        if ((string)$from['id'] !== $adminChatId) return false;
    }
    return true;
}

try {
    // Handle callback queries (inline button taps)
    if (isset($update['callback_query']) && is_array($update['callback_query'])) {
        $callback = $update['callback_query'];
        $chat = $callback['message']['chat'] ?? null;
        $from = $callback['from'] ?? null;
        if (isset($callback['id']) && webhook_is_admin(is_array($chat) ? $chat : null, is_array($from) ? $from : null, $adminChatId)) {
            try {
                handle_callback_query($callback);
            } catch (Throwable $e) {
                error_log('[CStore] Telegram callback error: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
                telegram_answer_callback((string)$callback['id'], '⚠️ حدث خطأ، يرجى المحاولة مرة أخرى');
            }
        }
        http_response_code(200);
        exit;
    }

    // Handle new text/media messages (edited messages and other update types are ignored)
    if (isset($update['message']) && is_array($update['message'])) {
        $message = $update['message'];
        $chat = $message['chat'] ?? null;
        $from = $message['from'] ?? null;
        if (webhook_is_admin(is_array($chat) ? $chat : null, is_array($from) ? $from : null, $adminChatId)) {
            try {
                handle_message($message);
            } catch (Throwable $e) {
                error_log('[CStore] Telegram message error: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
                telegram_send_message('⚠️ حدث خطأ أثناء معالجة رسالتك، يرجى المحاولة مرة أخرى.');
            }
        }
        http_response_code(200);
        exit;
    }
} catch (Throwable $e) {
    error_log('[CStore] Telegram webhook error: ' . get_class($e) . ': ' . $e->getMessage());
}

http_response_code(200);
