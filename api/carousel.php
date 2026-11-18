<?php
require_once __DIR__ . '/../config.php';
$action = $_GET['action'] ?? '';
$db = DB::get();
release_session();

/** Validate title/link/sort_order from POST. json_error() on bad input. */
function carousel_input(): array {
    $title = is_scalar($_POST['title'] ?? '') ? trim((string)($_POST['title'] ?? '')) : '';
    if (mb_strlen($title) > 150) json_error('عنوان الشريحة طويل جداً (الحد الأقصى 150 حرف)');
    if (preg_match('~[\x00-\x1F\x7F]~', $title)) json_error('عنوان الشريحة يحتوي على رموز غير مسموحة');

    $rawLink = is_scalar($_POST['link'] ?? '') ? trim((string)($_POST['link'] ?? '')) : '';
    if (mb_strlen($rawLink) > 2000) json_error('الرابط طويل جداً');
    $link = safe_url($rawLink);
    if ($rawLink !== '' && $link === '') {
        json_error('الرابط غير صالح. استخدم رابطاً يبدأ بـ https:// أو http:// أو / أو #');
    }
    $sortOrder = max(-100000, min(100000, intval($_POST['sort_order'] ?? 0)));
    return [$title, $link, $sortOrder];
}

switch ($action) {
    case 'list':
        $stmt = $db->query("SELECT id, image, title, link, sort_order FROM carousel_slides WHERE is_active=1 ORDER BY sort_order ASC, id ASC");
        $slides = $stmt->fetchAll();
        // Defence in depth for rows saved before link validation existed.
        foreach ($slides as &$s) $s['link'] = safe_url($s['link'] ?? '');
        unset($s);
        json_success(['slides' => $slides]);
        break;

    case 'admin_list':
        require_admin();
        $stmt = $db->query("SELECT * FROM carousel_slides ORDER BY sort_order ASC, id ASC");
        json_success(['slides' => $stmt->fetchAll()]);
        break;

    case 'create':
        require_method('POST');
        require_csrf();
        require_admin();
        [$title, $link, $sortOrder] = carousel_input();
        $image = handle_upload('image', UPLOAD_CAROUSEL);
        if (!$image) json_error('صورة الشريحة مطلوبة');
        try {
            $stmt = $db->prepare("INSERT INTO carousel_slides (image, title, link, sort_order) VALUES (?,?,?,?)");
            $stmt->execute([$image, $title, $link, $sortOrder]);
        } catch (Throwable $e) {
            delete_upload($image);
            server_error($e);
        }
        json_success(['id' => (int)$db->lastInsertId()], 'تم إنشاء الشريحة');
        break;

    case 'update':
        require_method('POST');
        require_csrf();
        require_admin();
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) json_error('معرف الشريحة مطلوب');
        $stmt = $db->prepare("SELECT image FROM carousel_slides WHERE id=?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) json_error('الشريحة غير موجودة', 404);
        $oldImage = $row['image'];

        [$title, $link, $sortOrder] = carousel_input();
        $isActive = intval($_POST['is_active'] ?? 1) ? 1 : 0;
        $image = handle_upload('image', UPLOAD_CAROUSEL);
        try {
            if ($image) {
                $stmt = $db->prepare("UPDATE carousel_slides SET image=?, title=?, link=?, sort_order=?, is_active=? WHERE id=?");
                $stmt->execute([$image, $title, $link, $sortOrder, $isActive, $id]);
            } else {
                $stmt = $db->prepare("UPDATE carousel_slides SET title=?, link=?, sort_order=?, is_active=? WHERE id=?");
                $stmt->execute([$title, $link, $sortOrder, $isActive, $id]);
            }
        } catch (Throwable $e) {
            delete_upload($image);
            server_error($e);
        }
        if ($image && $oldImage && $oldImage !== $image) delete_upload($oldImage);
        json_success([], 'تم تحديث الشريحة');
        break;

    case 'delete':
        require_method('POST');
        require_csrf();
        require_admin();
        $data = json_body();
        $id = intval($data['id'] ?? 0);
        if ($id <= 0) json_error('معرف الشريحة مطلوب');
        try {
            $image = DB::transaction(function (PDO $db) use ($id) {
                $stmt = $db->prepare("SELECT image FROM carousel_slides WHERE id=?");
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                if (!$row) return false;
                $db->prepare("DELETE FROM carousel_slides WHERE id=?")->execute([$id]);
                return (string)$row['image'];
            });
        } catch (Throwable $e) {
            server_error($e);
        }
        if ($image === false) json_error('الشريحة غير موجودة', 404);
        delete_upload($image);
        json_success([], 'تم حذف الشريحة');
        break;

    default:
        json_error('إجراء غير معروف', 404);
}
