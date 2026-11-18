<?php
/**
 * CStore — Profile API
 * GET  ?action=get
 * POST ?action=update         (multipart — first_name, last_name, profile_image)
 * POST ?action=change_password { current_password, new_password }
 * POST ?action=enable_2fa      (generates temp secret)
 * POST ?action=confirm_2fa    { code }
 * POST ?action=disable_2fa    { password }
 */
require_once __DIR__ . '/../config.php';

$action = $_GET['action'] ?? '';
$db = DB::get();

/** Read a string field from a request array (non-strings become ''). */
function profile_field(array $data, string $key): string {
    $v = $data[$key] ?? '';
    return is_string($v) ? $v : '';
}

try {
switch ($action) {
    case 'get':
        $user = require_auth();
        release_session();
        $kycStatus = get_user_kyc_status((int)$user['id']);
        json_success(['profile' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'first_name' => $user['first_name'] ?? '',
            'last_name' => $user['last_name'] ?? '',
            'profile_image' => $user['profile_image'] ?? '',
            'has_2fa' => !empty($user['has_2fa']),
            'kyc_status' => $kycStatus,
            'created_at' => $user['created_at'],
        ]]);
        break;

    case 'update':
        require_method('POST');
        $user = require_auth();
        require_csrf();

        $firstName = auth_clean_name(profile_field($_POST, 'first_name'), 'الاسم الأول');
        $lastName = auth_clean_name(profile_field($_POST, 'last_name'), 'اسم العائلة');

        $profileImage = handle_upload('profile_image', UPLOAD_PROFILES);

        if ($profileImage) {
            $oldStmt = $db->prepare("SELECT profile_image FROM users WHERE id=?");
            $oldStmt->execute([$user['id']]);
            $oldImage = $oldStmt->fetchColumn();

            $stmt = $db->prepare("UPDATE users SET first_name=?, last_name=?, profile_image=? WHERE id=?");
            $stmt->execute([$firstName ?: null, $lastName ?: null, $profileImage, $user['id']]);

            if ($oldImage && $oldImage !== $profileImage) {
                delete_upload((string)$oldImage);
            }
        } else {
            $stmt = $db->prepare("UPDATE users SET first_name=?, last_name=? WHERE id=?");
            $stmt->execute([$firstName ?: null, $lastName ?: null, $user['id']]);
        }

        // Clear auth cache
        unset($_SESSION['_auth_cache'], $_SESSION['_auth_cache_ts']);
        release_session();

        json_success([], 'تم تحديث الملف الشخصي');
        break;

    case 'change_password':
        require_method('POST');
        $user = require_auth();
        require_csrf();

        $data = json_body();
        $currentPassword = profile_field($data, 'current_password');
        $newPassword = profile_field($data, 'new_password');

        if ($currentPassword === '' || $newPassword === '') json_error('جميع الحقول مطلوبة');
        if ($err = auth_password_error($newPassword)) json_error($err);

        rate_limit('pwcheck_user:' . $user['id'], 10, 900);

        // Verify current password
        $stmt = $db->prepare("SELECT password_hash FROM users WHERE id=?");
        $stmt->execute([$user['id']]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
            json_error('كلمة المرور الحالية غير صحيحة');
        }

        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        DB::transaction(function (PDO $tx) use ($hash, $user) {
            $tx->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$hash, $user['id']]);
            // Invalidate every remembered device
            $tx->prepare("DELETE FROM remember_tokens WHERE user_id=?")->execute([$user['id']]);
        });
        rate_limit_clear('pwcheck_user:' . $user['id']);

        setcookie('cstore_remember', '', cookie_opts(time() - 3600));
        session_regenerate_id(true);
        unset($_SESSION['_auth_cache'], $_SESSION['_auth_cache_ts']);
        // Keep THIS session valid; every other session of the user is logged out by require_auth().
        $_SESSION['pw_fp'] = $user['id'] . ':' . password_fingerprint($hash);

        json_success([], 'تم تغيير كلمة المرور');
        break;

    case 'enable_2fa':
        require_method('POST');
        $user = require_auth();
        require_csrf();
        // Do NOT release_session — we need to write temp secret

        $stmt = $db->prepare("SELECT (totp_secret IS NOT NULL AND totp_secret != '') FROM users WHERE id=?");
        $stmt->execute([$user['id']]);
        if ((int)$stmt->fetchColumn() === 1) {
            json_error('التحقق بخطوتين مفعّل بالفعل');
        }

        $secret = TOTP::generateSecret();
        $_SESSION['_temp_2fa_secret'] = $secret;

        $label = $user['username'];
        $siteName = DB::setting('site_name', 'CStore');
        $otpauthUrl = TOTP::getOtpAuthUrl($label, $secret, $siteName);

        json_success([
            'secret' => $secret,
            'otpauth_url' => $otpauthUrl,
        ]);
        break;

    case 'confirm_2fa':
        require_method('POST');
        $user = require_auth();
        require_csrf();

        $data = json_body();
        $code = trim(profile_field($data, 'code'));
        if ($code === '') json_error('رمز التحقق مطلوب');
        if (!preg_match('/^\d{6}$/', $code)) json_error('رمز التحقق غير صحيح');

        $tempSecret = $_SESSION['_temp_2fa_secret'] ?? '';
        if (!$tempSecret) json_error('يرجى تفعيل التحقق بخطوتين أولاً');

        rate_limit('2fa_confirm_user:' . $user['id'], 10, 900);

        $step = TOTP::verifyStep($tempSecret, $code);
        if ($step === null) {
            json_error('رمز التحقق غير صحيح');
        }

        // Save to DB atomically: only if 2FA not already enabled and the step was not used before
        $saved = DB::transaction(function (PDO $tx) use ($tempSecret, $step, $user) {
            $stmt = $tx->prepare("UPDATE users SET totp_secret=?, totp_last_step=? WHERE id=? AND totp_last_step < ? AND (totp_secret IS NULL OR totp_secret = '')");
            $stmt->execute([$tempSecret, $step, $user['id'], $step]);
            return $stmt->rowCount() === 1;
        });
        if (!$saved) {
            json_error('رمز التحقق غير صحيح أو تم استخدامه مسبقاً');
        }
        unset($_SESSION['_temp_2fa_secret']);
        unset($_SESSION['_auth_cache'], $_SESSION['_auth_cache_ts']);

        json_success([], 'تم تفعيل التحقق بخطوتين');
        break;

    case 'disable_2fa':
        require_method('POST');
        $user = require_auth();
        require_csrf();

        $data = json_body();
        $password = profile_field($data, 'password');
        if ($password === '') json_error('كلمة المرور مطلوبة');

        rate_limit('pwcheck_user:' . $user['id'], 10, 900);

        // Verify password
        $stmt = $db->prepare("SELECT password_hash, totp_secret FROM users WHERE id=?");
        $stmt->execute([$user['id']]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($password, $row['password_hash'])) {
            json_error('كلمة المرور غير صحيحة');
        }

        // Disabling 2FA requires the second factor too (a stolen session + password is not enough)
        $code = trim(profile_field($data, 'code'));
        if (!empty($row['totp_secret'])) {
            if ($code === '') json_error('رمز التحقق من تطبيق المصادقة مطلوب');
            if (!totp_check_and_consume((int)$user['id'], $row['totp_secret'], $code)) {
                json_error('رمز التحقق غير صحيح');
            }
        }

        DB::transaction(function (PDO $tx) use ($user) {
            $tx->prepare("UPDATE users SET totp_secret=NULL WHERE id=?")->execute([$user['id']]);
            // Clear remember tokens for this user
            $tx->prepare("DELETE FROM remember_tokens WHERE user_id=?")->execute([$user['id']]);
        });
        rate_limit_clear('pwcheck_user:' . $user['id']);
        unset($_SESSION['_auth_cache'], $_SESSION['_auth_cache_ts']);

        json_success([], 'تم إلغاء التحقق بخطوتين');
        break;

    default:
        json_error('إجراء غير معروف', 404);
}
} catch (Throwable $e) {
    server_error($e);
}
