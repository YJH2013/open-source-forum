<?php
/**
 * GET /api/search?q=关键词&page=1
 */

$pdo = getDB();
$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

if (!$q) error('请输入搜索关键词');

$like = '%' . $q . '%';

$stmt = $pdo->prepare('SELECT COUNT(*) as total FROM posts WHERE title LIKE ? OR content LIKE ?');
$stmt->execute([$like, $like]);
$total = (int)$stmt->fetch()['total'];

$stmt = $pdo->prepare("
    SELECT p.*, u.username, u.avatar, c.name as category_name, c.slug as category_slug
    FROM posts p
    JOIN users u ON p.user_id = u.id
    JOIN categories c ON p.category_id = c.id
    WHERE p.title LIKE ? OR p.content LIKE ?
    ORDER BY p.updated_at DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute([$like, $like]);
$list = $stmt->fetchAll();

paginated($list, $total, $page, $limit);
