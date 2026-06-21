<?php
/**
 * POST /api/auth/checkin
 * 每日签到（需登录）
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error('仅支持 POST 请求', 405);
}

$auth = requireAuth();
$pdo = getDB();
$userId = $auth['id'];
$today = date('Y-m-d');

// 检查今天是否已签到
$stmt = $pdo->prepare("SELECT id, created_at FROM checkins WHERE user_id = ? AND check_date = ?");
$stmt->execute([$userId, $today]);
$existing = $stmt->fetch();

if ($existing) {
    error('今日已签到 (' . $existing['created_at'] . ')');
}

// 签到
$pdo->prepare("INSERT INTO checkins (user_id, check_date) VALUES (?, ?)")->execute([$userId, $today]);

// 统计总签到次数
$stmt = $pdo->prepare("SELECT COUNT(*) FROM checkins WHERE user_id = ?");
$stmt->execute([$userId]);
$total = (int)$stmt->fetchColumn();
// 连续签到天数
$streak = 0;
$date = new DateTime($today);
while (true) {
    $stmt = $pdo->prepare("SELECT id FROM checkins WHERE user_id = ? AND check_date = ?");
    $stmt->execute([$userId, $date->format('Y-m-d')]);
    if ($stmt->fetch()) {
        $streak++;
        $date->modify('-1 day');
    } else {
        break;
    }
}

success([
    'total' => $total,
    'streak' => $streak,
], '签到成功');
