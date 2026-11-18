<?php
/**
 * CStore — Alerts API
 * GET  ?action=list          Public: active alerts only / Admin: all alerts
 * POST ?action=create        Admin only
 * POST ?action=update        Admin only
 * POST ?action=delete        Admin only
 */
require_once __DIR__ . '/../config.php';
$action = $_GET['action'] ?? '';
$db = DB::get();
release_session();

switch ($action) {
    case 'list':
        // If admin → return all, else return only active
        $isAdmin = false;
        if (!empty($_SESSION['user_id'])) {
            $stmt = $db->prepare("SELECT role FROM users WHERE id = ? AND is_active = 1");
            $stmt->execute([$_SESSION['user_id']]);
            $u = $stmt->fetch();
            if ($u && $u['role'] === 'admin') $isAdmin = true;
        }
        if ($isAdmin) {
            $stmt = $db->query("SELECT * FROM alerts ORDER BY sort_order ASC, id DESC");
            json_success(['alerts' => $stmt->fetchAll()]);
        }
        $stmt = $db->query("SELECT id, title, description, url, type FROM alerts WHERE status='active' ORDER BY sort_order ASC, id DESC");
        $alerts = $stmt->fetchAll();
        // Defence in depth for rows saved before URL validation existed.
        foreach ($alerts as &$a) $a['url'] = safe_url($a['url'] ?? '');
        unset($a);
        json_success(['alerts' => $alerts]);
        break;

    case 'create':
    case 'update':
        require_method('POST');
        require_csrf();
        require_admin();
        $data = json_body();
        $isUpdate = ($action === 'update');
        $id = $isUpdate ? intval($data['id'] ?? 0) : 0;
        if ($isUpdate && $id <= 0) json_error('معرف التنبيه مطلوب');

        $title = is_scalar($data['title'] ?? '') ? trim((string)($data['title'] ?? '')) : '';
        $description = is_scalar($data['description'] ?? '') ? trim((string)($data['description'] ?? '')) : '';
        $rawUrl = is_scalar($data['url'] ?? '') ? trim((string)($data['url'] ?? '')) : '';
        if ($title === '') json_error('عنوان التنبيه مطلوب');
        if (mb_strlen($title) > 150) json_error('عنوان التنبيه طويل جداً (الحد الأقصى 150 حرف)');
        // The storefront renders the title as HTML (SweetAlert "title") — no markup allowed.
        if (preg_match('~[\x00-\x1F\x7F<>]~', $title)) json_error('عنوان التنبيه يحتوي على رموز غير مسموحة');
        if (mb_strlen($description) > 2000) json_error('الوصف طويل جداً (الحد الأقصى 2000 حرف)');
        if (preg_match('~[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]~', $description)) json_error('الوصف يحتوي على رموز غير مسموحة');
        if (mb_strlen($rawUrl) > 2000) json_error('الرابط طويل جداً');
        $url = safe_url($rawUrl);
        if ($rawUrl !== '' && $url === '') {
            json_error('الرابط غير صالح. استخدم رابطاً يبدأ بـ https:// أو http:// أو / أو #');
        }
        $status = in_array($data['status'] ?? 'active', ['active', 'inactive'], true) ? $data['status'] : 'active';
        $type = in_array($data['type'] ?? 'one_time', ['one_time', 'always'], true) ? $data['type'] : 'one_time';
        $sortOrder = max(-100000, min(100000, intval($data['sort_order'] ?? 0)));

        if ($isUpdate) {
            $stmt = $db->prepare("UPDATE alerts SET title=?, description=?, status=?, url=?, type=?, sort_order=? WHERE id=?");
            $stmt->execute([$title, $description, $status, $url, $type, $sortOrder, $id]);
            if ($stmt->rowCount() === 0) json_error('التنبيه غير موجود', 404);
            json_success([], 'تم تحديث التنبيه');
        }
        $stmt = $db->prepare("INSERT INTO alerts (title, description, status, url, type, sort_order) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$title, $description, $status, $url, $type, $sortOrder]);
        json_success(['id' => (int)$db->lastInsertId()], 'تم إنشاء التنبيه');
        break;

    case 'delete':
        require_method('POST');
        require_csrf();
        require_admin();
        $data = json_body();
        $id = intval($data['id'] ?? 0);
        if ($id <= 0) json_error('معرف التنبيه مطلوب');
        $db->prepare("DELETE FROM alerts WHERE id=?")->execute([$id]);
        json_success([], 'تم حذف التنبيه');
        break;

    default:
        json_error('إجراء غير معروف', 404);
}
