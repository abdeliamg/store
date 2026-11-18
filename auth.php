<?php
/**
 * CStore — Authentication helpers
 */

/** Columns safe to cache in the session (no password hash / TOTP secret). Keep in sync with helpers.php require_auth(). */
const AUTH_USER_COLUMNS = "id, username, email, role, wallet_balance, is_active, ban_reason, created_at, first_name, last_name, profile_image, (totp_secret IS NOT NULL AND totp_secret != '') AS has_2fa";

/** Pending 2FA login lifetime (seconds) and max wrong codes per pending login. */
const AUTH_2FA_TTL = 300;
const AUTH_2FA_MAX_ATTEMPTS = 5;

/** Valid bcrypt hash used to equalize login timing for unknown accounts. */
const AUTH_DUMMY_HASH = '$2y$10$zVut8bxj.12bd4BtmaZeBub0h6EsXgMXSn.Db.lrU7UbN3vZoNtnG';

/* ------------------------------------------------------------------
 * Input validation helpers (shared by register, profile, admin, setup)
 * The *_error() helpers return an error message, or null when valid.
 * ------------------------------------------------------------------ */

function auth_username_error(string $username): ?string {
    if (!preg_match('/^[\p{L}\p{N}_.\-]{3,32}$/u', $username)) {
        return 'اسم المستخدم يجب أن يكون من 3 إلى 32 حرفاً (حروف وأرقام و _ . - فقط)';
    }
    return null;
}

function auth_email_error(string $email): ?string {
    if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'البريد الإلكتروني غير صالح';
    }
    return null;
}

function auth_password_error(string $password): ?string {
    if (strlen($password) < 8) return 'كلمة المرور يجب أن تكون 8 أحرف على الأقل';
    if (strlen($password) > 200) return 'كلمة المرور طويلة جداً (200 حرف كحد أقصى)';
    return null;
}

/**
 * Normalize a first/last name: trim, strip control chars, max 50 chars, no '<' or '>'.
 * Calls json_error() on invalid input.
 */
function auth_clean_name($value, string $label = 'الاسم'): string {
    $value = is_string($value) ? $value : '';
    $value = trim(preg_replace('/\p{Cc}+/u', '', $value) ?? '');
    if ($value === '') return '';
    if (mb_strlen($value, 'UTF-8') > 50) json_error($label . ' يجب ألا يتجاوز 50 حرفاً');
    if (strpbrk($value, '<>') !== false) json_error($label . ' يحتوي على أحرف غير مسموحة');
    return $value;
}

/* ------------------------------------------------------------------
 * TOTP replay protection
 * ------------------------------------------------------------------ */

/**
 * Atomically mark TOTP time step $step as used for $userId. Returns false if
 * this step (or a later one) was already consumed — prevents code replay,
 * including parallel requests.
 */
function totp_consume_step(int $userId, int $step): bool {
    $stmt = DB::get()->prepare("UPDATE users SET totp_last_step = ? WHERE id = ? AND totp_last_step < ?");
    $stmt->execute([$step, $userId, $step]);
    return $stmt->rowCount() === 1;
}

/** Verify a TOTP code for a user and consume its time step (single use). */
function totp_check_and_consume(int $userId, string $secret, string $code): bool {
    $step = TOTP::verifyStep($secret, $code);
    if ($step === null) return false;
    return totp_consume_step($userId, $step);
}

/* ------------------------------------------------------------------ */

/**
 * Register a new user
 */
function auth_register(string $username, string $email, string $password, string $firstName = '', string $lastName = ''): array {
    $db = DB::get();

    // Validate
    if ($err = auth_username_error($username)) json_error($err);
    if ($err = auth_email_error($email)) json_error($err);
    if ($err = auth_password_error($password)) json_error($err);
    $firstName = auth_clean_name($firstName, 'الاسم الأول');
    $lastName = auth_clean_name($lastName, 'اسم العائلة');

    // Check unique (case-insensitive)
    $stmt = $db->prepare("SELECT id FROM users WHERE lower(username) = lower(?) OR lower(email) = lower(?)");
    $stmt->execute([$username, $email]);
    if ($stmt->fetch()) {
        json_error('اسم المستخدم أو البريد الإلكتروني مستخدم بالفعل');
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    try {
        $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, first_name, last_name) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$username, $email, $hash, $firstName ?: null, $lastName ?: null]);
    } catch (PDOException $e) {
        if (stripos($e->getMessage(), 'UNIQUE') !== false) {
            json_error('اسم المستخدم أو البريد الإلكتروني مستخدم بالفعل');
        }
        server_error($e);
    }

    $userId = (int) $db->lastInsertId();

    // Auto login (session data, incl. CSRF token, is preserved)
    session_regenerate_id(true);
    unset($_SESSION['_auth_cache'], $_SESSION['_auth_cache_ts']);
    auth_clear_pending_2fa();
    $_SESSION['user_id'] = $userId;
    $_SESSION['user_role'] = 'user';
    bind_session_to_password($userId);

    return [
        'id' => $userId,
        'username' => $username,
        'email' => $email,
        'role' => 'user',
        'wallet_balance' => 0.00,
        'first_name' => $firstName ?: null,
        'last_name' => $lastName ?: null,
        'profile_image' => null,
        'kyc_status' => 'none',
    ];
}

/**
 * Login Step 1 — Validate credentials only (does NOT set session)
 * Returns user row + requires_2fa flag
 */
function auth_login_step1(string $username, string $password, ?callable $onFailure = null): array {
    $db = DB::get();

    $stmt = $db->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch();

    if (!$user) {
        // Equalize timing so response time does not reveal whether the account exists
        password_verify($password, AUTH_DUMMY_HASH);
        if ($onFailure) $onFailure();
        json_error('اسم المستخدم أو كلمة المرور غير صحيحة');
    }
    if (!password_verify($password, $user['password_hash'])) {
        if ($onFailure) $onFailure();
        json_error('اسم المستخدم أو كلمة المرور غير صحيحة');
    }

    if (!$user['is_active']) {
        json_error('تم حظر حسابك: ' . ($user['ban_reason'] ?: 'لا يوجد سبب محدد'));
    }

    return [
        'user' => $user,
        'requires_2fa' => !empty($user['totp_secret']),
    ];
}

/** Start a pending 2FA login for $userId. */
function auth_begin_pending_2fa(int $userId): void {
    // Drop any previously logged-in identity in this session
    unset($_SESSION['user_id'], $_SESSION['user_role'], $_SESSION['2fa_verified'], $_SESSION['_auth_cache'], $_SESSION['_auth_cache_ts']);
    session_regenerate_id(true);
    $_SESSION['pending_2fa_user_id'] = $userId;
    $_SESSION['pending_2fa_at'] = time();
    $_SESSION['pending_2fa_attempts'] = 0;
}

/** Clear any pending 2FA login state. */
function auth_clear_pending_2fa(): void {
    unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_at'], $_SESSION['pending_2fa_attempts']);
}

/**
 * Complete 2FA verification and login
 */
function auth_verify_2fa(string $code, bool $remember = false): array {
    if (empty($_SESSION['pending_2fa_user_id'])) {
        json_error('لا يوجد عملية تحقق معلقة', 400);
    }
    $userId = (int)$_SESSION['pending_2fa_user_id'];

    if ((int)($_SESSION['pending_2fa_at'] ?? 0) < time() - AUTH_2FA_TTL) {
        auth_clear_pending_2fa();
        json_error('انتهت صلاحية عملية التحقق، يرجى تسجيل الدخول مجدداً', 400);
    }

    // Cross-session guard against brute forcing a user's codes
    rate_limit('2fa_user:' . $userId, 10, 900);

    $db = DB::get();
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user || empty($user['totp_secret']) || !$user['is_active']) {
        auth_clear_pending_2fa();
        json_error('خطأ في التحقق', 400);
    }

    if (!preg_match('/^\d{6}$/', $code) || !totp_check_and_consume($userId, $user['totp_secret'], $code)) {
        $_SESSION['pending_2fa_attempts'] = (int)($_SESSION['pending_2fa_attempts'] ?? 0) + 1;
        if ($_SESSION['pending_2fa_attempts'] >= AUTH_2FA_MAX_ATTEMPTS) {
            auth_clear_pending_2fa();
            json_error('تم تجاوز عدد المحاولات المسموح، يرجى تسجيل الدخول مجدداً', 400);
        }
        json_error('رمز التحقق غير صحيح');
    }

    // Complete login (session data, incl. CSRF token, is preserved)
    auth_clear_pending_2fa();
    session_regenerate_id(true);
    unset($_SESSION['_auth_cache'], $_SESSION['_auth_cache_ts']);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['2fa_verified'] = true;
    bind_session_to_password((int)$user['id']);

    // Remember this device for 30 days
    if ($remember) {
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresTs = time() + 30 * 24 * 3600;
        // SQLite datetime('now') is UTC, so store expiry in UTC
        $db->prepare("INSERT INTO remember_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)")
           ->execute([$user['id'], $tokenHash, gmdate('Y-m-d H:i:s', $expiresTs)]);
        setcookie('cstore_remember', $token, cookie_opts($expiresTs));
    }

    $kycStatus = get_user_kyc_status((int)$user['id']);

    return [
        'id' => $user['id'],
        'username' => $user['username'],
        'email' => $user['email'],
        'role' => $user['role'],
        'wallet_balance' => $user['wallet_balance'],
        'first_name' => $user['first_name'],
        'last_name' => $user['last_name'],
        'profile_image' => $user['profile_image'],
        'kyc_status' => $kycStatus,
    ];
}

/**
 * Logout. Clears the session but keeps the CSRF token so the already-loaded
 * SPA can log in again without a page reload. Returns the CSRF token.
 */
function auth_logout(): string {
    // Clear remember token
    if (!empty($_COOKIE['cstore_remember']) && is_string($_COOKIE['cstore_remember'])) {
        $db = DB::get();
        $tokenHash = hash('sha256', $_COOKIE['cstore_remember']);
        $db->prepare("DELETE FROM remember_tokens WHERE token_hash = ?")->execute([$tokenHash]);
    }
    setcookie('cstore_remember', '', cookie_opts(time() - 3600));

    $csrf = $_SESSION['csrf_token'] ?? '';
    $_SESSION = [];
    session_regenerate_id(true);
    if ($csrf !== '') $_SESSION['csrf_token'] = $csrf;
    return csrf_token();
}

/**
 * Get current user info (or null). Same columns as require_auth() (no secrets).
 */
function auth_user(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    // Use session cache (shared with require_auth) to avoid DB hit on every page load
    if (!empty($_SESSION['_auth_cache']) && ($_SESSION['_auth_cache_ts'] ?? 0) > time() - 30
        && !empty($_SESSION['pw_fp'])
        && (int)($_SESSION['_auth_cache']['id'] ?? 0) === (int)$_SESSION['user_id']) {
        $cached = $_SESSION['_auth_cache'];
        return [
            'id' => $cached['id'], 'username' => $cached['username'], 'email' => $cached['email'],
            'role' => $cached['role'], 'wallet_balance' => $cached['wallet_balance'],
            'is_active' => $cached['is_active'], 'ban_reason' => $cached['ban_reason'] ?? null,
            'created_at' => $cached['created_at'],
            'first_name' => $cached['first_name'] ?? null,
            'last_name' => $cached['last_name'] ?? null,
            'profile_image' => $cached['profile_image'] ?? null,
            'has_2fa' => (int)($cached['has_2fa'] ?? 0),
        ];
    }
    $db = DB::get();
    $stmt = $db->prepare("SELECT " . AUTH_USER_COLUMNS . ", password_hash FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch() ?: null;
    if (!$user) return null;

    // Same session rules as require_auth(): banned users and sessions not bound to the
    // current password (changed elsewhere, or created before binding existed) are logged out.
    $fp = $user['id'] . ':' . password_fingerprint((string)$user['password_hash']);
    unset($user['password_hash']);
    $sessFp = (string)($_SESSION['pw_fp'] ?? '');
    if (!$user['is_active'] || $sessFp === '' || !hash_equals($sessFp, $fp)) {
        unset($_SESSION['user_id'], $_SESSION['user_role'], $_SESSION['pw_fp'], $_SESSION['_auth_cache'], $_SESSION['_auth_cache_ts'], $_SESSION['2fa_verified']);
        return null;
    }

    $_SESSION['_auth_cache'] = $user;
    $_SESSION['_auth_cache_ts'] = time();
    return $user;
}

/**
 * Get current user info — always fresh from DB (bypasses session cache)
 */
function auth_user_fresh(): ?array {
    unset($_SESSION['_auth_cache'], $_SESSION['_auth_cache_ts']);
    return auth_user();
}

/**
 * Check if current user is admin
 */
function is_admin(): bool {
    return ($_SESSION['user_role'] ?? '') === 'admin';
}

/**
 * Get user's KYC status
 * Returns: 'none', 'pending', 'approved', 'denied'
 */
function get_user_kyc_status(int $userId): string {
    $db = DB::get();
    $stmt = $db->prepare("SELECT status FROM kyc_applications WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ? $row['status'] : 'none';
}
