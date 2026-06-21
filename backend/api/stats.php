<?php
/**
 * GET /api/stats
 * 论坛统计信息
 */

$pdo = getDB();

$totalUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$totalPosts = (int)$pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn();
$totalReplies = (int)$pdo->query('SELECT COUNT(*) FROM replies')->fetchColumn();
$latestUser = $pdo->query('SELECT username FROM users ORDER BY created_at DESC LIMIT 1')->fetch();
$onlineUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE last_active > DATE_SUB(NOW(), INTERVAL 5 MINUTE)")->fetchColumn();
$firstUser = $pdo->query("SELECT created_at FROM users ORDER BY created_at ASC LIMIT 1")->fetch();

success([
    'totalUsers'    => $totalUsers,
    'totalPosts'    => $totalPosts,
    'totalReplies'  => $totalReplies,
    'latestUser'    => $latestUser ? $latestUser['username'] : null,
    'onlineUsers'   => $onlineUsers,
    'siteStartedAt' => $firstUser ? $firstUser['created_at'] : date('Y-m-d H:i:s'),
]);
