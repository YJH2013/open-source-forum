<?php
/**
 * GET /api/posts?page=1&category_id=&limit=20
 */

$pdo = getDB();
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;
$categoryId = $_GET['category_id'] ?? null;

$where = '';
$params = [];
if ($categoryId) {
    $where = 'WHERE p.category_id = ?';
    $params[] = (int)$categoryId;
}

$countSql = "SELECT COUNT(*) as total FROM posts p $where";
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$total = $stmt->fetch()['total'];

$sql = "
    SELECT p.*, u.username, u.avatar, c.name as category_name, c.slug as category_slug
    FROM posts p
    JOIN users u ON p.user_id = u.id
    JOIN categories c ON p.category_id = c.id
    $where
    ORDER BY p.is_pinned DESC, p.updated_at DESC
    LIMIT $limit OFFSET $offset
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$list = $stmt->fetchAll();

// 类型转换
foreach ($list as &$row) {
    $row['id'] = (int)$row['id'];
    $row['user_id'] = (int)$row['user_id'];
    $row['category_id'] = (int)$row['category_id'];
    $row['is_pinned'] = (int)$row['is_pinned'];
    $row['is_locked'] = (int)$row['is_locked'];
    $row['view_count'] = (int)$row['view_count'];
    $row['reply_count'] = (int)$row['reply_count'];
}

paginated($list, (int)$total, $page, $limit);
