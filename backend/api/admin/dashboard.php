<?php
/**
 * GET /api/admin/dashboard
 * 后台仪表盘数据
 */

$auth = requireAuth();
if ($auth['role'] !== 'admin') error('需要管理员权限', 403);

$pdo = getDB();

$totalUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$totalPosts = (int)$pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn();
$totalReplies = (int)$pdo->query('SELECT COUNT(*) FROM replies')->fetchColumn();
$paidPosts = (int)$pdo->query('SELECT COUNT(*) FROM posts WHERE price > 0')->fetchColumn();
$paidOrders = (int)$pdo->query('SELECT COUNT(*) FROM orders WHERE status = 1')->fetchColumn();
$totalRevenue = (float)$pdo->query('SELECT COALESCE(SUM(amount), 0) FROM orders WHERE status = 1')->fetchColumn();
$totalBalance = (float)$pdo->query('SELECT COALESCE(SUM(balance), 0) FROM users')->fetchColumn();
$totalBalanceOut = (float)$pdo->query('SELECT COALESCE(SUM(amount), 0) FROM balance_logs WHERE type = "income"')->fetchColumn();

// 最近注册用户
$recentUsers = $pdo->query('SELECT id, username, email, created_at FROM users ORDER BY created_at DESC LIMIT 5')->fetchAll();

// 最近订单
$recentOrders = $pdo->query("
    SELECT o.*, u.username, p.title as post_title
    FROM orders o
    JOIN users u ON o.user_id = u.id
    JOIN posts p ON o.post_id = p.id
    WHERE o.status = 1
    ORDER BY o.paid_at DESC
    LIMIT 10
")->fetchAll();

success([
    'totalUsers'   => $totalUsers,
    'totalPosts'   => $totalPosts,
    'totalReplies' => $totalReplies,
    'paidPosts'    => $paidPosts,
    'paidOrders'   => $paidOrders,
    'totalRevenue' => number_format($totalRevenue, 2, '.', ''),
    'totalBalance' => number_format($totalBalance, 2, '.', ''),
    'totalBalanceOut' => number_format($totalBalanceOut, 2, '.', ''),
    'recentUsers'  => $recentUsers,
    'recentOrders' => $recentOrders,
]);
