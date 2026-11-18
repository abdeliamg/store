<?php
/**
 * CStore — Helper Functions
 */

/**
 * Generate or validate CSRF token
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verify(): bool {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || $token === '' || empty($_SESSION['csrf_token'])) return false;
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * JSON response helper
 */
function json_response(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $message, int $code = 400): void {
    json_response(['success' => false, 'error' => $message], $code);
}

function json_success(array $data = [], string $message = ''): void {
    $response = ['success' => true];
    if ($message) $response['message'] = $message;
    json_response(array_merge($response, $data));
}

/**
 * Require authentication
 */
function require_auth(): array {
    if (empty($_SESSION['user_id'])) {
        json_error('غير مصرح', 401);
    }
    // Cache user in session for 30 seconds to avoid DB hit on every request
    if (!empty($_SESSION['_auth_cache']) && ($_SESSION['_auth_cache_ts'] ?? 0) > time() - 30) {
        return $_SESSION['_auth_cache'];
    }
    $db = DB::get();
    // Never cache secrets (password hash, TOTP secret) in the session.
    $stmt = $db->prepare("SELECT id, username, email, role, wallet_balance, is_active, ban_reason, created_at, first_name, last_name, profile_image, (totp_secret IS NOT NULL AND totp_secret != '') AS has_2fa, password_hash FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        session_destroy();
        json_error('غير مصرح', 401);
    }
    // Sessions are bound to the password: changing it (or an admin reset) logs out every other session.
    $fp = $user['id'] . ':' . password_fingerprint((string)$user['password_hash']);
    unset($user['password_hash']);
    $sessFp = (string)($_SESSION['pw_fp'] ?? '');
    if ($sessFp === '' || strpos($sessFp, $user['id'] . ':') !== 0) {
        // First check for this login (or a different account in this session) — bind it now.
        $_SESSION['pw_fp'] = $fp;
    } elseif (!hash_equals($sessFp, $fp)) {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
        json_error('انتهت الجلسة، يرجى تسجيل الدخول مجدداً', 401);
    }
    if (!$user['is_active']) {
        session_destroy();
        json_error('تم حظر حسابك: ' . ($user['ban_reason'] ?: 'لا يوجد سبب محدد'), 403);
    }
    $_SESSION['_auth_cache'] = $user;
    $_SESSION['_auth_cache_ts'] = time();
    return $user;
}

/** Bind the current session to the user's current password (call right after login). */
function bind_session_to_password(int $userId): void {
    $stmt = DB::get()->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $hash = (string)$stmt->fetchColumn();
    $_SESSION['pw_fp'] = $userId . ':' . password_fingerprint($hash);
}

/** Short, non-reversible fingerprint of a password hash (stored in the session). */
function password_fingerprint(string $passwordHash): string {
    return substr(hash('sha256', 'cstore-session|' . $passwordHash), 0, 32);
}

/**
 * Force-refresh auth from DB (for balance-critical operations)
 */
function require_auth_fresh(): array {
    unset($_SESSION['_auth_cache'], $_SESSION['_auth_cache_ts']);
    return require_auth();
}

/**
 * Require admin role
 */
function require_admin(): array {
    $user = require_auth();
    if ($user['role'] !== 'admin') {
        json_error('صلاحيات غير كافية', 403);
    }
    return $user;
}

/**
 * Release session write lock early (for read-only API endpoints)
 * Allows concurrent requests from the same user
 */
function release_session(): void {
    session_write_close();
}

/**
 * File upload handler
 * Returns the relative file path from the uploads directory
 */
/** Allowed image MIME types => extension (extension is NEVER taken from the client). */
const UPLOAD_IMAGE_MIMES = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
];

/** Non-image MIME types that may be stored for deliveries (served as attachments only). */
const UPLOAD_DELIVERY_MIMES = [
    'application/pdf'              => 'pdf',
    'text/plain'                   => 'txt',
    'application/zip'              => 'zip',
    'application/x-zip-compressed' => 'zip',
    'video/mp4'                    => 'mp4',
    'audio/mpeg'                   => 'mp3',
    'audio/ogg'                    => 'ogg',
    'video/ogg'                    => 'ogg',
    'audio/mp4'                    => 'm4a',
];

const UPLOAD_MAX_BYTES  = 10 * 1024 * 1024;  // 10MB per uploaded file
const UPLOAD_MAX_SIDE   = 6000;              // max width/height in px
const UPLOAD_MAX_PIXELS = 40000000;          // 40 megapixels (GD memory guard)

/** Random, unguessable file name with the given extension. */
function random_upload_name(string $ext): string {
    return bin2hex(random_bytes(16)) . '.' . $ext;
}

/** Detect real MIME type of a file on disk. */
function detect_mime(string $path): string {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = (string)finfo_file($finfo, $path);
    finfo_close($finfo);
    return $mime;
}

/**
 * Validate an image file on disk and write a freshly re-encoded copy into $targetDir.
 * Anything that is not a real, decodable JPEG/PNG/GIF/WEBP is rejected, so polyglots
 * (image header + appended PHP/HTML) can never be stored.
 * Returns the path relative to ROOT_DIR (e.g. "uploads/kyc/abc.jpg") or null on failure.
 * The source file is NOT deleted.
 */
function store_image_file(string $srcPath, string $targetDir): ?string {
    if (!is_file($srcPath) || !function_exists('imagecreatefromstring')) return null;

    $mime = detect_mime($srcPath);
    if (!isset(UPLOAD_IMAGE_MIMES[$mime])) return null;

    $info = @getimagesize($srcPath);
    if (!$info || empty($info[0]) || empty($info[1])) return null;
    [$w, $h] = $info;
    if ($w > UPLOAD_MAX_SIDE || $h > UPLOAD_MAX_SIDE || ($w * $h) > UPLOAD_MAX_PIXELS) return null;

    $img = @imagecreatefromstring((string)file_get_contents($srcPath));
    if ($img === false) return null;

    $ext = UPLOAD_IMAGE_MIMES[$mime];
    if (!is_dir($targetDir)) @mkdir($targetDir, 0755, true);
    $targetPath = rtrim($targetDir, '/\\') . '/' . random_upload_name($ext);

    if ($ext === 'png' || $ext === 'gif' || $ext === 'webp') {
        imagealphablending($img, false);
        imagesavealpha($img, true);
    }
    $ok = false;
    if ($ext === 'jpg')      { $ok = imagejpeg($img, $targetPath, 90); }
    elseif ($ext === 'png')  { $ok = imagepng($img, $targetPath); }
    elseif ($ext === 'gif')  { $ok = imagegif($img, $targetPath); }
    elseif ($ext === 'webp') { $ok = function_exists('imagewebp') && imagewebp($img, $targetPath, 90); }
    imagedestroy($img);

    if (!$ok || !is_file($targetPath)) {
        @unlink($targetPath);
        return null;
    }
    return upload_rel_path($targetPath);
}

/**
 * Store raw bytes (e.g. a Telegram download). Images are re-encoded through
 * store_image_file(); other types must be in $allowedMimes (mime => ext).
 * Returns relative path or null if rejected.
 */
function store_file_bytes(string $bytes, string $targetDir, array $allowedMimes = UPLOAD_DELIVERY_MIMES, int $maxBytes = 20 * 1024 * 1024): ?string {
    if ($bytes === '' || strlen($bytes) > $maxBytes) return null;

    $tmp = tempnam(sys_get_temp_dir(), 'cs_up_');
    if ($tmp === false) return null;
    try {
        file_put_contents($tmp, $bytes);
        $mime = detect_mime($tmp);

        if (isset(UPLOAD_IMAGE_MIMES[$mime])) {
            return store_image_file($tmp, $targetDir);
        }
        if (!isset($allowedMimes[$mime])) return null;

        if (!is_dir($targetDir)) @mkdir($targetDir, 0755, true);
        $targetPath = rtrim($targetDir, '/\\') . '/' . random_upload_name($allowedMimes[$mime]);
        if (!copy($tmp, $targetPath)) return null;
        return upload_rel_path($targetPath);
    } finally {
        @unlink($tmp);
    }
}

/** Convert an absolute path under ROOT_DIR into "uploads/..." style relative path. */
function upload_rel_path(string $absPath): string {
    $abs  = str_replace('\\', '/', $absPath);
    $root = str_replace('\\', '/', ROOT_DIR) . '/';
    return strpos($abs, $root) === 0 ? substr($abs, strlen($root)) : $abs;
}

/**
 * Handle an image upload from $_FILES[$field].
 * Returns relative path (e.g. "uploads/products/x.jpg"), or null if no file was sent.
 * Calls json_error() (and exits) if a file was sent but is not an acceptable image.
 * Only JPEG/PNG/GIF/WEBP are accepted; the file is always re-encoded.
 */
function handle_upload(string $field, string $targetDir, array $allowedTypes = []): ?string {
    if (empty($_FILES[$field]) || !is_array($_FILES[$field]) || is_array($_FILES[$field]['error'] ?? null)) {
        return null;
    }
    $file = $_FILES[$field];
    if ($file['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        json_error('حجم الملف كبير جداً (الحد الأقصى 10MB)');
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        json_error('فشل في رفع الملف');
    }
    if ($file['size'] > UPLOAD_MAX_BYTES || filesize($file['tmp_name']) > UPLOAD_MAX_BYTES) {
        json_error('حجم الملف كبير جداً (الحد الأقصى 10MB)');
    }

    $mime = detect_mime($file['tmp_name']);
    $allowed = $allowedTypes ?: array_keys(UPLOAD_IMAGE_MIMES);
    if (!isset(UPLOAD_IMAGE_MIMES[$mime]) || !in_array($mime, $allowed, true)) {
        json_error('نوع الملف غير مسموح. الصيغ المسموحة: JPG, PNG, GIF, WEBP');
    }

    $rel = store_image_file($file['tmp_name'], $targetDir);
    @unlink($file['tmp_name']);
    if ($rel === null) {
        json_error('الصورة غير صالحة أو تالفة أو أبعادها كبيرة جداً');
    }
    return $rel;
}

/**
 * Safely delete a previously stored upload. Only deletes files that resolve
 * inside UPLOADS_DIR, so DB-sourced paths can never escape the uploads folder.
 */
function delete_upload(?string $relPath): void {
    if (!$relPath) return;
    if (preg_match('~^https?://~i', $relPath)) return;
    $abs = realpath(ROOT_DIR . '/' . ltrim($relPath, '/\\'));
    $base = realpath(UPLOADS_DIR);
    if ($abs === false || $base === false) return;
    $abs = str_replace('\\', '/', $abs);
    $base = rtrim(str_replace('\\', '/', $base), '/') . '/';
    if (strpos($abs, $base) !== 0 || !is_file($abs)) return;
    if (basename($abs) === '.htaccess') return;
    @unlink($abs);
}

/** Client IP (REMOTE_ADDR only — forwarded headers are spoofable). */
function client_ip(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/**
 * Fixed-window rate limiter. Records a hit for $bucket and responds 429 if the
 * bucket exceeded $max hits within $windowSeconds.
 */
function rate_limit(string $bucket, int $max, int $windowSeconds, string $message = 'محاولات كثيرة جداً، يرجى المحاولة لاحقاً'): void {
    if (rate_limit_hit($bucket, $windowSeconds) > $max) {
        header('Retry-After: ' . $windowSeconds);
        json_error($message, 429);
    }
}

/** Record a hit and return the current hit count within the window. */
function rate_limit_hit(string $bucket, int $windowSeconds): int {
    $key = hash('sha256', $bucket);
    $now = time();
    return (int)DB::transaction(function (PDO $db) use ($key, $now, $windowSeconds) {
        $stmt = $db->prepare("SELECT hits, window_start FROM rate_limits WHERE bucket = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if (!$row || ($now - (int)$row['window_start']) >= $windowSeconds) {
            $db->prepare("INSERT OR REPLACE INTO rate_limits (bucket, hits, window_start) VALUES (?, 1, ?)")->execute([$key, $now]);
            // Opportunistic cleanup of stale buckets
            if (random_int(1, 50) === 1) {
                $db->prepare("DELETE FROM rate_limits WHERE window_start < ?")->execute([$now - 86400]);
            }
            return 1;
        }
        $db->prepare("UPDATE rate_limits SET hits = hits + 1 WHERE bucket = ?")->execute([$key]);
        return (int)$row['hits'] + 1;
    });
}

/** Clear a rate-limit bucket (e.g. after successful login). */
function rate_limit_clear(string $bucket): void {
    DB::get()->prepare("DELETE FROM rate_limits WHERE bucket = ?")->execute([hash('sha256', $bucket)]);
}

/**
 * Parse a money amount. Returns a float rounded to 2 decimals, or null if not a
 * finite number in (0, $max].
 */
function parse_money($value, float $max = 1000000.0): ?float {
    if (is_string($value)) $value = trim($value);
    if (!is_numeric($value)) return null;
    $f = (float)$value;
    if (!is_finite($f) || $f <= 0 || $f > $max) return null;
    $f = round($f, 2);
    return $f > 0 ? $f : null;
}

/** Round a money value to 2 decimals. */
function money_round($value): float {
    return round((float)$value, 2);
}

/**
 * Return $url if it is a safe link target (http/https, site-relative, hash route,
 * tel:, mailto:), otherwise ''. Blocks javascript:, data:, vbscript:, etc.
 */
function safe_url(?string $url): string {
    $url = trim((string)$url);
    if ($url === '') return '';
    if (preg_match('~[\x00-\x1F\x7F]~', $url)) return '';
    if (preg_match('~^(https?://|mailto:|tel:)~i', $url)) return $url;
    if (preg_match('~^(/(?!/)|#)~', $url)) return $url;   // "/path" or "#/route" (not protocol-relative "//")
    return '';
}

/** Escape text for Telegram parse_mode=HTML. */
function tg_esc($text): string {
    return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Require a specific HTTP method. */
function require_method(string $method): void {
    if (request_method() !== strtoupper($method)) {
        json_error('طريقة غير مسموحة', 405);
    }
}

/** Log an exception server-side and return a generic error to the client. */
function server_error(Throwable $e, string $message = 'حدث خطأ في الخادم، يرجى المحاولة لاحقاً'): void {
    error_log('[CStore] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_error($message, 500);
}

/**
 * Sanitize input
 */
function sanitize(string $input): string {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Get request method
 */
function request_method(): string {
    return strtoupper($_SERVER['REQUEST_METHOD']);
}

/**
 * Get JSON body from POST/PUT
 */
function json_body(): array {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($contentType, 'application/json') !== false) {
        $body = json_decode(file_get_contents('php://input'), true);
        return is_array($body) ? $body : [];
    }
    return $_POST;
}

/**
 * Require CSRF on mutations
 */
function require_csrf(): void {
    // Every method that is not a safe read must carry a valid token.
    if (!in_array(request_method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
        if (!csrf_verify()) {
            json_error('رمز الأمان غير صالح', 403);
        }
    }
}

/**
 * DataTables server-side response helper
 * 
 * Each column entry:
 *   'db' => SQL column/expression to select (also used for ORDER BY)
 *   'searchable' => bool (default true) — whether to include in LIKE search
 *   'select' => optional, SQL expression for SELECT (if different from ordering)
 *   'render' => optional callable(row) => string — transforms cell for display
 */
function datatable_response(PDO $db, string $table, array $columns, string $where = '1=1', array $params = [], array $joins = []): void {
    $draw = intval($_GET['draw'] ?? 1);
    $start = max(0, intval($_GET['start'] ?? 0));
    $length = max(1, min(100, intval($_GET['length'] ?? 10)));
    $search = $_GET['search'] ?? [];
    $searchValue = (is_array($search) && is_scalar($search['value'] ?? null)) ? trim((string)$search['value']) : '';
    $searchValue = mb_substr($searchValue, 0, 100);
    $order = $_GET['order'] ?? [];
    $order0 = (is_array($order) && is_array($order[0] ?? null)) ? $order[0] : [];
    $orderCol = intval(is_scalar($order0['column'] ?? null) ? $order0['column'] : 0);
    $orderDir = (($order0['dir'] ?? 'asc') === 'desc') ? 'DESC' : 'ASC';

    $joinStr = implode(' ', $joins);

    // Build select list — use 'select' key if present, else 'db'
    $selectParts = [];
    $orderExprs = [];
    foreach ($columns as $col) {
        $selectParts[] = $col['select'] ?? $col['db'];
        $orderExprs[] = $col['db'];
    }
    $orderColumn = $orderExprs[$orderCol] ?? $orderExprs[0];

    // Total records (unfiltered)
    $stmt = $db->prepare("SELECT COUNT(*) FROM {$table} {$joinStr} WHERE {$where}");
    $stmt->execute($params);
    $totalRecords = (int)$stmt->fetchColumn();

    // Filtered records
    $searchWhere = $where;
    $searchParams = $params;
    if ($searchValue !== '') {
        $searchClauses = [];
        foreach ($columns as $col) {
            if ($col['searchable'] ?? true) {
                $searchClauses[] = "{$col['db']} LIKE ?";
                $searchParams[] = "%{$searchValue}%";
            }
        }
        if ($searchClauses) {
            $searchWhere .= ' AND (' . implode(' OR ', $searchClauses) . ')';
        }
    }

    $stmt = $db->prepare("SELECT COUNT(*) FROM {$table} {$joinStr} WHERE {$searchWhere}");
    $stmt->execute($searchParams);
    $filteredRecords = (int)$stmt->fetchColumn();

    // Fetch data
    $selectCols = implode(', ', $selectParts);
    $sql = "SELECT {$selectCols} FROM {$table} {$joinStr} WHERE {$searchWhere} ORDER BY {$orderColumn} {$orderDir} LIMIT ? OFFSET ?";
    $searchParams[] = $length;
    $searchParams[] = $start;
    $stmt = $db->prepare($sql);
    $stmt->execute($searchParams);
    $rows = $stmt->fetchAll();

    // Transform to indexed arrays + apply render callbacks
    $data = [];
    foreach ($rows as $row) {
        $rowArr = [];
        foreach ($columns as $col) {
            if (isset($col['render']) && is_callable($col['render'])) {
                // Render callbacks return HTML and are responsible for escaping their own data.
                $rowArr[] = $col['render']($row);
            } else {
                // Get value by alias: prefer the 'select' expression (it defines the result key),
                // then 'db'. Key = part after " as ", else part after the last ".".
                $key = datatable_result_key($col['select'] ?? $col['db']);
                if (!array_key_exists($key, $row)) {
                    $key = datatable_result_key($col['db']);
                }
                $val = $row[$key] ?? '';
                // Plain cells are rendered as HTML by DataTables — always escape them.
                $rowArr[] = htmlspecialchars((string)$val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }
        $data[] = $rowArr;
    }

    json_response([
        'draw' => $draw,
        'recordsTotal' => $totalRecords,
        'recordsFiltered' => $filteredRecords,
        'data' => $data,
    ]);
}

/** Result-set key produced by a SELECT expression ("x AS y" => y, "t.col" => col). */
function datatable_result_key(string $expr): string {
    if (preg_match('/\s+as\s+([A-Za-z_][A-Za-z0-9_]*)\s*$/i', $expr, $m)) {
        return $m[1];
    }
    $expr = trim($expr);
    $dot = strrpos($expr, '.');
    return $dot !== false ? substr($expr, $dot + 1) : $expr;
}

/**
 * Format time ago in Arabic
 */
function time_ago_ar(string $datetime): string {
    $now = new DateTime();
    $then = new DateTime($datetime);
    $diff = $now->diff($then);

    if ($diff->y > 0) return "قبل {$diff->y} سنة";
    if ($diff->m > 0) return "قبل {$diff->m} شهر";
    if ($diff->d > 0) return "قبل {$diff->d} يوم";
    if ($diff->h > 0) return "قبل {$diff->h} ساعة";
    if ($diff->i > 0) return "قبل {$diff->i} دقيقة";
    return "الآن";
}

/**
 * Check if setup is needed (no admin exists)
 */
function needs_setup(): bool {
    try {
        $db = DB::get();
        $stmt = $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'");
        return $stmt->fetchColumn() == 0;
    } catch (Throwable $e) {
        // Fail closed: a DB error must never re-open the admin setup wizard.
        error_log('[CStore] needs_setup failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Fetch a single currency rate from JSON API URL
 * Returns the raw rate (before adjustment) or null on failure
 */
function fetch_currency_rate(string $url, string $jsonPath): ?float {
    if (!$url || !$jsonPath) return null;
    if (!preg_match('~^https?://~i', $url)) return null;
    $ctx = stream_context_create(['http' => [
        'timeout' => 5,
        'ignore_errors' => true,
        'follow_location' => 0,   // a validated public URL must not redirect to an internal host
        'max_redirects' => 0,
        'header' => "User-Agent: CStore/1.0\r\n",
    ]]);
    $response = @file_get_contents($url, false, $ctx);
    if ($response === false) return null;

    $json = @json_decode($response, true);
    if (!is_array($json)) return null;

    // Navigate dot-path (e.g. "rates.IQD" → $json['rates']['IQD'])
    $parts = explode('.', $jsonPath);
    $value = $json;
    foreach ($parts as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) return null;
        $value = $value[$part];
    }

    $rate = floatval($value);
    return $rate > 0 ? $rate : null;
}

/**
 * Refresh currency rates from API sources (lazy, 3-hour cache)
 * Uses file lock to prevent concurrent refreshes
 */
function refresh_currency_rates(): void {
    $db = DB::get();

    // Quick check: any stale API currencies?
    $staleStmt = $db->query("SELECT COUNT(*) FROM currencies WHERE rate_mode='api' AND rate_url != '' AND (rate_updated_at IS NULL OR rate_updated_at < datetime('now', '-3 hours'))");
    if ((int)$staleStmt->fetchColumn() === 0) return;

    // File lock to prevent concurrent refreshes
    $lockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cstore_rate_lock';
    $fp = @fopen($lockFile, 'w');
    if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
        if ($fp) fclose($fp);
        return; // Another process is refreshing
    }

    try {
        $stmt = $db->query("SELECT id, rate_url, rate_json_path, rate_adjustment FROM currencies WHERE rate_mode='api' AND rate_url != '' AND (rate_updated_at IS NULL OR rate_updated_at < datetime('now', '-3 hours'))");
        $currencies = $stmt->fetchAll();

        $updateStmt = $db->prepare("UPDATE currencies SET rate = ?, rate_updated_at = datetime('now') WHERE id = ?");

        foreach ($currencies as $cur) {
            $rawRate = fetch_currency_rate($cur['rate_url'], $cur['rate_json_path']);
            if ($rawRate === null) continue;

            $finalRate = max(0.0001, $rawRate + floatval($cur['rate_adjustment']));
            $updateStmt->execute([$finalRate, $cur['id']]);
        }
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
