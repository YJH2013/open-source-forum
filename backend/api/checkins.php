<?php
/**
 * GET /api/checkins
 * 获取今日签到列表
 */

$pdo = getDB();
$today = date('Y-m-d');

// 今日签到人数
$stmt = $pdo->prepare("SELECT COUNT(*) FROM checkins WHERE check_date = ?");
$stmt->execute([$today]);
$todayCount = (int)$stmt->fetchColumn();

// 今日签到列表（含用户信息和签到时间）
$list = $pdo->query("
    SELECT u.id, u.username, u.avatar, c.created_at,
           (SELECT COUNT(*) FROM checkins WHERE user_id = u.id) as total_checkins
    FROM checkins c
    JOIN users u ON c.user_id = u.id
    WHERE c.check_date = '$today'
    ORDER BY c.created_at ASC
")->fetchAll();

success([
    'todayCount' => $todayCount,
    'list' => $list,
]);
