<?php
/**
 * POST /api/auth/login
 * Body: { username, password }
 */

$data = json_decode(file_get_contents('php://input'), true);
$username = trim($data['username'] ?? '');
$password = $data['password'] ?? '';

if (!$username || !$password) error('请输入用户名和密码');

$pdo = getDB();
$stmt = $pdo->prepare('SELECT id, username, email, password, role, avatar, signature, balance FROM users WHERE username = ? OR email = ?');
$stmt->execute([$username, $username]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password'])) {
    error('用户名或密码错误');
}

$token = generateToken([
    'id' => (int)$user['id'],
    'username' => $user['username'],
    'role' => $user['role']
]);

unset($user['password']);
$user['id'] = (int)$user['id'];

success([
    'token' => $token,
    'user' => $user
], '登录成功');
