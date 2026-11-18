<?php
/**
 * CStore — Private file server
 *
 * Private uploads (KYC documents, payment proofs, order field images, delivered
 * goods, stock) are never served directly. uploads/.htaccess rewrites
 *   /uploads/<dir>/<file>  →  /api/file.php?d=<dir>&f=<file>
 * and this script only streams the file to an admin or to the user whose own
 * records reference it.
 */
require_once __DIR__ . '/../config.php';

$user = require_auth();
release_session();

$dir  = is_string($_GET['d'] ?? null) ? $_GET['d'] : '';
$file = is_string($_GET['f'] ?? null) ? $_GET['f'] : '';

// Names: new ones are "<hex>.<ext>", legacy ones are uniqid('', true) style "69bb77e8a8b689.00203135.jpg".
// Every dot-separated part must be non-empty, so ".." can never appear.
if (!in_array($dir, PRIVATE_UPLOAD_DIRS, true) || !preg_match('/^[A-Za-z0-9_\-]+(?:\.[A-Za-z0-9_\-]+){0,3}$/D', $file)) {
    http_response_code(404);
    exit;
}

$base = realpath(UPLOADS_DIR . '/' . $dir);
$abs  = realpath(UPLOADS_DIR . '/' . $dir . '/' . $file);
if ($base === false || $abs === false || !is_file($abs)
    || strpos(str_replace('\\', '/', $abs), rtrim(str_replace('\\', '/', $base), '/') . '/') !== 0) {
    http_response_code(404);
    exit;
}

$rel = 'uploads/' . $dir . '/' . $file;

/** Does the current (non-admin) user own a record that references $rel? */
function private_file_owned(PDO $db, string $dir, string $rel, int $uid): bool {
    $q = function (string $sql, array $params) use ($db): bool {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return (bool)$stmt->fetchColumn();
    };
    switch ($dir) {
        case 'kyc':
            return $q("SELECT 1 FROM kyc_applications WHERE user_id = ? AND (front_image = ? OR back_image = ? OR selfie_image = ?) LIMIT 1",
                [$uid, $rel, $rel, $rel]);
        case 'proofs':
            return $q("SELECT 1 FROM topup_requests WHERE user_id = ? AND proof_image = ? LIMIT 1", [$uid, $rel])
                || $q("SELECT 1 FROM order_reports WHERE user_id = ? AND screenshot = ? LIMIT 1", [$uid, $rel])
                || $q("SELECT 1 FROM report_messages rm JOIN order_reports r ON r.id = rm.report_id WHERE r.user_id = ? AND rm.image = ? LIMIT 1", [$uid, $rel]);
        case 'fields':
            // Only image-type fields hold server-generated paths; text fields are user-typed and must not grant access.
            return $q("SELECT 1 FROM order_field_values v JOIN orders o ON o.id = v.order_id JOIN item_fields f ON f.id = v.field_id
                       WHERE o.user_id = ? AND f.type = 'image' AND v.value = ? LIMIT 1", [$uid, $rel]);
        case 'deliveries':
        case 'stock':
            // Text deliveries hold free text (codes etc.), never a server path — they must not grant access.
            return $q("SELECT 1 FROM order_deliveries d JOIN orders o ON o.id = d.order_id WHERE o.user_id = ? AND d.type != 'text' AND d.content = ? LIMIT 1", [$uid, $rel])
                || $q("SELECT 1 FROM item_stock s JOIN orders o ON o.id = s.sold_to_order_id WHERE s.is_sold = 1 AND o.user_id = ? AND s.image_path = ? LIMIT 1", [$uid, $rel]);
    }
    return false;
}

if (($user['role'] ?? '') !== 'admin') {
    if (!private_file_owned(DB::get(), $dir, $rel, (int)$user['id'])) {
        http_response_code(403);
        exit;
    }
}

$mime = detect_mime($abs);
$isImage = isset(UPLOAD_IMAGE_MIMES[$mime]);
$isKnown = $isImage || isset(UPLOAD_DELIVERY_MIMES[$mime]);
if (!$isKnown) {
    $mime = 'application/octet-stream';
}

$isMedia = strpos($mime, 'video/') === 0 || strpos($mime, 'audio/') === 0;
$size = (int)filesize($abs);

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; media-src 'self'; sandbox");
header('Cache-Control: private, no-store, max-age=0');
header('Content-Disposition: ' . (($isImage || $isMedia) ? 'inline' : 'attachment') . '; filename="' . $file . '"');
header('Cross-Origin-Resource-Policy: same-origin');
header('Accept-Ranges: bytes');

// Single byte-range support (Safari/iOS need it to play and seek <video>/<audio>).
$start = 0;
$end = $size - 1;
$range = $_SERVER['HTTP_RANGE'] ?? '';
if ($size > 0 && is_string($range) && preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m) && ($m[1] !== '' || $m[2] !== '')) {
    if ($m[1] === '') {                       // suffix range: last N bytes
        $start = max(0, $size - (int)$m[2]);
    } else {
        $start = (int)$m[1];
        if ($m[2] !== '') $end = min((int)$m[2], $size - 1);
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header("Content-Range: bytes */$size");
        exit;
    }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}

$length = $end - $start + 1;
header('Content-Length: ' . max(0, $length));
if (request_method() === 'HEAD' || $size === 0) exit;

$fp = fopen($abs, 'rb');
if ($fp === false) exit;
fseek($fp, $start);
$remaining = $length;
while ($remaining > 0 && !feof($fp)) {
    $chunk = fread($fp, (int)min(65536, $remaining));
    if ($chunk === false || $chunk === '') break;
    echo $chunk;
    $remaining -= strlen($chunk);
    flush();
}
fclose($fp);
