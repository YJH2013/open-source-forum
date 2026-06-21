<?php
/**
 * POST /api/auth/ping
 * 心跳接口：更新当前用户的在线状态
 * 前端每 60 秒调用一次
 */

$auth = requireAuth();
$pdo = getDB();
$pdo->prepare("UPDATE users SET last_active = NOW() WHERE id = ?")->execute([$auth['id']]);
success(['last_active' => date('Y-m-d H:i:s')], 'pong');
