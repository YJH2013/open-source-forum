<?php
/**
 * GET /api/users/:id
 */

$pdo = getDB();
$userId = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT id, username, email, role, avatar, signature, balance, created_at FROM users WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) error('用户不存在', 404);

$user['id'] = (int)$user['id'];

// 用户帖子
$stmt = $pdo->prepare("
    SELECT p.*, c.name as category_name, c.slug as category_slug
    FROM posts p
    JOIN categories c ON p.category_id = c.id
    WHERE p.user_id = ?
    ORDER BY p.created_at DESC
    LIMIT 20
");
$stmt->execute([$userId]);
$posts = $stmt->fetchAll();

// 回复数
$stmt = $pdo->prepare('SELECT COUNT(*) as cnt FROM replies WHERE user_id = ?');
$stmt->execute([$userId]);
$replyCount = (int)$stmt->fetch()['cnt'];

success([
    'user' => $user,
    'posts' => $posts,
    'replyCount' => $replyCount
]);
