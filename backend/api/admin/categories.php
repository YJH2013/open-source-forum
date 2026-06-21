<?php
/**
 * GET    /api/admin/categories            — 版块列表（嵌套）
 * POST   /api/admin/categories            — 创建版块 { name, slug, parent_id? }
 * PUT    /api/admin/categories?id=X       — 更新版块
 * DELETE /api/admin/categories?id=X       — 删除版块
 */

$auth = requireAuth();
if ($auth['role'] !== 'admin') error('需要管理员权限', 403);

$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $all = $pdo->query('SELECT * FROM categories ORDER BY sort_order ASC')->fetchAll();
    $parents = [];
    $children = [];
    foreach ($all as &$cat) {
        $cat['id'] = (int)$cat['id'];
        $cat['parent_id'] = $cat['parent_id'] ? (int)$cat['parent_id'] : null;
        $cat['sort_order'] = (int)$cat['sort_order'];
        $cat['post_count'] = (int)$cat['post_count'];
        if (!$cat['parent_id']) {
            $cat['children'] = [];
            $parents[$cat['id']] = $cat;
        } else {
            $children[] = $cat;
        }
    }
    foreach ($children as $child) {
        if (isset($parents[$child['parent_id']])) {
            $parents[$child['parent_id']]['children'][] = $child;
        }
    }
    success(array_values($parents));
}

elseif ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $name = trim($data['name'] ?? '');
    $description = trim($data['description'] ?? '');
    $slug = trim($data['slug'] ?? '');
    $icon = trim($data['icon'] ?? '📋');
    $sortOrder = (int)($data['sort_order'] ?? 0);
    $parentId = ($data['parent_id'] ?? null) ? (int)$data['parent_id'] : null;

    if (!$name) error('版块名称不能为空');
    if (!$slug) $slug = preg_replace('/[^a-zA-Z0-9]/', '', $name) ?: 'cat' . time();

    // 检查 slug 唯一
    $existing = $pdo->prepare('SELECT id FROM categories WHERE slug = ?');
    $existing->execute([$slug]);
    if ($existing->fetch()) error('版块标识已存在');

    // 检查父版块存在
    if ($parentId) {
        $p = $pdo->prepare('SELECT id FROM categories WHERE id = ? AND parent_id IS NULL');
        $p->execute([$parentId]);
        if (!$p->fetch()) error('父版块不存在或不是大版块');
    }

    $onlyAdmin = !empty($data['only_admin']) ? 1 : 0;
    $pdo->prepare('INSERT INTO categories (name, description, slug, icon, sort_order, parent_id, only_admin) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$name, $description, $slug, $icon, $sortOrder, $parentId, $onlyAdmin]);
    success(['id' => (int)$pdo->lastInsertId()], '创建成功');
}

elseif ($method === 'PUT') {
    $id = (int)($_GET['id'] ?? 0);
    $data = json_decode(file_get_contents('php://input'), true);

    $existing = $pdo->prepare('SELECT id FROM categories WHERE id = ?');
    $existing->execute([$id]);
    if (!$existing->fetch()) error('版块不存在', 404);

    $name = trim($data['name'] ?? '');
    if (!$name) error('版块名称不能为空');

    $slug = trim($data['slug'] ?? '');
    if ($slug) {
        $dup = $pdo->prepare('SELECT id FROM categories WHERE slug = ? AND id != ?');
        $dup->execute([$slug, $id]);
        if ($dup->fetch()) error('版块标识已存在');
    }

    $parentId = isset($data['parent_id']) ? ($data['parent_id'] ? (int)$data['parent_id'] : null) : null;
    // 不能把自己设为自己的父版块
    if ($parentId && $parentId === $id) error('不能将自己设为父版块');

    $onlyAdmin = isset($data['only_admin']) ? (!empty($data['only_admin']) ? 1 : 0) : 0;
    $pdo->prepare('UPDATE categories SET name = ?, description = ?, slug = ?, icon = ?, sort_order = ?, parent_id = ?, only_admin = ? WHERE id = ?')
        ->execute([
            $name,
            trim($data['description'] ?? ''),
            $slug ?: '',
            trim($data['icon'] ?? '📋'),
            (int)($data['sort_order'] ?? 0),
            $parentId,
            $onlyAdmin,
            $id
        ]);
    success(null, '更新成功');
}

elseif ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    // 检查有帖子
    $count = $pdo->prepare('SELECT COUNT(*) FROM posts WHERE category_id = ?');
    $count->execute([$id]);
    $postCount = $count->fetchColumn();
    // 检查有子版块
    $subCount = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE parent_id = ?');
    $subCount->execute([$id]);
    $subCount = $subCount->fetchColumn();

    if ($postCount > 0) error('该版块下有帖子，无法删除');
    if ($subCount > 0) error('该版块下有子版块，请先删除子版块');

    $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
    success(null, '删除成功');
}
