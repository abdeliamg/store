<?php
require_once __DIR__ . '/../config.php';
$action = $_GET['action'] ?? '';
$db = DB::get();
release_session();

/** Thrown inside DB::transaction() to abort with a user-facing (Arabic) message. */
class ItemInputError extends RuntimeException {}

switch ($action) {
    case 'get':
        $id = intval($_GET['id'] ?? 0);
        // Fresh (uncached) role check: the admin branch exposes supplier api_url values.
        $viewer = auth_user_fresh();
        $isAdmin = $viewer && ($viewer['role'] ?? '') === 'admin';

        if ($isAdmin) {
            // Admin edit modal needs the full row (incl. api_url) and inactive items too.
            $stmt = $db->prepare("SELECT * FROM items WHERE id=?");
        } else {
            // Public: explicit column list — NEVER api_url (contains supplier tokens).
            $stmt = $db->prepare("SELECT id, category_id, name, description, price, image, cancel_window_seconds, contact_only, is_active, sort_order, delivery_type, pricing_type, calc_field_id, calc_operator, calc_value FROM items WHERE id=? AND is_active=1");
        }
        $stmt->execute([$id]);
        $item = $stmt->fetch();
        if (!$item) json_error('المنتج غير موجود', 404);

        $stmtF = $db->prepare("SELECT id, item_id, label, type, is_required, sort_order, prefix, suffix FROM item_fields WHERE item_id=? AND " . items_active_fields_cond($db) . " ORDER BY sort_order ASC, id ASC");
        $stmtF->execute([$id]);
        $fields = $stmtF->fetchAll();
        $contactPhone = DB::setting('contact_phone');

        // Stock count for auto_stock items (exclude expired)
        $stockCount = 0;
        if ($item['delivery_type'] === 'auto_stock') {
            $stmtS = $db->prepare("SELECT COUNT(*) FROM item_stock WHERE item_id=? AND is_sold=0 AND (expires_at IS NULL OR expires_at > datetime('now'))");
            $stmtS->execute([$id]);
            $stockCount = (int)$stmtS->fetchColumn();
        }
        // Get category info for breadcrumb (including parent)
        $catName = '';
        $catParentId = 0;
        $catParentName = '';
        if ($item['category_id']) {
            $stmtC = $db->prepare("SELECT name, parent_id FROM categories WHERE id=?");
            $stmtC->execute([$item['category_id']]);
            $catRow = $stmtC->fetch();
            if ($catRow) {
                $catName = $catRow['name'];
                if (!empty($catRow['parent_id'])) {
                    $stmtP = $db->prepare("SELECT id, name FROM categories WHERE id=?");
                    $stmtP->execute([$catRow['parent_id']]);
                    $parentRow = $stmtP->fetch();
                    if ($parentRow) {
                        $catParentId = (int)$parentRow['id'];
                        $catParentName = $parentRow['name'];
                    }
                }
            }
        }

        // Price options for 'options' pricing type
        $priceOptions = [];
        if ($item['pricing_type'] === 'options') {
            $cols = $isAdmin ? '*' : 'id, item_id, label, price, sort_order';   // never leak per-option api_url
            $stmtPO = $db->prepare("SELECT {$cols} FROM item_price_options WHERE item_id=? ORDER BY sort_order ASC, id ASC");
            $stmtPO->execute([$id]);
            $priceOptions = $stmtPO->fetchAll();
        }

        json_success(['item' => $item, 'fields' => $fields, 'contact_phone' => $contactPhone, 'stock_count' => $stockCount, 'category_name' => $catName, 'category_parent_id' => $catParentId, 'category_parent_name' => $catParentName, 'price_options' => $priceOptions]);
        break;

    case 'list':
        require_admin();
        if (isset($_GET['draw'])) {
            datatable_response($db, 'items', [
                ['db' => 'items.id', 'searchable' => false],
                ['db' => 'items.image', 'searchable' => false, 'render' => fn($r) => $r['image'] ? '<img src="/' . htmlspecialchars($r['image'], ENT_QUOTES) . '" style="width:50px;height:40px;object-fit:cover;border-radius:6px;">' : '-'],
                ['db' => 'items.name'],
                ['db' => 'categories.name', 'select' => 'categories.name as category_name'],
                ['db' => 'items.price', 'searchable' => false, 'render' => fn($r) => $r['contact_only'] ? '<span class="contact-only-badge">📞 تواصل</span>' : '$' . number_format((float)$r['price'], 2)],
                ['db' => 'items.delivery_type', 'searchable' => false, 'render' => function($r) {
                    $types = ['manual' => ['يدوي', 'pending'], 'auto_stock' => ['مخزون', 'accepted'], 'api_json' => ['API', 'delivering']];
                    $t = $types[$r['delivery_type']] ?? ['يدوي', 'pending'];
                    return '<span class="badge badge-' . $t[1] . '">' . $t[0] . '</span>';
                }],
                ['db' => 'items.is_active', 'searchable' => false, 'render' => fn($r) => $r['is_active'] ? '<span class="badge badge-accepted">مفعّل</span>' : '<span class="badge badge-canceled">معطّل</span>'],
                ['db' => 'items.id', 'select' => 'items.id as action_id, items.contact_only, items.delivery_type as action_dtype', 'searchable' => false, 'render' => fn($r) =>
                    '<button class="btn btn-primary btn-sm edit-item" data-id="' . (int)$r['action_id'] . '">✏️</button> ' .
                    ($r['action_dtype'] === 'auto_stock' ? '<button class="btn btn-outline btn-sm stock-item" data-id="' . (int)$r['action_id'] . '">📦</button> ' : '') .
                    '<button class="btn btn-danger btn-sm del-item" data-id="' . (int)$r['action_id'] . '">🗑</button>'
                ],
            ], '1=1', [], [
                'LEFT JOIN categories ON items.category_id=categories.id',
            ]);
        }
        $stmt = $db->query("SELECT items.*, categories.name as category_name FROM items LEFT JOIN categories ON items.category_id=categories.id ORDER BY items.sort_order ASC");
        json_success(['items' => $stmt->fetchAll()]);
        break;

    case 'create':
    case 'update':
        require_method('POST');
        require_csrf();
        require_admin();
        $isUpdate = ($action === 'update');

        $id = $isUpdate ? intval($_POST['id'] ?? 0) : 0;
        $oldImage = null;
        if ($isUpdate) {
            if ($id <= 0) json_error('معرف المنتج مطلوب');
            $stmt = $db->prepare("SELECT image FROM items WHERE id=?");
            $stmt->execute([$id]);
            $existing = $stmt->fetch();
            if (!$existing) json_error('المنتج غير موجود', 404);
            $oldImage = $existing['image'];
        }

        $in = items_parse_input($db, $isUpdate);

        // Custom fields: create defaults to "[]"; update only touches fields when the param is sent.
        $fieldsRaw = $_POST['fields'] ?? ($isUpdate ? '' : '[]');
        $fields = null;
        if (is_string($fieldsRaw) && $fieldsRaw !== '') {
            $fields = items_normalize_fields($fieldsRaw);
        }
        $priceOptions = [];
        if ($in['pricing_type'] === 'options') {
            $priceOptions = items_normalize_price_options((string)($_POST['price_options'] ?? '[]'));
            if (!$priceOptions) json_error('يجب إضافة خيار سعر واحد على الأقل');
        }

        // Upload last (after validation). Remove the new file if anything below aborts.
        $newImage = handle_upload('image', UPLOAD_PRODUCTS);
        $committed = false;
        register_shutdown_function(function () use (&$committed, &$newImage) {
            if (!$committed && $newImage) delete_upload($newImage);
        });

        try {
            $itemId = DB::transaction(function (PDO $db) use ($isUpdate, $id, $in, $fields, $priceOptions, $newImage) {
                $vals = [
                    $in['category_id'], $in['name'], $in['description'], $in['price'], $in['cancel_window_seconds'],
                    $in['contact_only'], $in['sort_order'], $in['delivery_type'], $in['api_url'], $in['pricing_type'],
                    $in['calc_operator'], $in['calc_value'],
                ];
                if ($isUpdate) {
                    $sql = "UPDATE items SET category_id=?, name=?, description=?, price=?, cancel_window_seconds=?, contact_only=?, sort_order=?, delivery_type=?, api_url=?, pricing_type=?, calc_operator=?, calc_value=?, is_active=?";
                    $vals[] = $in['is_active'];
                    if ($newImage) { $sql .= ", image=?"; $vals[] = $newImage; }
                    $sql .= " WHERE id=?";
                    $vals[] = $id;
                    $db->prepare($sql)->execute($vals);
                    $itemId = $id;
                } else {
                    $vals[] = $newImage;
                    $db->prepare("INSERT INTO items (category_id, name, description, price, cancel_window_seconds, contact_only, sort_order, delivery_type, api_url, pricing_type, calc_operator, calc_value, image) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute($vals);
                    $itemId = (int)$db->lastInsertId();
                }

                // Fields: update in place / insert / retire. Returns payload index => field id.
                $posMap = [];
                if ($fields !== null) {
                    $posMap = saveItemFields($db, $itemId, $fields);
                } else {
                    $cur = $db->prepare("SELECT id FROM item_fields WHERE item_id=? AND " . items_active_fields_cond($db) . " ORDER BY sort_order ASC, id ASC");
                    $cur->execute([$itemId]);
                    $posMap = array_map('intval', $cur->fetchAll(PDO::FETCH_COLUMN));
                }

                // Resolve calc field (position-based "_pos_N" refers to the submitted field index)
                $calcFieldId = null;
                if ($in['pricing_type'] === 'calculated') {
                    if ($in['calc_field_pos'] !== null) {
                        $calcFieldId = $posMap[$in['calc_field_pos']] ?? null;
                    } elseif ($in['calc_field_id']) {
                        $chk = $db->prepare("SELECT id FROM item_fields WHERE id=? AND item_id=? AND " . items_active_fields_cond($db));
                        $chk->execute([$in['calc_field_id'], $itemId]);
                        $calcFieldId = $chk->fetchColumn() ? $in['calc_field_id'] : null;
                    }
                    if (!$calcFieldId) throw new ItemInputError('يجب اختيار الحقل المرتبط بالتسعير المحسوب');
                }
                $db->prepare("UPDATE items SET calc_field_id=? WHERE id=?")->execute([$calcFieldId, $itemId]);

                if ($in['pricing_type'] === 'options') {
                    $minPrice = saveItemPriceOptions($db, $itemId, $priceOptions);
                    $db->prepare("UPDATE items SET price=? WHERE id=?")->execute([$minPrice, $itemId]);
                } else {
                    // Clean up price options if switching away from options type
                    $db->prepare("DELETE FROM item_price_options WHERE item_id=?")->execute([$itemId]);
                }
                return $itemId;
            });
        } catch (ItemInputError $e) {
            json_error($e->getMessage());
        } catch (Throwable $e) {
            server_error($e);
        }

        $committed = true;
        // Replace succeeded: remove the previous image file.
        if ($isUpdate && $newImage && $oldImage && $oldImage !== $newImage) {
            delete_upload($oldImage);
        }
        if ($isUpdate) json_success([], 'تم تحديث المنتج');
        json_success(['id' => $itemId], 'تم إنشاء المنتج');
        break;

    case 'delete':
        require_method('POST');
        require_csrf();
        require_admin();
        $data = json_body();
        $id = intval($data['id'] ?? 0);
        if (!$id) json_error('معرف المنتج مطلوب');
        try {
            $result = DB::transaction(function (PDO $db) use ($id) {
                $stmt = $db->prepare("SELECT image FROM items WHERE id=?");
                $stmt->execute([$id]);
                $item = $stmt->fetch();
                if (!$item) return null;

                // Items with orders cannot be hard-deleted without destroying order history.
                $stmt = $db->prepare("SELECT 1 FROM orders WHERE item_id=? LIMIT 1");
                $stmt->execute([$id]);
                if ($stmt->fetchColumn()) {
                    $db->prepare("UPDATE items SET is_active=0 WHERE id=?")->execute([$id]);
                    return ['soft' => true];
                }

                $stmt = $db->prepare("SELECT image_path FROM item_stock WHERE item_id=? AND image_path IS NOT NULL AND image_path != ''");
                $stmt->execute([$id]);
                $stockImages = $stmt->fetchAll(PDO::FETCH_COLUMN);

                $db->prepare("DELETE FROM item_fields WHERE item_id=?")->execute([$id]);
                $db->prepare("DELETE FROM item_stock WHERE item_id=?")->execute([$id]);
                $db->prepare("DELETE FROM item_price_options WHERE item_id=?")->execute([$id]);
                $db->prepare("DELETE FROM items WHERE id=?")->execute([$id]);
                return ['soft' => false, 'files' => array_merge([$item['image']], $stockImages)];
            });
        } catch (Throwable $e) {
            server_error($e);
        }
        if ($result === null) json_error('المنتج غير موجود', 404);
        if ($result['soft']) {
            json_success(['deactivated' => true], 'المنتج مرتبط بطلبات سابقة، تم تعطيله بدلاً من حذفه');
        }
        foreach ($result['files'] as $f) delete_upload($f);
        json_success([], 'تم حذف المنتج');
        break;

    case 'fields':
        $id = intval($_GET['id'] ?? 0);
        $stmt = $db->prepare("SELECT id, item_id, label, type, is_required, sort_order, prefix, suffix FROM item_fields WHERE item_id=? AND " . items_active_fields_cond($db) . " ORDER BY sort_order ASC, id ASC");
        $stmt->execute([$id]);
        json_success(['fields' => $stmt->fetchAll()]);
        break;

    // ==========================================
    // Stock Management
    // ==========================================
    case 'stock_list':
        require_admin();
        $itemId = intval($_GET['item_id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM item_stock WHERE item_id=? ORDER BY is_sold ASC, id ASC");
        $stmt->execute([$itemId]);
        $stock = $stmt->fetchAll();
        $available = 0;
        $now = gmdate('Y-m-d H:i:s');
        foreach ($stock as $s) {
            if (!$s['is_sold'] && (!$s['expires_at'] || $s['expires_at'] > $now)) $available++;
        }
        json_success(['stock' => $stock, 'available' => $available, 'total' => count($stock)]);
        break;

    case 'stock_add':
        require_method('POST');
        require_csrf();
        require_admin();
        $itemId = intval($_POST['item_id'] ?? 0);
        if (!$itemId) json_error('المنتج مطلوب');
        $stmt = $db->prepare("SELECT id FROM items WHERE id=?");
        $stmt->execute([$itemId]);
        if (!$stmt->fetchColumn()) json_error('المنتج غير موجود', 404);

        // Count how many items are in the repeater
        $count = intval($_POST['stock_count'] ?? 0);
        if ($count <= 0) json_error('لا توجد عناصر للإضافة');
        if ($count > 500) json_error('الحد الأقصى 500 عنصر في المرة الواحدة');

        // Stored images go to the PRIVATE stock dir; clean them up if the request aborts.
        $storedImages = [];
        $committed = false;
        register_shutdown_function(function () use (&$committed, &$storedImages) {
            if (!$committed) foreach ($storedImages as $p) delete_upload($p);
        });

        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $content = $_POST["stock_content_{$i}"] ?? '';
            $content = is_string($content) ? trim($content) : '';
            if (mb_strlen($content) > 10000) json_error('محتوى العنصر طويل جداً (الحد الأقصى 10000 حرف)');
            $expiresAt = items_parse_datetime($_POST["stock_expires_{$i}"] ?? '');

            $imagePath = null;
            $fileKey = "stock_image_{$i}";
            if (!empty($_FILES[$fileKey]['name'])) {
                $imagePath = handle_upload($fileKey, UPLOAD_STOCK);
                if ($imagePath) $storedImages[] = $imagePath;
            }

            // Must have at least content or image
            if ($content === '' && !$imagePath) continue;
            $rows[] = [$itemId, $content !== '' ? $content : null, $imagePath, $expiresAt];
        }
        if (!$rows) json_error('لا توجد عناصر صالحة للإضافة');

        try {
            DB::transaction(function (PDO $db) use ($rows) {
                $stmt = $db->prepare("INSERT INTO item_stock (item_id, content, image_path, expires_at) VALUES (?,?,?,?)");
                foreach ($rows as $r) $stmt->execute($r);
            });
        } catch (Throwable $e) {
            server_error($e);
        }
        $committed = true;
        $added = count($rows);
        json_success(['added' => $added], "تم إضافة {$added} عنصر");
        break;

    case 'stock_delete':
        require_method('POST');
        require_csrf();
        require_admin();
        $data = json_body();
        $id = intval($data['id'] ?? 0);
        if (!$id) json_error('معرف العنصر مطلوب');
        try {
            $imagePath = DB::transaction(function (PDO $db) use ($id) {
                $stmt = $db->prepare("SELECT image_path FROM item_stock WHERE id=? AND is_sold=0");
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                if (!$row) return false;
                $del = $db->prepare("DELETE FROM item_stock WHERE id=? AND is_sold=0");
                $del->execute([$id]);
                if ($del->rowCount() !== 1) return false;
                return $row['image_path'] ?: '';
            });
        } catch (Throwable $e) {
            server_error($e);
        }
        if ($imagePath === false) json_error('العنصر غير موجود أو تم بيعه');
        delete_upload($imagePath ?: null);
        json_success([], 'تم حذف العنصر');
        break;

    default:
        json_error('إجراء غير معروف', 404);
}

// ==========================================
// Helpers
// ==========================================

/** Does item_fields have an is_active column (optional future migration)? */
function items_fields_has_active_col(PDO $db): bool {
    static $has = null;
    if ($has === null) {
        $cols = array_column($db->query("PRAGMA table_info(item_fields)")->fetchAll(), 'name');
        $has = in_array('is_active', $cols, true);
    }
    return $has;
}

/**
 * SQL condition selecting live (not retired) fields. Retired fields — removed by the
 * admin but still referenced by past orders — are kept with sort_order = -1.
 */
function items_active_fields_cond(PDO $db): string {
    return 'sort_order >= 0' . (items_fields_has_active_col($db) ? ' AND is_active = 1' : '');
}

/** Validate a single-line/multi-line text input. */
function items_text($value, int $max, string $label, bool $required = false, bool $multiline = false): string {
    if (!is_scalar($value) && $value !== null) json_error("قيمة {$label} غير صالحة");
    $s = trim((string)$value);
    if ($required && $s === '') json_error("{$label} مطلوب");
    if (mb_strlen($s) > $max) json_error("{$label} طويل جداً (الحد الأقصى {$max} حرف)");
    $ctrl = $multiline ? '~[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]~' : '~[\x00-\x1F\x7F]~';
    if (preg_match($ctrl, $s)) json_error("{$label} يحتوي على رموز غير مسموحة");
    return $s;
}

/** Validate an API URL template (must be http(s); may contain {placeholders}). */
function items_api_url($value, string $label = 'رابط API'): string {
    $url = items_text($value, 2000, $label);
    if ($url === '') return '';
    if (!preg_match('~^https?://[^\s/?#]+~i', $url)) json_error("{$label} يجب أن يبدأ بـ http:// أو https://");
    return $url;
}

/** Money that may legitimately be 0 (contact-only / reference prices). Returns null if invalid. */
function items_money_or_zero($value): ?float {
    if (is_string($value)) $value = trim($value);
    if ($value === '' || $value === null) return 0.0;
    if (!is_numeric($value)) return null;
    if ((float)$value == 0.0) return 0.0;
    return parse_money($value);
}

/** Parse & validate the item create/update form. Calls json_error() on bad input. */
function items_parse_input(PDO $db, bool $isUpdate): array {
    $in = [];
    $in['category_id'] = intval($_POST['category_id'] ?? 0);
    if ($in['category_id'] <= 0) json_error('التصنيف مطلوب');
    $stmt = $db->prepare("SELECT id FROM categories WHERE id=?");
    $stmt->execute([$in['category_id']]);
    if (!$stmt->fetchColumn()) json_error('التصنيف غير موجود');

    $in['name'] = items_text($_POST['name'] ?? '', 150, 'اسم المنتج', true);
    $in['description'] = items_text($_POST['description'] ?? '', 5000, 'الوصف', false, true);

    $pt = $_POST['pricing_type'] ?? 'fixed';
    $in['pricing_type'] = in_array($pt, ['fixed', 'calculated', 'options'], true) ? $pt : 'fixed';
    $in['contact_only'] = intval($_POST['contact_only'] ?? 0) ? 1 : 0;

    if ($in['pricing_type'] === 'calculated') {
        // Reference ("starting from") price — display only, 0 allowed.
        $price = items_money_or_zero($_POST['calc_base_price'] ?? 0);
        if ($price === null) json_error('السعر المرجعي غير صالح');
    } elseif ($in['pricing_type'] === 'options') {
        $price = 0.0; // recomputed from the options
    } else {
        $price = $in['contact_only'] ? items_money_or_zero($_POST['price'] ?? 0) : parse_money($_POST['price'] ?? null);
        if ($price === null) json_error('السعر غير صالح (يجب أن يكون رقماً أكبر من صفر)');
    }
    $in['price'] = $price;

    $cw = intval($_POST['cancel_window_seconds'] ?? 0);
    if ($cw < 0 || $cw > 30 * 86400) json_error('مدة إلغاء الطلب غير صالحة');
    $in['cancel_window_seconds'] = $cw;
    $in['sort_order'] = max(-100000, min(100000, intval($_POST['sort_order'] ?? 0)));
    $in['is_active'] = $isUpdate ? (intval($_POST['is_active'] ?? 1) ? 1 : 0) : 1;

    $dt = $_POST['delivery_type'] ?? 'manual';
    $in['delivery_type'] = in_array($dt, ['manual', 'auto_stock', 'api_json'], true) ? $dt : 'manual';
    $in['api_url'] = items_api_url($_POST['api_url'] ?? '');

    $calcRaw = is_string($_POST['calc_field_id'] ?? null) ? trim($_POST['calc_field_id']) : '';
    $in['calc_field_id'] = null;
    $in['calc_field_pos'] = null;
    if (str_starts_with($calcRaw, '_pos_')) {
        $in['calc_field_pos'] = intval(substr($calcRaw, 5));
    } elseif ($calcRaw !== '') {
        $in['calc_field_id'] = intval($calcRaw);
    }
    $op = $_POST['calc_operator'] ?? '*';
    $in['calc_operator'] = in_array($op, ['+', '-', '*', '/'], true) ? $op : '*';
    $cv = $_POST['calc_value'] ?? 0;
    if (is_string($cv)) $cv = trim($cv);
    if ($cv === '' || $cv === null) $cv = 0;
    if (!is_numeric($cv) || !is_finite((float)$cv) || abs((float)$cv) > 1000000) json_error('قيمة المعادلة غير صالحة');
    $in['calc_value'] = (float)$cv;
    if ($in['pricing_type'] === 'calculated') {
        if ($in['calc_operator'] === '/' && $in['calc_value'] == 0.0) json_error('لا يمكن القسمة على صفر');
        if ($in['calc_field_pos'] === null && !$in['calc_field_id']) json_error('يجب اختيار الحقل المرتبط بالتسعير المحسوب');
    }
    return $in;
}

/** Decode + validate the custom-fields JSON. Keeps the submitted index (used by "_pos_N"). */
function items_normalize_fields(string $json): array {
    $raw = json_decode($json, true);
    if (!is_array($raw)) json_error('بيانات الحقول غير صالحة');
    if (count($raw) > 50) json_error('الحد الأقصى 50 حقلاً');
    $out = [];
    foreach (array_values($raw) as $i => $f) {
        if (!is_array($f)) continue;
        $label = items_text($f['label'] ?? '', 100, 'عنوان الحقل');
        if ($label === '') continue;
        $type = $f['type'] ?? 'text';
        $out[$i] = [
            'id'          => isset($f['id']) && is_numeric($f['id']) ? (int)$f['id'] : null,
            'label'       => $label,
            'type'        => in_array($type, ['text', 'email', 'phone', 'textarea', 'image'], true) ? $type : 'text',
            'is_required' => intval($f['is_required'] ?? 1) ? 1 : 0,
            'prefix'      => items_text($f['prefix'] ?? '', 30, 'البادئة'),
            'suffix'      => items_text($f['suffix'] ?? '', 30, 'اللاحقة'),
        ];
    }
    return $out;
}

/** Decode + validate price options JSON. Fully empty rows are ignored. */
function items_normalize_price_options(string $json): array {
    $raw = json_decode($json, true);
    if (!is_array($raw)) json_error('بيانات خيارات السعر غير صالحة');
    if (count($raw) > 100) json_error('الحد الأقصى 100 خيار');
    $out = [];
    foreach (array_values($raw) as $opt) {
        if (!is_array($opt)) continue;
        $label = items_text($opt['label'] ?? '', 100, 'اسم الخيار');
        $priceRaw = $opt['price'] ?? '';
        if ($label === '' && ($priceRaw === '' || (is_numeric($priceRaw) && (float)$priceRaw == 0.0))) continue;
        if ($label === '') json_error('اسم الخيار مطلوب');
        $price = parse_money($priceRaw);
        if ($price === null) json_error("سعر الخيار \"{$label}\" غير صالح");
        $out[] = ['label' => $label, 'price' => $price, 'api_url' => items_api_url($opt['api_url'] ?? '', 'رابط API للخيار')];
    }
    return $out;
}

/** Normalize an expiry date (e.g. datetime-local "2025-01-31T10:00") to "Y-m-d H:i:s", or null. */
function items_parse_datetime($value): ?string {
    if (!is_string($value) || trim($value) === '') return null;
    $v = trim($value);
    foreach (['Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d H:i:s', 'Y-m-d'] as $fmt) {
        $d = DateTime::createFromFormat('!' . $fmt, $v);
        if ($d && $d->format($fmt) === $v) return $d->format('Y-m-d H:i:s');
    }
    json_error('تاريخ الانتهاء غير صالح');
    return null;
}

/**
 * Save custom fields WITHOUT recreating them, so past orders' order_field_values keep
 * pointing at the right label. Matching order for each submitted field:
 *   1. explicit "id" in the payload (if it belongs to this item and the type is unchanged)
 *   2. first unmatched existing field with the same label and type (live fields first)
 *   3. otherwise insert a new field.
 * Existing fields left unmatched are deleted if no order references them; otherwise they
 * are retired (sort_order = -1, not required, is_active = 0 if that column exists).
 * Must run inside DB::transaction(). Returns submitted index => field id.
 */
function saveItemFields(PDO $db, int $itemId, array $fields): array {
    $hasActive = items_fields_has_active_col($db);
    $stmt = $db->prepare("SELECT id, label, type, (" . items_active_fields_cond($db) . ") AS is_live FROM item_fields WHERE item_id=? ORDER BY is_live DESC, sort_order ASC, id ASC");
    $stmt->execute([$itemId]);
    $existing = [];
    foreach ($stmt->fetchAll() as $row) $existing[(int)$row['id']] = $row;

    $used = [];
    $posMap = [];
    $upd = $db->prepare("UPDATE item_fields SET label=?, type=?, is_required=?, sort_order=?, prefix=?, suffix=?" . ($hasActive ? ", is_active=1" : "") . " WHERE id=? AND item_id=?");
    $ins = $db->prepare("INSERT INTO item_fields (item_id, label, type, is_required, sort_order, prefix, suffix) VALUES (?,?,?,?,?,?,?)");

    foreach ($fields as $i => $f) {
        $matchId = null;
        if ($f['id'] && isset($existing[$f['id']]) && !isset($used[$f['id']]) && $existing[$f['id']]['type'] === $f['type']) {
            $matchId = $f['id'];
        } else {
            foreach ($existing as $eid => $e) {
                if (!isset($used[$eid]) && $e['label'] === $f['label'] && $e['type'] === $f['type']) { $matchId = $eid; break; }
            }
        }
        if ($matchId !== null) {
            $used[$matchId] = true;
            $upd->execute([$f['label'], $f['type'], $f['is_required'], $i, $f['prefix'], $f['suffix'], $matchId, $itemId]);
            $posMap[$i] = $matchId;
        } else {
            $ins->execute([$itemId, $f['label'], $f['type'], $f['is_required'], $i, $f['prefix'], $f['suffix']]);
            $posMap[$i] = (int)$db->lastInsertId();
        }
    }

    $refStmt = $db->prepare("SELECT 1 FROM order_field_values WHERE field_id=? LIMIT 1");
    $retire = $db->prepare("UPDATE item_fields SET sort_order=-1, is_required=0" . ($hasActive ? ", is_active=0" : "") . " WHERE id=?");
    $delete = $db->prepare("DELETE FROM item_fields WHERE id=?");
    foreach ($existing as $eid => $e) {
        if (isset($used[$eid])) continue;
        $refStmt->execute([$eid]);
        if ($refStmt->fetchColumn()) {
            $retire->execute([$eid]);
        } else {
            $delete->execute([$eid]);
        }
    }
    return $posMap;
}

/** Replace an item's price options (already validated). Returns the minimum price. */
function saveItemPriceOptions(PDO $db, int $itemId, array $options): float {
    $db->prepare("DELETE FROM item_price_options WHERE item_id=?")->execute([$itemId]);
    if (empty($options)) return 0;
    $stmt = $db->prepare("INSERT INTO item_price_options (item_id, label, price, sort_order, api_url) VALUES (?,?,?,?,?)");
    $minPrice = PHP_FLOAT_MAX;
    foreach ($options as $i => $opt) {
        $stmt->execute([$itemId, $opt['label'], $opt['price'], $i, $opt['api_url']]);
        if ($opt['price'] < $minPrice) $minPrice = $opt['price'];
    }
    return $minPrice === PHP_FLOAT_MAX ? 0 : $minPrice;
}
