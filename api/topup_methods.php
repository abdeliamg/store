<?php
require_once __DIR__ . '/../config.php';
$action = $_GET['action'] ?? '';
$db = DB::get();

/** Validate name/instructions/sort_order/require_kyc from POST. json_error() on bad input. */
function topup_method_input(): array {
    $name = is_scalar($_POST['name'] ?? '') ? trim((string)($_POST['name'] ?? '')) : '';
    if ($name === '') json_error('اسم الطريقة مطلوب');
    if (mb_strlen($name) > 100) json_error('اسم الطريقة طويل جداً (الحد الأقصى 100 حرف)');
    if (preg_match('~[\x00-\x1F\x7F]~', $name)) json_error('اسم الطريقة يحتوي على رموز غير مسموحة');

    $instructions = is_scalar($_POST['instructions'] ?? '') ? trim((string)($_POST['instructions'] ?? '')) : '';
    if (mb_strlen($instructions) > 5000) json_error('التعليمات طويلة جداً (الحد الأقصى 5000 حرف)');
    if (preg_match('~[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]~', $instructions)) json_error('التعليمات تحتوي على رموز غير مسموحة');

    $sortOrder = max(-100000, min(100000, intval($_POST['sort_order'] ?? 0)));
    $requireKyc = intval($_POST['require_kyc'] ?? 0) ? 1 : 0;
    return [$name, $instructions, $sortOrder, $requireKyc];
}

switch ($action) {
    case 'list':
        // Public endpoint — return all methods + KYC status for UI gating
        $currentUser = auth_user();
        release_session();

        $userKycApproved = false;
        if ($currentUser) {
            $kycStatus = get_user_kyc_status((int)$currentUser['id']);
            $userKycApproved = ($kycStatus === 'approved');
        }

        $stmt = $db->query("SELECT id, name, instructions, image, require_kyc FROM topup_methods WHERE is_active=1 ORDER BY sort_order ASC, id ASC");
        json_success(['methods' => $stmt->fetchAll(), 'user_kyc_approved' => $userKycApproved]);
        break;

    case 'admin_list':
        require_admin();
        release_session();
        $stmt = $db->query("SELECT * FROM topup_methods ORDER BY sort_order ASC, id ASC");
        json_success(['methods' => $stmt->fetchAll()]);
        break;

    case 'create':
        require_method('POST');
        require_csrf();
        require_admin();
        release_session();
        [$name, $instructions, $sortOrder, $requireKyc] = topup_method_input();
        $image = handle_upload('image', UPLOAD_PRODUCTS);
        try {
            $stmt = $db->prepare("INSERT INTO topup_methods (name, instructions, image, sort_order, require_kyc) VALUES (?,?,?,?,?)");
            $stmt->execute([$name, $instructions, $image, $sortOrder, $requireKyc]);
        } catch (Throwable $e) {
            delete_upload($image);
            server_error($e);
        }
        json_success(['id' => (int)$db->lastInsertId()], 'تم إنشاء طريقة الشحن');
        break;

    case 'update':
        require_method('POST');
        require_csrf();
        require_admin();
        release_session();
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) json_error('معرف الطريقة مطلوب');
        $stmt = $db->prepare("SELECT image FROM topup_methods WHERE id=?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) json_error('طريقة الشحن غير موجودة', 404);
        $oldImage = $row['image'];

        [$name, $instructions, $sortOrder, $requireKyc] = topup_method_input();
        $isActive = intval($_POST['is_active'] ?? 1) ? 1 : 0;
        $image = handle_upload('image', UPLOAD_PRODUCTS);
        try {
            if ($image) {
                $stmt = $db->prepare("UPDATE topup_methods SET name=?, instructions=?, image=?, sort_order=?, is_active=?, require_kyc=? WHERE id=?");
                $stmt->execute([$name, $instructions, $image, $sortOrder, $isActive, $requireKyc, $id]);
            } else {
                $stmt = $db->prepare("UPDATE topup_methods SET name=?, instructions=?, sort_order=?, is_active=?, require_kyc=? WHERE id=?");
                $stmt->execute([$name, $instructions, $sortOrder, $isActive, $requireKyc, $id]);
            }
        } catch (Throwable $e) {
            delete_upload($image);
            server_error($e);
        }
        if ($image && $oldImage && $oldImage !== $image) delete_upload($oldImage);
        json_success([], 'تم تحديث طريقة الشحن');
        break;

    case 'delete':
        require_method('POST');
        require_csrf();
        require_admin();
        release_session();
        $data = json_body();
        $id = intval($data['id'] ?? 0);
        if ($id <= 0) json_error('معرف الطريقة مطلوب');
        try {
            $result = DB::transaction(function (PDO $db) use ($id) {
                $stmt = $db->prepare("SELECT image FROM topup_methods WHERE id=?");
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                if (!$row) return 'missing';
                // topup_requests.method_id references this row (no cascade): keep history, just disable.
                $stmt = $db->prepare("SELECT 1 FROM topup_requests WHERE method_id=? LIMIT 1");
                $stmt->execute([$id]);
                if ($stmt->fetchColumn()) {
                    $db->prepare("UPDATE topup_methods SET is_active=0 WHERE id=?")->execute([$id]);
                    return 'deactivated';
                }
                $db->prepare("DELETE FROM topup_methods WHERE id=?")->execute([$id]);
                return ['image' => $row['image']];
            });
        } catch (Throwable $e) {
            server_error($e);
        }
        if ($result === 'missing') json_error('طريقة الشحن غير موجودة', 404);
        if ($result === 'deactivated') {
            json_success(['deactivated' => true], 'طريقة الشحن مرتبطة بطلبات شحن سابقة، تم تعطيلها بدلاً من حذفها');
        }
        delete_upload($result['image']);
        json_success([], 'تم حذف طريقة الشحن');
        break;

    default:
        json_error('إجراء غير معروف', 404);
}
