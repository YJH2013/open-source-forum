<?php
/**
 * POST /api/auth/register
 * Body: { username, email, password, code? }
 *
 * 如果后台开启了邮箱验证，则需要传 code（验证码）
 */

$data = json_decode(file_get_contents('php://input'), true);
$username = trim($data['username'] ?? '');
$email = trim($data['email'] ?? '');
$password = $data['password'] ?? '';
$code = trim($data['code'] ?? '');

if (mb_strlen($username) < 2) error('用户名至少2个字符');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) error('请输入有效的邮箱');
if (strlen($password) < 6) error('密码至少6个字符');

$pdo = getDB();

// 重复检查
$stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
$stmt->execute([$username, $email]);
if ($stmt->fetch()) error('用户名或邮箱已被注册');

// 检查是否开启了邮箱验证
require_once __DIR__ . '/../../utils/mailer.php';
$verifyEnabled = isEmailVerifyEnabled();

if ($verifyEnabled) {
    if (!$code) error('请输入邮箱验证码');
    if (!preg_match('/^\d{6}$/', $code)) error('验证码格式不正确');

    // 验证验证码
    $stmt = $pdo->prepare("SELECT id FROM email_codes WHERE email = ? AND code = ? AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
    $stmt->execute([$email, $code]);
    $codeRow = $stmt->fetch();

    if (!$codeRow) {
        error('验证码错误或已过期（5分钟内有效）');
    }

    // 验证通过，删除已使用的验证码
    $pdo->prepare("DELETE FROM email_codes WHERE email = ?")->execute([$email]);
}

$hash = password_hash($password, PASSWORD_BCRYPT);
$pdo->prepare('INSERT INTO users (username, email, password) VALUES (?, ?, ?)')
    ->execute([$username, $email, $hash]);

$userId = $pdo->lastInsertId();

$token = generateToken(['id' => $userId, 'username' => $username, 'role' => 'user']);

success([
    'token' => $token,
    'user' => ['id' => (int)$userId, 'username' => $username, 'email' => $email, 'role' => 'user', 'avatar' => '', 'signature' => '', 'balance' => 0]
], '注册成功');
