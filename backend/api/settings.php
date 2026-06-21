<?php
/**
 * GET /api/settings  — 获取公开设置（无需登录）
 * 返回白名单中的公开配置项
 */

$pdo = getDB();
$stmt = $pdo->query("SELECT `key`, `value` FROM settings WHERE `key` IN ('site_name', 'forum_declaration', 'forum_ad', 'forum_post_footer', 'forum_intro', 'email_verify_enabled')");
$rows = $stmt->fetchAll();
$settings = [];
foreach ($rows as $row) {
    $settings[$row['key']] = $row['value'];
}
success($settings);
