<?php
/**
 * API 路由入口
 * 用法: php -S localhost:8080 -t backend/   （开发测试）
 *      Apache + .htaccess                   （生产环境）
 */

// 开放 CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/utils/response.php';
require_once __DIR__ . '/middleware/auth.php';

// 自动初始化数据库
initDatabase();

// 解析请求 URI
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = rtrim($uri, '/');
$method = $_SERVER['REQUEST_METHOD'];

// ==================== 路由分发 ====================

// --- 认证 ---
if ($uri === '/api/auth/register' && $method === 'POST') {
    require __DIR__ . '/api/auth/register.php';
} elseif ($uri === '/api/auth/login' && $method === 'POST') {
    require __DIR__ . '/api/auth/login.php';
} elseif ($uri === '/api/auth/me' && $method === 'GET') {
    require __DIR__ . '/api/auth/me.php';
} elseif ($uri === '/api/auth/send-code' && $method === 'POST') {
    require __DIR__ . '/api/auth/send_code.php';
} elseif ($uri === '/api/auth/ping' && $method === 'POST') {
    require __DIR__ . '/api/auth/ping.php';
} elseif ($uri === '/api/auth/checkin' && $method === 'POST') {
    require __DIR__ . '/api/auth/checkin.php';
}
// --- 第三方登录 ---
elseif ($uri === '/api/auth/oauth/login' && $method === 'GET') {
    require __DIR__ . '/api/auth/oauth_login.php';
} elseif (preg_match('#^/api/auth/oauth/callback#', $uri) && $method === 'GET') {
    require __DIR__ . '/api/auth/oauth_callback.php';
}
// --- 帖子 ---
elseif ($uri === '/api/posts' && $method === 'GET') {
    require __DIR__ . '/api/posts/list.php';
} elseif ($uri === '/api/posts' && $method === 'POST') {
    require __DIR__ . '/api/posts/create.php';
} elseif (preg_match('#^/api/posts/(\d+)$#', $uri, $m) && $method === 'GET') {
    $_GET['id'] = $m[1];
    require __DIR__ . '/api/posts/detail.php';
} elseif (preg_match('#^/api/posts/(\d+)/reply$#', $uri, $m) && $method === 'POST') {
    $_GET['id'] = $m[1];
    require __DIR__ . '/api/posts/reply.php';
}
// --- 版块 ---
elseif ($uri === '/api/categories' && $method === 'GET') {
    require __DIR__ . '/api/categories/list.php';
}
// --- 文件 ---
elseif ($uri === '/api/files/upload' && $method === 'POST') {
    require __DIR__ . '/api/files/upload.php';
} elseif (preg_match('#^/api/files/(\d+)$#', $uri, $m) && $method === 'GET') {
    $_GET['id'] = $m[1];
    require __DIR__ . '/api/files/download.php';
}
// --- 用户 ---
elseif (preg_match('#^/api/users/(\d+)$#', $uri, $m) && $method === 'GET') {
    $_GET['id'] = $m[1];
    require __DIR__ . '/api/users/profile.php';
}
// --- 公告（公开） ---
elseif ($uri === '/api/announcements' && $method === 'GET') {
    require __DIR__ . '/api/announcements.php';
}
// --- 签到（公开） ---
elseif ($uri === '/api/checkins' && $method === 'GET') {
    require __DIR__ . '/api/checkins.php';
}
// --- 诊断 ---
elseif ($uri === '/api/ping' && $method === 'GET') {
    require __DIR__ . '/api/ping.php';
}
// --- 搜索 ---
elseif ($uri === '/api/search' && $method === 'GET') {
    require __DIR__ . '/api/search.php';
}
// --- 统计 ---
elseif ($uri === '/api/stats' && $method === 'GET') {
    require __DIR__ . '/api/stats.php';
}
// --- 公开设置 ---
elseif ($uri === '/api/settings' && $method === 'GET') {
    require __DIR__ . '/api/settings.php';
}
// --- 支付 ---
elseif ($uri === '/api/payment/create' && $method === 'POST') {
    require __DIR__ . '/api/payment/create.php';
} elseif ($uri === '/api/payment/check' && $method === 'GET') {
    require __DIR__ . '/api/payment/check.php';
} elseif ($uri === '/api/payment/balance' && $method === 'POST') {
    require __DIR__ . '/api/payment/balance.php';
} elseif ($uri === '/api/payment/notify' && ($method === 'GET' || $method === 'POST')) {
    require __DIR__ . '/api/payment/notify.php';
} elseif ($uri === '/api/payment/recharge' && $method === 'POST') {
    require __DIR__ . '/api/payment/recharge.php';
}
// --- 管理后台 ---
elseif ($uri === '/api/admin/dashboard' && $method === 'GET') {
    require __DIR__ . '/api/admin/dashboard.php';
} elseif ($uri === '/api/admin/settings' && ($method === 'GET' || $method === 'POST')) {
    require __DIR__ . '/api/admin/settings.php';
} elseif ($uri === '/api/admin/change-password' && $method === 'POST') {
    require __DIR__ . '/api/admin/change_password.php';
} elseif ($uri === '/api/admin/users' && ($method === 'GET' || $method === 'POST')) {
    require __DIR__ . '/api/admin/users.php';
} elseif (preg_match('#^/api/admin/categories(\?.*)?$#', $uri) && in_array($method, ['GET','POST','PUT','DELETE'])) {
    require __DIR__ . '/api/admin/categories.php';
} elseif (preg_match('#^/api/admin/announcements(\?.*)?$#', $uri) && in_array($method, ['GET','POST','PUT','DELETE'])) {
    require __DIR__ . '/api/admin/announcements.php';
} elseif (preg_match('#^/api/admin/posts(\?.*)?$#', $uri) && in_array($method, ['GET','DELETE'])) {
    require __DIR__ . '/api/admin/posts.php';
}
// --- 404 ---
else {
    error('接口不存在', 404);
}
