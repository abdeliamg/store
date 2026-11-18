<?php
/**
 * CStore — Configuration & Constants
 */

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Is the current request over HTTPS (directly or behind a TLS-terminating proxy)?
function is_https_request(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') return true;
    return (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
}

// Cookie options for every app cookie we set ourselves (remember-me, etc.)
function cookie_opts(int $expires): array {
    return [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => is_https_request(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

// Session configuration
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_secure', is_https_request() ? '1' : '0');

// Constants (defined before session for remember-me check)
define('ROOT_DIR', __DIR__);
define('DB_PATH', ROOT_DIR . '/cstore.db');
define('UPLOADS_DIR', ROOT_DIR . '/uploads');
define('CURRENCY_SYMBOL', '$');
define('CURRENCY_CODE', 'USD');

// Upload subdirectories
define('UPLOAD_PROOFS', UPLOADS_DIR . '/proofs');
define('UPLOAD_PRODUCTS', UPLOADS_DIR . '/products');
define('UPLOAD_CAROUSEL', UPLOADS_DIR . '/carousel');
define('UPLOAD_DELIVERIES', UPLOADS_DIR . '/deliveries');
define('UPLOAD_FIELDS', UPLOADS_DIR . '/fields');
define('UPLOAD_LOGO', UPLOADS_DIR . '/logo');
define('UPLOAD_PROFILES', UPLOADS_DIR . '/profiles');
define('UPLOAD_KYC', UPLOADS_DIR . '/kyc');
define('UPLOAD_STOCK', UPLOADS_DIR . '/stock'); // private: digital goods (served via api/file.php)

// Private upload folders: never served directly; uploads/.htaccess routes them to api/file.php
const PRIVATE_UPLOAD_DIRS = ['kyc', 'proofs', 'fields', 'deliveries', 'stock'];

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Remember-me cookie: auto-restore session from persistent token
if (empty($_SESSION['user_id']) && !empty($_COOKIE['cstore_remember'])) {
    try {
        require_once ROOT_DIR . '/db.php';
        $rmDb = DB::get();
        $tokenHash = hash('sha256', $_COOKIE['cstore_remember']);
        $rmStmt = $rmDb->prepare("SELECT user_id FROM remember_tokens WHERE token_hash = ? AND expires_at > datetime('now')");
        $rmStmt->execute([$tokenHash]);
        $rmRow = $rmStmt->fetch();
        if ($rmRow) {
            // Verify user still exists and is active
            $rmUserStmt = $rmDb->prepare("SELECT id, role FROM users WHERE id = ? AND is_active = 1");
            $rmUserStmt->execute([$rmRow['user_id']]);
            $rmUser = $rmUserStmt->fetch();
            if ($rmUser) {
                $_SESSION['user_id'] = $rmUser['id'];
                $_SESSION['user_role'] = $rmUser['role'];
                $_SESSION['2fa_verified'] = true;
                session_regenerate_id(true);
                $rmNeedsPwBind = (int)$rmUser['id'];
            } else {
                // User deleted/banned — clear cookie
                setcookie('cstore_remember', '', cookie_opts(time() - 3600));
            }
        } else {
            // Invalid/expired token — clear cookie
            setcookie('cstore_remember', '', cookie_opts(time() - 3600));
        }
    } catch (Exception $e) {
        // DB not ready yet, ignore
    }
}

// Ensure upload directories exist (checked once per session)
if (empty($_SESSION['_dirs_ok'])) {
    $uploadDirs = [UPLOAD_PROOFS, UPLOAD_PRODUCTS, UPLOAD_CAROUSEL, UPLOAD_DELIVERIES, UPLOAD_FIELDS, UPLOAD_LOGO, UPLOAD_PROFILES, UPLOAD_KYC, UPLOAD_STOCK];
    foreach ($uploadDirs as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
    $_SESSION['_dirs_ok'] = true;
}

// Include core files
require_once ROOT_DIR . '/db.php';
require_once ROOT_DIR . '/helpers.php';
require_once ROOT_DIR . '/auth.php';
require_once ROOT_DIR . '/totp.php';

// Session restored from a remember-me token: bind it to the current password
if (!empty($rmNeedsPwBind)) {
    bind_session_to_password($rmNeedsPwBind);
}
