<?php
/**
 * POST /api/auth/send-code
 * 发送邮箱验证码
 * Body: { email }
 *
 * 限制：同一邮箱 60 秒内只能发一次，同一 IP 60 秒内最多 3 次
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error('仅支持 POST 请求', 405);
}

// 检查开关
require_once __DIR__ . '/../../utils/mailer.php';
if (!isEmailVerifyEnabled()) {
    error('邮箱验证功能未开启');
}

$data = json_decode(file_get_contents('php://input'), true);
$email = trim($data['email'] ?? '');

if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    error('请输入有效的邮箱地址');
}

$pdo = getDB();

// 检查是否已注册
$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$stmt->execute([$email]);
if ($stmt->fetch()) {
    error('该邮箱已被注册');
}

// 频率限制 — 同一邮箱 60 秒内只能发一次
$stmt = $pdo->prepare("SELECT created_at FROM email_codes WHERE email = ? AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND) ORDER BY created_at DESC LIMIT 1");
$stmt->execute([$email]);
if ($stmt->fetch()) {
    error('发送太频繁，请 60 秒后再试');
}

// 同一 IP 60 秒内最多 3 次
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM email_codes WHERE ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)");
$stmt->execute([$ip]);
$row = $stmt->fetch();
if (($row['cnt'] ?? 0) >= 3) {
    error('操作太频繁，请稍后再试');
}

// 生成验证码
$code = sprintf('%06d', random_int(0, 999999));

// 删除旧验证码
$pdo->prepare("DELETE FROM email_codes WHERE email = ?")->execute([$email]);

// 保存新验证码
$pdo->prepare("INSERT INTO email_codes (email, code, ip, created_at) VALUES (?, ?, ?, NOW())")
    ->execute([$email, $code, $ip]);

// 发送邮件
$mailer = getMailer();

// 获取站点名称
$stmt = $pdo->prepare("SELECT `value` FROM settings WHERE `key` = 'site_name'");
$stmt->execute();
$siteName = $stmt->fetchColumn() ?: '论坛';

$subject = "{$siteName} - 邮箱验证码";
$body = <<<HTML
<div style="max-width:480px;margin:0 auto;padding:30px;font-family:Arial,sans-serif;background:#f9fafb;border-radius:12px">
  <h2 style="color:#4f46e5;margin-bottom:8px">{$siteName}</h2>
  <p style="color:#374151;font-size:16px">您的邮箱验证码为：</p>
  <div style="background:#4f46e5;color:#fff;font-size:32px;font-weight:700;text-align:center;padding:20px;border-radius:8px;letter-spacing:8px;margin:16px 0">{$code}</div>
  <p style="color:#6b7280;font-size:13px">验证码 5 分钟内有效，请勿泄露给他人。</p>
  <hr style="border:0;border-top:1px solid #e5e7eb;margin:20px 0">
  <p style="color:#9ca3af;font-size:12px">如果这不是您的操作，请忽略此邮件。</p>
</div>
HTML;

$result = $mailer->send($email, $subject, $body);

if (!$result[0]) {
    // 发送失败，删除验证码记录
    $pdo->prepare("DELETE FROM email_codes WHERE email = ?")->execute([$email]);
    error('邮件发送失败：' . $result[1], 500);
}

success(null, '验证码已发送至 ' . $email);
