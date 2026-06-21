<?php
/**
 * GET /api/auth/oauth/callback?code=xxx&type=qq
 * 聚合登录回调 — Step3/4
 *
 * 1. 接收 code 和 type
 * 2. 调用聚合平台获取用户信息
 * 3. 查找或创建用户
 * 4. 生成 JWT token
 * 5. 重定向到前端页面，带上 token
 */

require_once __DIR__ . '/../../utils/oauth_login.php';

$code = trim($_GET['code'] ?? '');
$type = trim($_GET['type'] ?? 'qq');

if (!$code) {
    die('登录失败：未收到授权码。请返回重新登录。<br><a href="/">← 返回首页</a>');
}

$oauth = new OAuthLogin();
if (!$oauth->isConfigured()) {
    die('聚合登录未配置。请联系管理员。<br><a href="/">← 返回首页</a>');
}

// Step4: 通过 code 换取用户信息
$userInfo = $oauth->getUserInfo($code, $type);

if (!$userInfo || empty($userInfo['social_uid'])) {
    die('获取用户信息失败（可能是 code 已过期或 appid/appkey 配置错误）。请返回重新登录。<br><a href="/">← 返回首页</a>');
}

$socialUid = $userInfo['social_uid'];

try {
    $user = findOrCreateOAuthUser($type, $socialUid, $userInfo);
} catch (\Exception $e) {
    die('登录失败：' . $e->getMessage() . '<br><a href="/">← 返回首页</a>');
}

// 生成 JWT token
$token = generateToken([
    'id'       => (int)$user['id'],
    'username' => $user['username'],
    'role'     => $user['role'],
]);

// 确定前端地址
// 从请求来源推断前端地址，或使用配置
$frontendUrl = $_SERVER['HTTP_REFERER']
    ?? (($_SERVER['HTTP_HOST'] ?? '') === 'localhost:8080' ? 'http://localhost:5173' : '/');

// 去除路径，只保留协议+域名+端口
$parsed = parse_url($frontendUrl);
$frontendOrigin = ($parsed['scheme'] ?? 'http') . '://' . ($parsed['host'] ?? 'localhost:5173');
if (!empty($parsed['port'])) {
    $frontendOrigin .= ':' . $parsed['port'];
}

// 如果 referer 不可用（比如来自第三方页面），使用默认前端地址
if (!$frontendUrl || strpos($frontendUrl, 'graph.qq.com') !== false || strpos($frontendUrl, '0mz.cn') !== false) {
    $frontendOrigin = 'http://localhost:5173';
}

// 重定向到前端，带上 token
$redirectUrl = $frontendOrigin . '/?oauth_token=' . urlencode($token)
    . '&oauth_username=' . urlencode($user['username'])
    . '&oauth_avatar=' . urlencode($user['avatar'] ?? '');

header('Location: ' . $redirectUrl);
exit;
