<?php
/**
 * GET /api/auth/me
 * 获取当前登录用户信息
 */

$auth = requireAuth();
$pdo = getDB();
$stmt = $pdo->prepare('SELECT id, username, email, role, avatar, signature, balance, created_at FROM users WHERE id = ?');
$stmt->execute([$auth['id']]);
$user = $stmt->fetch();

if (!$user) error('用户不存在', 404);

$user['id'] = (int)$user['id'];
success($user);
