<?php
/**
 * GET /api/auth/oauth/login?type=qq
 * 获取第三方登录跳转地址（聚合登录 Step1）
 *
 * 返回: { url: "https://graph.qq.com/oauth2.0/...", type: "qq" }
 * 前端拿到 url 后跳转过去
 */

require_once __DIR__ . '/../../utils/oauth_login.php';

if (!OAuthLogin::isEnabled()) {
    error('第三方登录功能未开启');
}

$type = trim($_GET['type'] ?? 'qq');
$allowedTypes = ['qq', 'alipay', 'wechat', 'weibo', 'baidu', 'github', 'gitee'];
if (!in_array($type, $allowedTypes)) {
    error('不支持的登录方式: ' . $type);
}

$oauth = new OAuthLogin();
if (!$oauth->isConfigured()) {
    error('聚合登录未配置，请在后台设置 appid 和 appkey');
}

// 回调地址：当前后端地址
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost:8080';
$redirectUri = "{$scheme}://{$host}/api/auth/oauth/callback";

$result = $oauth->getAuthUrl($type, $redirectUri);

if (!$result || empty($result['url'])) {
    error('获取登录地址失败，请检查 appid 和 appkey 配置', 500);
}

success([
    'url'    => $result['url'],
    'qrcode' => $result['qrcode'] ?? '',
    'type'   => $result['type'],
]);
