<?php
/**
 * POST /api/admin/change-password
 * 管理员修改自己的密码
 * Body: { old_password, new_password }
 */

$auth = requireAuth();
if ($auth['role'] !== 'admin') error('需要管理员权限', 403);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error('仅支持 POST 请求', 405);
}

$data = json_decode(file_get_contents('php://input'), true);
$oldPassword = $data['old_password'] ?? '';
$newPassword = $data['new_password'] ?? '';

if (!$oldPassword || !$newPassword) {
    error('请输入原密码和新密码');
}

if (strlen($newPassword) < 6) {
    error('新密码至少需要6位');
}

if ($oldPassword === $newPassword) {
    error('新密码不能与原密码相同');
}

$pdo = getDB();

// 查询当前用户
$stmt = $pdo->prepare('SELECT id, password FROM users WHERE id = ?');
$stmt->execute([$auth['id']]);
$user = $stmt->fetch();

if (!$user) {
    error('用户不存在', 404);
}

// 验证原密码
if (!password_verify($oldPassword, $user['password'])) {
    error('原密码不正确');
}

// 更新密码
$newHash = password_hash($newPassword, PASSWORD_BCRYPT);
$pdo->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([$newHash, $auth['id']]);

success(null, '密码修改成功');
