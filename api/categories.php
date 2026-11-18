<?php
require_once __DIR__ . '/../config.php';
$action = $_GET['action'] ?? '';
$db = DB::get();
release_session();

/**
 * Check if a category and ALL its ancestors are active.
 * Returns false if any category in the chain is inactive or missing.
 */
function isCategoryChainActive(PDO $db, int $categoryId): bool {
    $stmt = $db->prepare("SELECT is_active, parent_id FROM categories WHERE id = ?");
    $visited = [];
    $currentId = $categoryId;
    while ($currentId) {
        if (isset($visited[$currentId])) break; // circular reference guard
        $visited[$currentId] = true;
        $stmt->execute([$currentId]);
        $row = $stmt->fetch();
        if (!$row || !$row['is_active']) return false;
        $currentId = $row['parent_id'] ? (int)$row['parent_id'] : 0;
    }
    return true;
}

/**
 * Walk up the parent chain to find the root (top-level) category ID.
 */
/** Public-safe category columns. */
const CAT_PUBLIC_COLS = 'id, name, description, image, sort_order, is_active, parent_id';

/** Validate a text input; json_error() on failure. */
function cat_text($value, int $max, string $label, bool $required = false, bool $multiline = false): string {
    if (!is_scalar($value) && $value !== null) json_error("قيمة {$label} غير صالحة");
    $s = trim((string)$value);
    if ($required && $s === '') json_error("{$label} مطلوب");
    if (mb_strlen($s) > $max) json_error("{$label} طويل جداً (الحد الأقصى {$max} حرف)");
    $ctrl = $multiline ? '~[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]~' : '~[\x00-\x1F\x7F]~';
    if (preg_match($ctrl, $s)) json_error("{$label} يحتوي على رموز غير مسموحة");
    return $s;
}

/**
 * Validate a parent category id (one nesting level only, no self/cycles).
 * Returns null for "top level".
 */
function cat_validate_parent(PDO $db, int $parentId, int $selfId = 0): ?int {
    if ($parentId <= 0) return null;
    if ($selfId && $parentId === $selfId) json_error('لا يمكن جعل التصنيف أباً لنفسه');
    $stmt = $db->prepare("SELECT id, parent_id FROM categories WHERE id=?");
    $stmt->execute([$parentId]);
    $p = $stmt->fetch();
    if (!$p) json_error('التصنيف الأب غير موجود');
    if (!empty($p['parent_id'])) json_error('يجب أن يكون التصنيف الأب تصنيفاً رئيسياً');
    if ($selfId) {
        $stmt = $db->prepare("SELECT 1 FROM categories WHERE parent_id=? LIMIT 1");
        $stmt->execute([$selfId]);
        if ($stmt->fetchColumn()) json_error('لا يمكن نقل تصنيف يحتوي على تصنيفات فرعية تحت تصنيف آخر');
    }
    return $parentId;
}

function getRootCategoryId(PDO $db, int $categoryId): int {
    $stmt = $db->prepare("SELECT parent_id FROM categories WHERE id = ?");
    $visited = [];
    $currentId = $categoryId;
    while (true) {
        if (isset($visited[$currentId])) break;
        $visited[$currentId] = true;
        $stmt->execute([$currentId]);
        $row = $stmt->fetch();
        if (!$row || !$row['parent_id']) break;
        $currentId = (int)$row['parent_id'];
    }
    return $currentId;
}

switch ($action) {
    case 'list_with_items':
        // Only top-level categories (no parent) for the store page
        $cats = $db->query("SELECT " . CAT_PUBLIC_COLS . " FROM categories WHERE is_active=1 AND (parent_id IS NULL OR parent_id = 0) ORDER BY sort_order ASC, id ASC")->fetchAll();
        $items = $db->query("SELECT id, name, description, price, image, contact_only, category_id, delivery_type, pricing_type FROM items WHERE is_active=1 ORDER BY sort_order ASC, id ASC")->fetchAll();
        $itemsByCategory = [];
        foreach ($items as $item) {
            $itemsByCategory[$item['category_id']][] = $item;
        }
        // Also fetch child categories to count their items
        $childCats = $db->query("SELECT id, parent_id FROM categories WHERE is_active=1 AND parent_id IS NOT NULL AND parent_id != 0")->fetchAll();
        $childIdsByParent = [];
        foreach ($childCats as $cc) {
            $childIdsByParent[$cc['parent_id']][] = $cc['id'];
        }
        foreach ($cats as &$cat) {
            $cat['items'] = $itemsByCategory[$cat['id']] ?? [];
            // Count items in subcategories
            $subItemCount = 0;
            foreach (($childIdsByParent[$cat['id']] ?? []) as $childId) {
                $subItemCount += count($itemsByCategory[$childId] ?? []);
            }
            $cat['subcategory_item_count'] = $subItemCount;
        }
        json_success(['categories' => $cats]);
        break;

    case 'list':
        require_admin();
        // DataTables SSR mode
        if (isset($_GET['draw'])) {
            datatable_response($db, 'categories', [
                ['db' => 'id', 'searchable' => false],
                ['db' => 'image', 'searchable' => false, 'render' => fn($r) => $r['image'] ? '<img src="/' . htmlspecialchars($r['image'], ENT_QUOTES) . '" style="width:50px;height:40px;object-fit:cover;border-radius:6px;">' : '-'],
                ['db' => 'name'],
                ['db' => 'sort_order', 'searchable' => false],
                ['db' => 'is_active', 'searchable' => false, 'render' => fn($r) => $r['is_active'] ? '<span class="badge badge-accepted">مفعّل</span>' : '<span class="badge badge-canceled">معطّل</span>'],
                ['db' => 'id', 'select' => 'id as action_id', 'searchable' => false, 'render' => fn($r) =>
                    '<button class="btn btn-primary btn-sm edit-cat" data-id="' . (int)$r['action_id'] . '">✏️</button> ' .
                    '<button class="btn btn-danger btn-sm del-cat" data-id="' . (int)$r['action_id'] . '">🗑</button>'
                ],
            ]);
        }
        // Fallback full list (used for dropdowns etc.)
        $cats = $db->query("SELECT * FROM categories ORDER BY sort_order ASC, id ASC")->fetchAll();
        json_success(['categories' => $cats]);
        break;

    case 'get':
        $id = intval($_GET['id'] ?? 0);
        $viewer = auth_user();
        $isAdmin = $viewer && ($viewer['role'] ?? '') === 'admin';
        // Public: active categories only (whole ancestor chain); admin may open inactive ones to edit.
        if (!$isAdmin && !isCategoryChainActive($db, $id)) json_error('التصنيف غير موجود', 404);
        $stmt = $db->prepare("SELECT " . CAT_PUBLIC_COLS . " FROM categories WHERE id=?");
        $stmt->execute([$id]);
        $cat = $stmt->fetch();
        if (!$cat) json_error('التصنيف غير موجود', 404);
        $stmtItems = $db->prepare("SELECT id, name, description, price, image, contact_only, delivery_type, pricing_type FROM items WHERE category_id=? AND is_active=1 ORDER BY sort_order ASC, id ASC");
        $stmtItems->execute([$id]);
        $cat['items'] = $stmtItems->fetchAll();
        // Fetch subcategories
        $stmtSub = $db->prepare("SELECT " . CAT_PUBLIC_COLS . " FROM categories WHERE parent_id=? AND is_active=1 ORDER BY sort_order ASC, id ASC");
        $stmtSub->execute([$id]);
        $cat['subcategories'] = $stmtSub->fetchAll();
        // Fetch parent info for breadcrumb
        $parentName = '';
        $parentId = 0;
        if (!empty($cat['parent_id'])) {
            $stmtP = $db->prepare("SELECT id, name FROM categories WHERE id=?");
            $stmtP->execute([$cat['parent_id']]);
            $parent = $stmtP->fetch();
            if ($parent) { $parentName = $parent['name']; $parentId = (int)$parent['id']; }
        }
        $cat['parent_name'] = $parentName;
        $cat['parent_id_resolved'] = $parentId;
        json_success(['category' => $cat]);
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
            if ($id <= 0) json_error('معرف التصنيف مطلوب');
            $stmt = $db->prepare("SELECT image FROM categories WHERE id=?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) json_error('التصنيف غير موجود', 404);
            $oldImage = $row['image'];
        }
        $name = cat_text($_POST['name'] ?? '', 100, 'اسم التصنيف', true);
        $description = cat_text($_POST['description'] ?? '', 2000, 'الوصف', false, true);
        $sortOrder = max(-100000, min(100000, intval($_POST['sort_order'] ?? 0)));
        $isActive = $isUpdate ? (intval($_POST['is_active'] ?? 1) ? 1 : 0) : 1;
        $parentId = cat_validate_parent($db, intval($_POST['parent_id'] ?? 0), $id);

        $image = handle_upload('image', UPLOAD_PRODUCTS);
        try {
            if ($isUpdate) {
                $sql = "UPDATE categories SET name=?, description=?, sort_order=?, is_active=?, parent_id=?";
                $vals = [$name, $description, $sortOrder, $isActive, $parentId];
                if ($image) { $sql .= ", image=?"; $vals[] = $image; }
                $sql .= " WHERE id=?";
                $vals[] = $id;
                $db->prepare($sql)->execute($vals);
            } else {
                $db->prepare("INSERT INTO categories (name, description, image, sort_order, parent_id, is_active) VALUES (?,?,?,?,?,1)")
                   ->execute([$name, $description, $image, $sortOrder, $parentId]);
                $id = (int)$db->lastInsertId();
            }
        } catch (Throwable $e) {
            delete_upload($image);
            server_error($e);
        }
        if ($isUpdate) {
            if ($image && $oldImage && $oldImage !== $image) delete_upload($oldImage);
            json_success([], 'تم تحديث التصنيف');
        }
        json_success(['id' => $id], 'تم إنشاء التصنيف');
        break;

    case 'delete':
        require_method('POST');
        require_csrf();
        require_admin();
        $data = json_body();
        $id = intval($data['id'] ?? 0);
        if ($id <= 0) json_error('معرف التصنيف مطلوب');
        try {
            // Foreign keys stay ON: categories → items is ON DELETE CASCADE, so refuse to delete
            // a category that still has products instead of silently wiping/orphaning them.
            $result = DB::transaction(function (PDO $db) use ($id) {
                $stmt = $db->prepare("SELECT image FROM categories WHERE id=?");
                $stmt->execute([$id]);
                $cat = $stmt->fetch();
                if (!$cat) return 'missing';
                $stmt = $db->prepare("SELECT 1 FROM items WHERE category_id=? LIMIT 1");
                $stmt->execute([$id]);
                if ($stmt->fetchColumn()) return 'has_items';
                // Promote children to top level, then delete
                $db->prepare("UPDATE categories SET parent_id=NULL WHERE parent_id=?")->execute([$id]);
                $db->prepare("DELETE FROM categories WHERE id=?")->execute([$id]);
                return ['image' => $cat['image']];
            });
        } catch (Throwable $e) {
            server_error($e);
        }
        if ($result === 'missing') json_error('التصنيف غير موجود', 404);
        if ($result === 'has_items') json_error('لا يمكن حذف تصنيف يحتوي على منتجات. انقل المنتجات أو احذفها أولاً، أو قم بتعطيل التصنيف');
        delete_upload($result['image']);
        json_success([], 'تم حذف التصنيف');
        break;

    case 'search':
        $q = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 100) : '';
        if ($q === '') {
            // Empty query — return same as list_with_items
            $cats = $db->query("SELECT " . CAT_PUBLIC_COLS . " FROM categories WHERE is_active=1 AND (parent_id IS NULL OR parent_id = 0) ORDER BY sort_order ASC, id ASC")->fetchAll();
            $items = $db->query("SELECT id, name, description, price, image, contact_only, category_id, delivery_type, pricing_type FROM items WHERE is_active=1 ORDER BY sort_order ASC, id ASC")->fetchAll();
            $itemsByCategory = [];
            foreach ($items as $item) $itemsByCategory[$item['category_id']][] = $item;
            $childCats = $db->query("SELECT id, parent_id FROM categories WHERE is_active=1 AND parent_id IS NOT NULL AND parent_id != 0")->fetchAll();
            $childIdsByParent = [];
            foreach ($childCats as $cc) $childIdsByParent[$cc['parent_id']][] = $cc['id'];
            foreach ($cats as &$cat) {
                $cat['items'] = $itemsByCategory[$cat['id']] ?? [];
                $subItemCount = 0;
                foreach (($childIdsByParent[$cat['id']] ?? []) as $childId) $subItemCount += count($itemsByCategory[$childId] ?? []);
                $cat['subcategory_item_count'] = $subItemCount;
            }
            json_success(['categories' => $cats, 'items' => []]);
        }

        // Split query into words for smart matching
        $words = preg_split('/\s+/', $q);
        $words = array_slice(array_values(array_filter($words, fn($w) => mb_strlen($w) > 0)), 0, 8);
        if (empty($words)) json_success(['categories' => [], 'items' => []]);

        // Build LIKE conditions: all words must match (AND)
        $buildLike = function(string $column) use ($words, $db) {
            $conditions = [];
            $params = [];
            foreach ($words as $w) {
                $conditions[] = "$column LIKE ?";
                $params[] = '%' . $w . '%';
            }
            return ['sql' => implode(' AND ', $conditions), 'params' => $params];
        };

        $matchedCatIds = [];
        $resultCategories = [];
        $resultItems = [];

        // 1. Search in top-level categories (name + description)
        $like = $buildLike('name');
        $likeDesc = $buildLike('description');
        $catSql = "SELECT " . CAT_PUBLIC_COLS . " FROM categories WHERE is_active=1 AND (parent_id IS NULL OR parent_id = 0) AND (({$like['sql']}) OR ({$likeDesc['sql']}))";
        $stmt = $db->prepare($catSql);
        $stmt->execute(array_merge($like['params'], $likeDesc['params']));
        foreach ($stmt->fetchAll() as $cat) {
            $matchedCatIds[$cat['id']] = true;
            $cat['items'] = [];
            $resultCategories[$cat['id']] = $cat;
        }

        // 2. Search in subcategories — surface their root ancestor
        $subSql = "SELECT c.id, c.parent_id FROM categories c WHERE c.is_active=1 AND c.parent_id IS NOT NULL AND c.parent_id != 0 AND (({$like['sql']}) OR ({$likeDesc['sql']}))";
        $stmt = $db->prepare($subSql);
        $stmt->execute(array_merge($like['params'], $likeDesc['params']));
        foreach ($stmt->fetchAll() as $subcat) {
            // Skip subcategories whose full ancestor chain is not active
            if (!isCategoryChainActive($db, (int)$subcat['id'])) continue;

            // Walk up to root ancestor
            $rootId = getRootCategoryId($db, (int)$subcat['id']);
            if (!isset($matchedCatIds[$rootId])) {
                $pStmt = $db->prepare("SELECT " . CAT_PUBLIC_COLS . " FROM categories WHERE id=? AND is_active=1");
                $pStmt->execute([$rootId]);
                $parent = $pStmt->fetch();
                if ($parent) {
                    $matchedCatIds[$rootId] = true;
                    $parent['items'] = [];
                    $resultCategories[$rootId] = $parent;
                }
            }
        }

        // 3. Search in items (name + description)
        $likeName = $buildLike('i.name');
        $likeItemDesc = $buildLike('i.description');
        $itemSql = "SELECT i.id, i.name, i.description, i.price, i.image, i.contact_only, i.category_id, i.delivery_type, i.pricing_type, c.name as category_name, c.parent_id as cat_parent_id
                    FROM items i LEFT JOIN categories c ON i.category_id = c.id
                    WHERE i.is_active=1 AND (({$likeName['sql']}) OR ({$likeItemDesc['sql']}))
                    ORDER BY i.sort_order ASC LIMIT 30";
        $stmt = $db->prepare($itemSql);
        $stmt->execute(array_merge($likeName['params'], $likeItemDesc['params']));
        foreach ($stmt->fetchAll() as $item) {
            $catId = (int)$item['category_id'];

            // Skip items whose category (or any ancestor) is inactive
            if (!isCategoryChainActive($db, $catId)) continue;

            $resultItems[] = $item;

            // Surface the item's root ancestor category
            $rootId = getRootCategoryId($db, $catId);
            if (!isset($matchedCatIds[$rootId])) {
                $pStmt = $db->prepare("SELECT " . CAT_PUBLIC_COLS . " FROM categories WHERE id=? AND is_active=1");
                $pStmt->execute([$rootId]);
                $parent = $pStmt->fetch();
                if ($parent) {
                    $matchedCatIds[$rootId] = true;
                    $parent['items'] = [];
                    $resultCategories[$rootId] = $parent;
                }
            }
        }

        // Add subcategory_item_count = 0 for search results (not computed)
        foreach ($resultCategories as &$rc) {
            $rc['subcategory_item_count'] = $rc['subcategory_item_count'] ?? 0;
        }

        json_success([
            'categories' => array_values($resultCategories),
            'items' => $resultItems,
        ]);
        break;

    default:
        json_error('إجراء غير معروف', 404);
}
