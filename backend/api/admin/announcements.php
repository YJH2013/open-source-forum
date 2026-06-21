<?php
/**
 * GET    /api/admin/announcements       — 公告列表
 * POST   /api/admin/announcements       — 创建公告
 * PUT    /api/admin/announcements?id=X  — 更新公告
 * DELETE /api/admin/announcements?id=X  — 删除公告
 */

$auth = requireAuth();
if ($auth['role'] !== 'admin') error('需要管理员权限', 403);

$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $list = $pdo->query('SELECT * FROM announcements ORDER BY created_at DESC')->fetchAll();
    success($list);
}

elseif ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $title = trim($data['title'] ?? '');
    $content = trim($data['content'] ?? '');
    if (!$title || !$content) error('标题和内容不能为空');

    $pdo->prepare('INSERT INTO announcements (title, content) VALUES (?, ?)')
        ->execute([$title, $content]);
    success(['id' => (int)$pdo->lastInsertId()], '创建成功');
}

elseif ($method === 'PUT') {
    $id = (int)($_GET['id'] ?? 0);
    $data = json_decode(file_get_contents('php://input'), true);
    $title = trim($data['title'] ?? '');
    $content = trim($data['content'] ?? '');

    $pdo->prepare('UPDATE announcements SET title = ?, content = ? WHERE id = ?')
        ->execute([$title, $content, $id]);
    success(null, '更新成功');
}

elseif ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    $pdo->prepare('DELETE FROM announcements WHERE id = ?')->execute([$id]);
    success(null, '删除成功');
}
