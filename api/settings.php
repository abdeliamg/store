<?php
require_once __DIR__ . '/../config.php';
$action = $_GET['action'] ?? '';
$db = DB::get();
release_session();

/** Settings that must never be returned to any client. */
const SETTINGS_HIDDEN_KEYS = ['telegram_webhook_secret'];

/** Read a trimmed string from $_POST (null if absent or not a string), truncated to $max chars. */
function settings_post_str(string $key, int $max): ?string {
    if (!array_key_exists($key, $_POST)) return null;
    $v = $_POST[$key];
    if (!is_string($v)) json_error('قيمة غير صالحة');
    $v = trim($v);
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $v)) json_error('قيمة غير صالحة');
    return mb_substr($v, 0, $max);
}

switch ($action) {
    case 'contact':
        json_success([
            'phone' => DB::setting('contact_phone'),
            'email' => DB::setting('contact_email'),
            'whatsapp' => DB::setting('contact_whatsapp'),
        ]);
        break;

    case 'get_all':
        require_admin();
        $stmt = $db->query("SELECT * FROM site_settings");
        $settings = [];
        while ($row = $stmt->fetch()) {
            if (in_array($row['key'], SETTINGS_HIDDEN_KEYS, true)) continue;
            $settings[$row['key']] = $row['value'];
        }
        json_success([
            'settings' => $settings,
            // The webhook rejects every update until a secret exists ("Set webhook" must be pressed once)
            'webhook_secret_missing' => DB::setting('telegram_webhook_secret') === '' && DB::setting('telegram_bot_token') !== '',
        ]);
        break;

    case 'update':
        require_method('POST');
        require_admin();
        require_csrf();

        // Whitelist (telegram_webhook_secret is intentionally NOT writable) + max lengths
        $allowedKeys = [
            'site_name'              => 100,
            'theme_color'            => 7,
            'contact_phone'          => 50,
            'contact_email'          => 254,
            'contact_whatsapp'       => 50,
            'telegram_bot_token'     => 200,
            'telegram_admin_chat_id' => 32,
            'report_window_seconds'  => 10,
            'queue_process_token'    => 200,
        ];

        $updates = [];
        foreach ($allowedKeys as $key => $maxLen) {
            $val = settings_post_str($key, $maxLen + 1);
            if ($val === null) continue;
            if (mb_strlen($val) > $maxLen) json_error('القيمة طويلة جداً');

            switch ($key) {
                case 'site_name':
                    if ($val === '') json_error('اسم الموقع مطلوب');
                    break;
                case 'theme_color':
                    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $val)) json_error('لون السمة غير صالح');
                    break;
                case 'contact_email':
                    if ($val !== '' && filter_var($val, FILTER_VALIDATE_EMAIL) === false) json_error('البريد الإلكتروني غير صالح');
                    break;
                case 'telegram_bot_token':
                    if ($val !== '' && !preg_match('/^\d{3,20}:[A-Za-z0-9_-]{20,100}$/', $val)) json_error('توكن البوت غير صالح');
                    break;
                case 'telegram_admin_chat_id':
                    if ($val !== '' && !preg_match('/^-?\d{1,20}$/', $val)) json_error('معرّف محادثة المشرف غير صالح (يجب أن يكون رقماً)');
                    break;
                case 'report_window_seconds':
                    $n = filter_var($val, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 86400]]);
                    if ($n === false) json_error('مدة البلاغ يجب أن تكون رقماً صحيحاً بين 0 و 86400 ثانية');
                    $val = (string)$n;
                    break;
            }
            $updates[$key] = $val;
        }

        try {
            // Handle logo upload (validates and exits with an error on bad files)
            $logo = handle_upload('company_logo', UPLOAD_LOGO);
            $oldLogo = DB::setting('company_logo');

            DB::transaction(function () use ($updates, $logo) {
                foreach ($updates as $key => $val) {
                    DB::setSetting($key, $val);
                }
                if ($logo) {
                    DB::setSetting('company_logo', $logo);
                }
            });

            if ($logo && $oldLogo && $oldLogo !== $logo) {
                delete_upload($oldLogo);
            }
        } catch (Throwable $e) {
            server_error($e);
        }
        json_success([], 'تم تحديث الإعدادات');
        break;

    case 'set_webhook':
        require_method('POST');
        require_admin();
        require_csrf();
        require_once ROOT_DIR . '/telegram/bot.php';
        $data = json_body();
        $url = is_string($data['webhook_url'] ?? null) ? trim($data['webhook_url']) : '';
        if ($url === '') json_error('رابط الويب هوك مطلوب');
        if (strlen($url) > 2048
            || stripos($url, 'https://') !== 0
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || preg_match('/[\x00-\x20\x7F]/', $url)) {
            json_error('رابط الويب هوك يجب أن يكون رابطاً صالحاً يبدأ بـ https://');
        }
        if (DB::setting('telegram_bot_token') === '') json_error('يرجى حفظ توكن البوت أولاً');

        try {
            $secret = bin2hex(random_bytes(32));
            $result = telegram_set_webhook($url, $secret);
            if ($result && ($result['ok'] ?? false)) {
                // Persist the secret only once Telegram has accepted it
                DB::setSetting('telegram_webhook_secret', $secret);
                json_success([], 'تم تعيين الويب هوك بنجاح');
            }
            error_log('[CStore] setWebhook failed: ' . mb_substr((string)($result['description'] ?? 'no response'), 0, 300));
        } catch (Throwable $e) {
            server_error($e);
        }
        json_error('فشل في تعيين الويب هوك، تحقق من التوكن والرابط');
        break;

    default:
        json_error('إجراء غير معروف', 404);
}
