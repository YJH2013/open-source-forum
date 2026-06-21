<?php
/**
 * GET /api/ping — 公开诊断接口
 * 返回服务器状态，用于确认 API 路由是否正常
 */
$pdo = getDB();
$userCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$postCount = (int)$pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn();

success([
    'php_version' => PHP_VERSION,
    'time'        => date('Y-m-d H:i:s'),
    'user_count'  => $userCount,
    'post_count'  => $postCount,
    'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
], 'OK');
