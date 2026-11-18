<?php
/**
 * CStore — Currency API
 * Manages currencies with exchange rates (fixed or auto-fetched from JSON APIs)
 */
require_once __DIR__ . '/../config.php';

$action = $_GET['action'] ?? '';

/**
 * Validate a rate-source URL: http(s) only, and the host must resolve to a public IP
 * (limits SSRF against loopback / private / reserved ranges). Returns '' if invalid.
 * Note: DNS can change after validation; fetch_currency_rate() should also disable redirects.
 */
function cur_valid_rate_url(string $url): string {
    $url = trim($url);
    if ($url === '' || strlen($url) > 2000 || preg_match('~[\x00-\x20\x7F]~', $url)) return '';
    if (!preg_match('~^https?://~i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) return '';
    $parts = parse_url($url);
    if (!$parts || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) return '';
    $scheme = strtolower($parts['scheme'] ?? '');
    if ($scheme !== 'http' && $scheme !== 'https') return '';
    $host = trim($parts['host'], '[]');
    $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips = [$host];
    } else {
        $ips = @gethostbynamel($host) ?: [];
        if (!$ips) {
            $ip = gethostbyname($host);
            if ($ip !== $host) $ips = [$ip];
        }
    }
    if (!$ips) return '';
    foreach ($ips as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) return '';
    }
    return $url;
}

/** Validate a dot-notation JSON path like "rates.IQD". */
function cur_valid_json_path(string $path): bool {
    return (bool)preg_match('~^[A-Za-z0-9_\-]+(\.[A-Za-z0-9_\-]+){0,9}$~', $path);
}

/** Parse a finite float within [$min, $max]; null if invalid. */
function cur_float($value, float $min, float $max): ?float {
    if (is_string($value)) $value = trim($value);
    if ($value === '' || $value === null) return null;
    if (!is_numeric($value)) return null;
    $f = (float)$value;
    if (!is_finite($f) || $f < $min || $f > $max) return null;
    return $f;
}

/** Validate a short single-line text field. */
function cur_text($value, int $max, string $label, bool $required): string {
    $s = is_scalar($value) ? trim((string)$value) : '';
    if ($required && $s === '') json_error("{$label} مطلوب");
    if (mb_strlen($s) > $max) json_error("{$label} طويل جداً (الحد الأقصى {$max} حرف)");
    if (preg_match('~[\x00-\x1F\x7F<>]~', $s)) json_error("{$label} يحتوي على رموز غير مسموحة");
    return $s;
}

/** Shared validation for create/update. Returns normalized input array. */
function cur_input(bool $isUpdate): array {
    $in = [];
    $in['name'] = cur_text($_POST['name'] ?? '', 50, 'اسم العملة', true);
    $in['symbol'] = cur_text($_POST['symbol'] ?? '', 10, 'رمز العرض', false);
    $in['sort_order'] = max(-100000, min(100000, intval($_POST['sort_order'] ?? 0)));
    $in['is_active'] = $isUpdate ? (intval($_POST['is_active'] ?? 1) ? 1 : 0) : 1;
    $mode = $_POST['rate_mode'] ?? 'fixed';
    $in['rate_mode'] = in_array($mode, ['fixed', 'api'], true) ? $mode : 'fixed';
    $in['rate_url'] = is_scalar($_POST['rate_url'] ?? '') ? trim((string)($_POST['rate_url'] ?? '')) : '';
    $in['rate_json_path'] = is_scalar($_POST['rate_json_path'] ?? '') ? trim((string)($_POST['rate_json_path'] ?? '')) : '';
    $adj = cur_float($_POST['rate_adjustment'] ?? 0, -1000000, 1000000);
    if ($adj === null) {
        if (trim((string)($_POST['rate_adjustment'] ?? '')) !== '') json_error('قيمة تعديل السعر غير صالحة');
        $adj = 0.0;
    }
    $in['rate_adjustment'] = $adj;
    // Rate input is disabled (not submitted) in API mode.
    $in['rate'] = cur_float($_POST['rate'] ?? null, 0.0, 1000000000);
    return $in;
}

/** Validate API-mode / fixed-mode specifics. */
function cur_validate_mode(array &$in): void {
    if ($in['rate_mode'] === 'api') {
        $in['rate_url'] = cur_valid_rate_url($in['rate_url']);
        if ($in['rate_url'] === '') json_error('رابط API غير صالح (يجب أن يكون http(s) ويشير إلى عنوان عام)');
        if (!cur_valid_json_path($in['rate_json_path'])) json_error('مسار JSON غير صالح');
    } else {
        if ($in['rate'] === null || $in['rate'] <= 0) json_error('سعر الصرف يجب أن يكون رقماً أكبر من صفر');
        // A non-API currency keeps no outbound URL
        $in['rate_url'] = '';
        $in['rate_json_path'] = '';
    }
}

switch ($action) {
    case 'list':
        // Public: returns all active currencies (refresh stale API rates first)
        release_session();
        refresh_currency_rates();
        $db = DB::get();
        // Explicit columns: never expose rate_url / rate_json_path / rate_adjustment publicly.
        $stmt = $db->query("SELECT id, code, name, symbol, rate, sort_order FROM currencies WHERE is_active=1 ORDER BY sort_order ASC, id ASC");
        json_response(['currencies' => $stmt->fetchAll()]);
        break;

    case 'admin_list':
        require_admin();
        release_session();
        $db = DB::get();
        $stmt = $db->query("SELECT * FROM currencies ORDER BY sort_order ASC, id ASC");
        json_response(['currencies' => $stmt->fetchAll()]);
        break;

    case 'create':
        require_method('POST');
        require_csrf();
        require_admin();
        release_session();
        $db = DB::get();
        $code = strtoupper(is_scalar($_POST['code'] ?? '') ? trim((string)($_POST['code'] ?? '')) : '');
        if ($code === '') json_error('الرمز والاسم مطلوبان');
        if (!preg_match('~^[A-Z0-9]{2,5}$~', $code)) json_error('رمز العملة يجب أن يكون 2-5 أحرف/أرقام لاتينية');
        $in = cur_input(false);
        if ($in['rate_mode'] === 'api' && $in['rate'] === null) $in['rate'] = 1.0; // placeholder until first fetch
        cur_validate_mode($in);

        try {
            $newId = DB::transaction(function (PDO $db) use ($code, $in) {
                $existing = $db->prepare("SELECT id FROM currencies WHERE code=?");
                $existing->execute([$code]);
                if ($existing->fetch()) return 0;
                $stmt = $db->prepare("INSERT INTO currencies (code, name, symbol, rate, sort_order, rate_mode, rate_url, rate_json_path, rate_adjustment) VALUES (?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$code, $in['name'], $in['symbol'], $in['rate'] ?: 1.0, $in['sort_order'], $in['rate_mode'], $in['rate_url'], $in['rate_json_path'], $in['rate_adjustment']]);
                return (int)$db->lastInsertId();
            });
        } catch (Throwable $e) {
            server_error($e);
        }
        if (!$newId) json_error('رمز العملة موجود بالفعل');

        // Trigger immediate fetch for API currencies
        if ($in['rate_mode'] === 'api') {
            $rawRate = fetch_currency_rate($in['rate_url'], $in['rate_json_path']);
            if ($rawRate !== null && is_finite($rawRate)) {
                $finalRate = max(0.0001, $rawRate + $in['rate_adjustment']);
                $db->prepare("UPDATE currencies SET rate = ?, rate_updated_at = datetime('now') WHERE id = ?")->execute([$finalRate, $newId]);
            }
        }

        json_response(['success' => true, 'id' => $newId]);
        break;

    case 'update':
        require_method('POST');
        require_csrf();
        require_admin();
        release_session();
        $db = DB::get();
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) json_error('بيانات غير صالحة');
        $in = cur_input(true);

        $stmt = $db->prepare("SELECT code, rate FROM currencies WHERE id=?");
        $stmt->execute([$id]);
        $currency = $stmt->fetch();
        if (!$currency) json_error('العملة غير موجودة', 404);

        // Don't allow deactivating or changing rate of USD
        if ($currency['code'] === 'USD') {
            $in['rate'] = 1.0;
            $in['is_active'] = 1;
            $in['rate_mode'] = 'fixed'; // USD always fixed
        }
        // API mode: the rate field is not submitted — keep the last known rate.
        if ($in['rate_mode'] === 'api') $in['rate'] = (float)$currency['rate'];
        cur_validate_mode($in);

        try {
            $stmt = $db->prepare("UPDATE currencies SET name=?, symbol=?, rate=?, sort_order=?, is_active=?, rate_mode=?, rate_url=?, rate_json_path=?, rate_adjustment=? WHERE id=?");
            $stmt->execute([$in['name'], $in['symbol'], $in['rate'], $in['sort_order'], $in['is_active'], $in['rate_mode'], $in['rate_url'], $in['rate_json_path'], $in['rate_adjustment'], $id]);
        } catch (Throwable $e) {
            server_error($e);
        }

        // Trigger immediate fetch for API currencies
        if ($in['rate_mode'] === 'api') {
            $rawRate = fetch_currency_rate($in['rate_url'], $in['rate_json_path']);
            if ($rawRate !== null && is_finite($rawRate)) {
                $finalRate = max(0.0001, $rawRate + $in['rate_adjustment']);
                $db->prepare("UPDATE currencies SET rate = ?, rate_updated_at = datetime('now') WHERE id = ?")->execute([$finalRate, $id]);
            }
        }

        json_response(['success' => true]);
        break;

    case 'test_rate':
        require_method('POST');
        require_csrf();
        require_admin();
        release_session();
        rate_limit('currency_test:' . client_ip(), 30, 600);
        $data = json_body();
        $url = cur_valid_rate_url(is_scalar($data['url'] ?? '') ? (string)($data['url'] ?? '') : '');
        $jsonPath = is_scalar($data['json_path'] ?? '') ? trim((string)($data['json_path'] ?? '')) : '';
        $adjustment = cur_float($data['adjustment'] ?? 0, -1000000, 1000000) ?? 0.0;

        if ($url === '') json_error('رابط API غير صالح (يجب أن يكون http(s) ويشير إلى عنوان عام)');
        if (!cur_valid_json_path($jsonPath)) json_error('مسار JSON غير صالح');

        $rawRate = fetch_currency_rate($url, $jsonPath);
        if ($rawRate === null || !is_finite($rawRate)) {
            json_error('فشل في جلب السعر — تحقق من الرابط ومسار JSON');
        }

        $finalRate = max(0.0001, $rawRate + $adjustment);
        json_response(['success' => true, 'raw_rate' => $rawRate, 'adjustment' => $adjustment, 'final_rate' => $finalRate]);
        break;

    case 'delete':
        require_method('POST');
        require_csrf();
        require_admin();
        release_session();
        $db = DB::get();
        // admin.js sends JSON ({id}); json_body() also falls back to form posts.
        $data = json_body();
        $id = intval($data['id'] ?? 0);

        // Don't allow deleting USD
        $stmt = $db->prepare("SELECT code FROM currencies WHERE id=?");
        $stmt->execute([$id]);
        $currency = $stmt->fetch();
        if (!$currency) json_error('العملة غير موجودة', 404);
        if ($currency['code'] === 'USD') json_error('لا يمكن حذف العملة الأساسية (USD)');

        $db->prepare("DELETE FROM currencies WHERE id=?")->execute([$id]);
        json_response(['success' => true]);
        break;

    default:
        json_error('إجراء غير معروف', 400);
}
