<?php
/**
 * CStore — Auth API
 * POST ?action=login     { username, password }
 * POST ?action=register  { username, email, password, first_name, last_name }
 * POST ?action=verify_2fa { code, remember }
 * POST ?action=logout
 * GET  ?action=me
 */
require_once __DIR__ . '/../config.php';

$action = $_GET['action'] ?? '';

/** Read a string field from the request body (non-strings become ''). */
function auth_field(array $data, string $key): string {
    $v = $data[$key] ?? '';
    return is_string($v) ? $v : '';
}

try {
switch ($action) {
    case 'login':
        require_method('POST');
        require_csrf();
        $data = json_body();
        $username = trim(auth_field($data, 'username'));
        $password = auth_field($data, 'password');
        if ($username === '' || $password === '') json_error('جميع الحقول مطلوبة');
        if (mb_strlen($username, 'UTF-8') > 190 || strlen($password) > 200) {
            json_error('اسم المستخدم أو كلمة المرور غير صحيحة');
        }

        // Only FAILED attempts count toward per-account limits, so nobody can lock a user
        // (e.g. the admin) out just by sending requests:
        //  - per account + IP: 8 failures / 15 min   (stops a single attacker)
        //  - per account, any IP: 100 failures / hour (slows distributed guessing)
        //  - per IP, any account: 30 attempts / hour  (stops spraying)
        $name = mb_strtolower($username, 'UTF-8');
        $failIpBucket = 'login_fail:' . $name . '|' . client_ip();
        $failUserBucket = 'login_fail_user:' . $name;
        rate_limit('login_ip:' . client_ip(), 30, 3600);
        if (rate_limit_count($failIpBucket, 900) >= 8 || rate_limit_count($failUserBucket, 3600) >= 100) {
            header('Retry-After: 900');
            json_error('محاولات كثيرة جداً، يرجى المحاولة لاحقاً', 429);
        }

        $result = auth_login_step1($username, $password, function () use ($failIpBucket, $failUserBucket) {
            rate_limit_hit($failIpBucket, 900);
            rate_limit_hit($failUserBucket, 3600);
        });
        rate_limit_clear($failIpBucket);

        if ($result['requires_2fa']) {
            // Store pending user for 2FA verification
            auth_begin_pending_2fa((int)$result['user']['id']);
            json_success(['requires_2fa' => true], 'يرجى إدخال رمز التحقق');
        } else {
            // No 2FA — complete login directly (session data, incl. CSRF token, is preserved)
            $user = $result['user'];
            auth_clear_pending_2fa();
            session_regenerate_id(true);
            unset($_SESSION['_auth_cache'], $_SESSION['_auth_cache_ts']);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_role'] = $user['role'];
            bind_session_to_password((int)$user['id']);

            $kycStatus = get_user_kyc_status((int)$user['id']);
            json_success(['user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'role' => $user['role'],
                'wallet_balance' => $user['wallet_balance'],
                'first_name' => $user['first_name'],
                'last_name' => $user['last_name'],
                'profile_image' => $user['profile_image'],
                'kyc_status' => $kycStatus,
            ]], 'تم تسجيل الدخول بنجاح');
        }
        break;

    case 'verify_2fa':
        require_method('POST');
        require_csrf();
        $data = json_body();
        $code = trim(auth_field($data, 'code'));
        $remember = !empty($data['remember']);
        if ($code === '') json_error('رمز التحقق مطلوب');
        $user = auth_verify_2fa($code, $remember);
        json_success(['user' => $user], 'تم تسجيل الدخول بنجاح');
        break;

    case 'register':
        require_method('POST');
        require_csrf();
        rate_limit('register_ip:' . client_ip(), 5, 3600);
        $data = json_body();
        $username = trim(auth_field($data, 'username'));
        $email = trim(auth_field($data, 'email'));
        $password = auth_field($data, 'password');
        $firstName = auth_field($data, 'first_name');
        $lastName = auth_field($data, 'last_name');
        if ($username === '' || $email === '' || $password === '') json_error('جميع الحقول مطلوبة');
        $user = auth_register($username, $email, $password, $firstName, $lastName);
        json_success(['user' => $user], 'تم إنشاء الحساب بنجاح');
        break;

    case 'logout':
        require_method('POST');
        require_csrf();
        $csrf = auth_logout();
        json_success(['csrf' => $csrf], 'تم تسجيل الخروج');
        break;

    case 'me':
        $user = auth_user_fresh();
        if (!$user) json_error('غير مصرح', 401);
        $kycStatus = get_user_kyc_status((int)$user['id']);
        json_success(['user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'role' => $user['role'],
            'wallet_balance' => $user['wallet_balance'],
            'first_name' => $user['first_name'] ?? null,
            'last_name' => $user['last_name'] ?? null,
            'profile_image' => $user['profile_image'] ?? null,
            'kyc_status' => $kycStatus,
        ]]);
        break;

    default:
        json_error('إجراء غير معروف', 404);
}
} catch (Throwable $e) {
    server_error($e);
}
