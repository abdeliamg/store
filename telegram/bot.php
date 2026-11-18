<?php
/**
 * CStore — Telegram Bot API Wrapper
 */

function telegram_api(string $method, array $params = [], $file = null): ?array {
    $token = DB::setting('telegram_bot_token');
    if (!$token) return null;

    $url = "https://api.telegram.org/bot{$token}/{$method}";

    $ch = curl_init();
    
    if ($file && file_exists($file)) {
        $params['photo'] = new CURLFile($file);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    } else {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    if (!$response) return null;
    return json_decode($response, true);
}

function telegram_get_chat_id(): string {
    return DB::setting('telegram_admin_chat_id', '');
}

function telegram_send_message(string $text, ?array $replyMarkup = null, bool $silent = false): ?int {
    $chatId = telegram_get_chat_id();
    if (!$chatId) return null;

    $params = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
    ];

    if ($silent) {
        $params['disable_notification'] = true;
    }

    if ($replyMarkup) {
        $params['reply_markup'] = json_encode($replyMarkup);
    } else {
        $params['reply_markup'] = json_encode([
            'keyboard' => [
                [['text' => '📋 الطلبات المعلقة'], ['text' => '💰 طلبات الشحن المعلقة']],
                [['text' => '📝 التقارير المعلقة']],
            ],
            'resize_keyboard' => true,
            'is_persistent' => true,
        ]);
    }

    $result = telegram_api('sendMessage', $params);
    return $result['result']['message_id'] ?? null;
}

function telegram_edit_message(int $messageId, string $text, ?array $replyMarkup = null): void {
    $chatId = telegram_get_chat_id();
    if (!$chatId) return;

    $params = [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
        'parse_mode' => 'HTML',
    ];
    if ($replyMarkup) {
        $params['reply_markup'] = json_encode($replyMarkup);
    }

    telegram_api('editMessageText', $params);
}

function telegram_answer_callback(string $callbackId, string $text = ''): void {
    telegram_api('answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text' => $text,
    ]);
}

function telegram_send_photo(string $photoPath, string $caption = ''): ?int {
    $chatId = telegram_get_chat_id();
    if (!$chatId) return null;

    // Caption uses parse_mode=HTML like every other message: callers must tg_esc() user data.
    $result = telegram_api('sendPhoto', [
        'chat_id' => $chatId,
        'caption' => mb_substr($caption, 0, 1024),
        'parse_mode' => 'HTML',
    ], $photoPath);

    return $result['result']['message_id'] ?? null;
}

/** Max size of a file downloaded from Telegram (Bot API getFile limit is 20MB too). */
const TELEGRAM_DOWNLOAD_MAX_BYTES = 20 * 1024 * 1024;

/**
 * Resolve a Telegram file id to its download URL.
 * NOTE: the returned URL contains the bot token — never log it or show it to anyone.
 * $fileSize receives the size reported by Telegram (0 if unknown).
 */
function telegram_get_file_url(string $fileId, ?int &$fileSize = null): ?string {
    $fileSize = 0;
    $result = telegram_api('getFile', ['file_id' => $fileId]);
    if (!$result || empty($result['ok']) || !isset($result['result']['file_path'])) return null;
    $filePath = (string)$result['result']['file_path'];
    if ($filePath === '' || strpos($filePath, '..') !== false) return null;
    $fileSize = (int)($result['result']['file_size'] ?? 0);
    $token = DB::setting('telegram_bot_token');
    if ($token === '') return null;
    $encodedPath = implode('/', array_map('rawurlencode', explode('/', $filePath)));
    return "https://api.telegram.org/file/bot{$token}/{$encodedPath}";
}

/**
 * Download a Telegram file and store it safely in $targetDir.
 * The file type is detected from the content (finfo) — the extension from Telegram's
 * file_path is never trusted. Images are re-encoded; other types must be in
 * UPLOAD_DELIVERY_MIMES. Returns the "uploads/..." relative path, or null.
 * On failure $error is set to 'download' | 'size' | 'type'.
 */
function telegram_download_file(string $fileId, string $targetDir, ?string &$error = null): ?string {
    $error = null;
    $fileSize = 0;
    $fileUrl = telegram_get_file_url($fileId, $fileSize);
    if (!$fileUrl) {
        $error = 'download';
        return null;
    }
    if ($fileSize > TELEGRAM_DOWNLOAD_MAX_BYTES) {
        $error = 'size';
        return null;
    }

    $ctx = stream_context_create([
        'http' => [
            'method'          => 'GET',
            'timeout'         => 30,
            'follow_location' => 0,
            'ignore_errors'   => false,
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);
    // '@' keeps PHP warnings (which would contain the token-bearing URL) out of the logs.
    $content = @file_get_contents($fileUrl, false, $ctx, 0, TELEGRAM_DOWNLOAD_MAX_BYTES + 1);
    unset($fileUrl);
    if ($content === false || $content === '') {
        error_log('[CStore] Telegram file download failed (file_id=' . substr($fileId, 0, 64) . ')');
        $error = 'download';
        return null;
    }
    if (strlen($content) > TELEGRAM_DOWNLOAD_MAX_BYTES) {
        $error = 'size';
        return null;
    }

    $rel = store_file_bytes($content, $targetDir, UPLOAD_DELIVERY_MIMES, TELEGRAM_DOWNLOAD_MAX_BYTES);
    if ($rel === null) {
        $error = 'type';
        return null;
    }
    return $rel;
}

/**
 * Register the webhook with Telegram. $secret is sent back by Telegram in the
 * X-Telegram-Bot-Api-Secret-Token header of every update (verified in webhook.php).
 */
function telegram_set_webhook(string $url, string $secret): ?array {
    return telegram_api('setWebhook', [
        'url'                  => $url,
        'secret_token'         => $secret,
        'allowed_updates'      => ['message', 'callback_query'],
        'drop_pending_updates' => true,
    ]);
}
