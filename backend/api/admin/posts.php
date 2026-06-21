<?php
/**
 * GET    /api/admin/posts?page=1     — 帖子列表
 * DELETE /api/admin/posts?id=xxx      — 删除帖子
 */

$auth = requireAuth();
if ($auth['role'] !== 'admin') error('需要管理员权限', 403);

$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $search = trim($_GET['search'] ?? '');
    $limit = 20;
    $offset = ($page - 1) * $limit;

    if ($search) {
        $stmt = $pdo->prepare("SELECT p.id, p.title, p.user_id, p.category_id, p.price, p.is_pinned, p.is_locked, p.view_count, p.reply_count, p.created_at, u.username, c.name as category_name FROM posts p JOIN users u ON p.user_id = u.id LEFT JOIN categories c ON p.category_id = c.id WHERE p.title LIKE ? ORDER BY p.id DESC LIMIT $limit OFFSET $offset");
        $stmt->execute(["%$search%"]);
        $total = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE title LIKE ?");
        $total->execute(["%$search%"]);
    } else {
        $stmt = $pdo->query("SELECT p.id, p.title, p.user_id, p.category_id, p.price, p.is_pinned, p.is_locked, p.view_count, p.reply_count, p.created_at, u.username, c.name as category_name FROM posts p JOIN users u ON p.user_id = u.id LEFT JOIN categories c ON p.category_id = c.id ORDER BY p.id DESC LIMIT $limit OFFSET $offset");
        $total = $pdo->query("SELECT COUNT(*) FROM posts");
    }

    $list = $stmt->fetchAll();
    success([
        'list' => $list,
        'total' => (int)$total->fetchColumn(),
        'page' => $page,
        'pageSize' => $limit
    ]);
}

elseif ($method === 'DELETE') {
    $postId = (int)($_GET['id'] ?? 0);
    if ($postId <= 0) error('请指定帖子ID');

    $stmt = $pdo->prepare("SELECT id, title FROM posts WHERE id = ?");
    $stmt->execute([$postId]);
    $post = $stmt->fetch();
    if (!$post) error('帖子不存在');

    $pdo->beginTransaction();
    try {
        // 删除回复中的文件
        $pdo->prepare("DELETE FROM files WHERE reply_id IN (SELECT id FROM replies WHERE post_id = ?)")->execute([$postId]);
        // 删除帖子的回复
        $pdo->prepare("DELETE FROM replies WHERE post_id = ?")->execute([$postId]);
        // 删除帖子文件
        $pdo->prepare("DELETE FROM files WHERE post_id = ?")->execute([$postId]);
        // 删除帖子订单
        $pdo->prepare("DELETE FROM orders WHERE post_id = ?")->execute([$postId]);
        // 更新版块帖子数
        $stmt2 = $pdo->prepare("SELECT category_id FROM posts WHERE id = ?");
        $stmt2->execute([$postId]);
        $catId = $stmt2->fetchColumn();
        if ($catId) {
            $pdo->prepare("UPDATE categories SET post_count = GREATEST(post_count - 1, 0) WHERE id = ?")->execute([$catId]);
        }
        // 删除帖子
        $pdo->prepare("DELETE FROM posts WHERE id = ?")->execute([$postId]);
        $pdo->commit();
        success(null, '帖子已删除');
    } catch (Exception $e) {
        $pdo->rollBack();
        error('删除失败: ' . $e->getMessage(), 500);
    }
}

else {
    error('不支持的请求方法', 405);
}
