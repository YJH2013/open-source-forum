<?php
/**
 * GET  /api/admin/settings    — 获取所有设置（管理员）
 * POST /api/admin/settings    — 保存设置 { key: value, ... }
 */

$auth = requireAuth();
if ($auth['role'] !== 'admin') error('需要管理员权限', 403);

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->query('SELECT `key`, `value` FROM settings');
    $rows = $stmt->fetchAll();
    $settings = [];
    foreach ($rows as $row) {
        $settings[$row['key']] = $row['value'];
    }
    success($settings);
}

elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data || !is_array($data)) error('数据格式错误');

    $stmt = $pdo->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
    foreach ($data as $key => $value) {
        // 只允许白名单中的 key
        $allowed = ['codepay_pid', 'codepay_key', 'codepay_host', 'site_name', 'max_upload_gb', 'max_price',
                     'email_verify_enabled', 'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from_email', 'smtp_from_name',
                     'oauth_enabled', 'oauth_appid', 'oauth_appkey',
                     'forum_declaration', 'forum_ad', 'forum_post_footer', 'forum_intro',
                     'max_upload_mb'];
        if (!in_array($key, $allowed)) continue;
        $stmt->execute([$key, trim($value)]);
    }
    success(null, '保存成功');
}
