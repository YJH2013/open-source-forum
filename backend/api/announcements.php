<?php
/**
 * GET /api/announcements
 * 公开接口：获取最新公告列表
 */

$pdo = getDB();
$list = $pdo->query('SELECT id, title, content, created_at FROM announcements ORDER BY created_at DESC LIMIT 5')->fetchAll();
success($list);
