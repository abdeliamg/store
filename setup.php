<?php
/**
 * CStore — Setup Wizard (first visit)
 */
require_once __DIR__ . '/config.php';

// If admin already exists, deny access
if (!needs_setup()) {
    http_response_code(404);
    exit;
}

/** Escape a value for HTML output. */
function setup_e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Read a string POST field (non-strings become ''). */
function setup_post(string $key, string $default = ''): string {
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? $v : $default;
}

const SETUP_DEFAULT_COLOR = '#4F46E5';

$errors = [];
$themeColor = SETUP_DEFAULT_COLOR;

// Handle setup form submission
if (request_method() === 'POST') {
    $username = trim(setup_post('username'));
    $email = trim(setup_post('email'));
    $password = setup_post('password');
    $siteName = trim(setup_post('site_name', 'CStore'));
    $themeColor = trim(setup_post('theme_color', SETUP_DEFAULT_COLOR));
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $themeColor)) $themeColor = SETUP_DEFAULT_COLOR;
    $botToken = trim(setup_post('telegram_bot_token'));
    $chatId = trim(setup_post('telegram_admin_chat_id'));

    if (rate_limit_hit('setup_ip:' . client_ip(), 3600) > 10) {
        http_response_code(429);
        $errors[] = 'محاولات كثيرة جداً، يرجى المحاولة لاحقاً';
    } elseif (!csrf_verify()) {
        http_response_code(403);
        $errors[] = 'رمز الأمان غير صالح، يرجى إعادة المحاولة';
    } else {
        // Validate required fields
        if ($err = auth_username_error($username)) $errors[] = $err;
        if ($err = auth_email_error($email)) $errors[] = $err;
        if ($err = auth_password_error($password)) $errors[] = $err;
        if ($siteName === '') $errors[] = 'اسم الموقع مطلوب';
        elseif (mb_strlen($siteName, 'UTF-8') > 100 || preg_match('/\p{Cc}/u', $siteName)) $errors[] = 'اسم الموقع غير صالح (100 حرف كحد أقصى)';
        if (strlen($botToken) > 200 || preg_match('/[^\w:\-]/', $botToken)) $errors[] = 'توكن البوت غير صالح';
        if (strlen($chatId) > 64 || preg_match('/[^0-9\-@\w]/', $chatId)) $errors[] = 'معرّف المحادثة غير صالح';
    }

    if (empty($errors)) {
        try {
            // Handle logo upload first (it aborts on an invalid file before anything is created)
            $logoPath = handle_upload('company_logo', UPLOAD_LOGO);

            $hash = password_hash($password, PASSWORD_BCRYPT);
            $created = DB::transaction(function (PDO $db) use ($username, $email, $hash, $siteName, $themeColor, $botToken, $chatId, $logoPath) {
                // Re-check inside the write lock so two concurrent submissions cannot both create an admin
                if ((int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() > 0) {
                    return false;
                }
                $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, 'admin')");
                $stmt->execute([$username, $email, $hash]);

                // Save settings
                DB::setSetting('site_name', $siteName);
                DB::setSetting('theme_color', $themeColor);
                if ($botToken !== '') DB::setSetting('telegram_bot_token', $botToken);
                if ($chatId !== '') DB::setSetting('telegram_admin_chat_id', $chatId);
                if ($logoPath) DB::setSetting('company_logo', $logoPath);
                return true;
            });

            if (!$created) {
                if ($logoPath) delete_upload($logoPath);
                http_response_code(404);
                exit;
            }

            header('Location: /');
            exit;
        } catch (Throwable $e) {
            error_log('[CStore] setup failed: ' . get_class($e) . ': ' . $e->getMessage());
            if (!empty($logoPath)) delete_upload($logoPath);
            http_response_code(500);
            $errors[] = 'حدث خطأ أثناء الإعداد، يرجى المحاولة لاحقاً';
        }
    }
}

$csrfToken = csrf_token();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إعداد CStore</title>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: <?= setup_e($themeColor) ?>;
            --primary-hover: color-mix(in srgb, var(--primary) 85%, black);
            --primary-light: color-mix(in srgb, var(--primary) 12%, white);
            --bg: #f8f9fa;
            --card-bg: #ffffff;
            --text: #1a1a2e;
            --text-secondary: #6b7280;
            --border: #e5e7eb;
            --radius: 12px;
            --shadow: 0 4px 24px rgba(0,0,0,0.08);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'IBM Plex Sans Arabic', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .setup-card {
            background: var(--card-bg);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            width: 100%;
            max-width: 520px;
            padding: 2.5rem;
        }
        .setup-card h1 {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            text-align: center;
        }
        .setup-card p.subtitle {
            text-align: center;
            color: var(--text-secondary);
            margin-bottom: 2rem;
            font-size: 0.95rem;
        }
        .section-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--primary);
            margin: 1.5rem 0 0.75rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--primary-light);
        }
        .section-title:first-of-type { margin-top: 0; }
        .form-group {
            margin-bottom: 1rem;
        }
        .form-group label {
            display: block;
            font-size: 0.875rem;
            font-weight: 500;
            margin-bottom: 0.35rem;
            color: var(--text);
        }
        .form-group label .optional {
            font-weight: 400;
            color: var(--text-secondary);
            font-size: 0.8rem;
        }
        .form-group input[type="text"],
        .form-group input[type="email"],
        .form-group input[type="password"],
        .form-group input[type="color"] {
            width: 100%;
            padding: 0.65rem 0.85rem;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            font-size: 0.95rem;
            font-family: inherit;
            transition: border-color 0.2s;
            background: var(--card-bg);
        }
        .form-group input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-light);
        }
        .form-group input[type="color"] {
            height: 44px;
            padding: 4px;
            cursor: pointer;
        }
        .form-group input[type="file"] {
            font-family: inherit;
            font-size: 0.9rem;
        }
        .color-row {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .color-row input[type="color"] {
            width: 50px;
            flex-shrink: 0;
        }
        .color-row input[type="text"] {
            flex: 1;
        }
        .errors {
            background: #fef2f2;
            border: 1px solid #fca5a5;
            border-radius: 8px;
            padding: 0.75rem 1rem;
            margin-bottom: 1rem;
            color: #dc2626;
            font-size: 0.875rem;
        }
        .errors ul { margin: 0; padding-right: 1.25rem; }
        .btn-primary {
            display: block;
            width: 100%;
            padding: 0.75rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            margin-top: 1.5rem;
            transition: background 0.2s, transform 0.1s;
        }
        .btn-primary:hover {
            background: var(--primary-hover);
        }
        .btn-primary:active { transform: scale(0.98); }
        @media (max-width: 540px) {
            .setup-card { padding: 1.5rem; }
        }
    </style>
</head>
<body>
    <div class="setup-card">
        <h1>🚀 إعداد المتجر</h1>
        <p class="subtitle">مرحباً! قم بإعداد حساب المشرف والإعدادات الأساسية</p>

        <?php if (!empty($errors)): ?>
        <div class="errors">
            <ul>
                <?php foreach ($errors as $err): ?>
                <li><?= setup_e($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= setup_e($csrfToken) ?>">
            <div class="section-title">👤 حساب المشرف</div>
            
            <div class="form-group">
                <label>اسم المستخدم</label>
                <input type="text" name="username" value="<?= setup_e(setup_post('username')) ?>" required minlength="3" maxlength="32">
            </div>
            <div class="form-group">
                <label>البريد الإلكتروني</label>
                <input type="email" name="email" value="<?= setup_e(setup_post('email')) ?>" required maxlength="190">
            </div>
            <div class="form-group">
                <label>كلمة المرور</label>
                <input type="password" name="password" required minlength="8" maxlength="200">
            </div>

            <div class="section-title">🏪 إعدادات الموقع</div>

            <div class="form-group">
                <label>اسم الموقع</label>
                <input type="text" name="site_name" value="<?= setup_e(setup_post('site_name', 'CStore')) ?>" required maxlength="100">
            </div>
            <div class="form-group">
                <label>شعار الشركة <span class="optional">(اختياري)</span></label>
                <input type="file" name="company_logo" accept="image/*">
            </div>
            <div class="form-group">
                <label>لون الثيم</label>
                <div class="color-row">
                    <input type="color" id="theme_color_picker" value="<?= setup_e($themeColor) ?>">
                    <input type="text" name="theme_color" id="theme_color_text" value="<?= setup_e($themeColor) ?>" pattern="^#[0-9A-Fa-f]{6}$">
                </div>
            </div>

            <div class="section-title">🤖 تيليجرام <span class="optional">(اختياري)</span></div>

            <div class="form-group">
                <label>توكن البوت <span class="optional">(يمكن إضافته لاحقاً)</span></label>
                <input type="text" name="telegram_bot_token" value="<?= setup_e(setup_post('telegram_bot_token')) ?>" placeholder="123456:ABC-DEF...">
            </div>
            <div class="form-group">
                <label>معرّف محادثة المشرف <span class="optional">(Chat ID)</span></label>
                <input type="text" name="telegram_admin_chat_id" value="<?= setup_e(setup_post('telegram_admin_chat_id')) ?>" placeholder="123456789">
            </div>

            <button type="submit" class="btn-primary">تثبيت وبدء الاستخدام ←</button>
        </form>
    </div>

    <script>
        const picker = document.getElementById('theme_color_picker');
        const text = document.getElementById('theme_color_text');
        picker.addEventListener('input', () => { text.value = picker.value; });
        text.addEventListener('input', () => {
            if (/^#[0-9A-Fa-f]{6}$/.test(text.value)) picker.value = text.value;
        });
    </script>
</body>
</html>
