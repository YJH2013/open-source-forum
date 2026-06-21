<?php
/**
 * 论坛入口路由
 *
 * /api/*     → backend/
 * /install/* → install/
 * 其他       → 返回 app.html (SPA)
 */

// 获取真实请求路径（兼容 Apache / Nginx / IIS）
$uri = '/';
if (!empty($_SERVER['REQUEST_URI'])) {
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
} elseif (!empty($_SERVER['REDIRECT_URL'])) {
    $uri = parse_url($_SERVER['REDIRECT_URL'], PHP_URL_PATH);
} elseif (!empty($_SERVER['PHP_SELF'])) {
    $uri = $_SERVER['PHP_SELF'];
}

// 确保以 / 开头
if (empty($uri) || $uri[0] !== '/') {
    $uri = '/' . $uri;
}

// API 请求
if (str_starts_with($uri, '/api/')) {
    require __DIR__ . '/backend/index.php';
    exit;
}

// 安装程序
if (str_starts_with($uri, '/install')) {
    require __DIR__ . '/install/index.php';
    exit;
}

// 禁止缓存（确保每次都拿最新前端文件）
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// SPA 回退：返回 app.html（注入版本号防缓存）
$appHtml = __DIR__ . '/app.html';
if (file_exists($appHtml)) {
    $html = file_get_contents($appHtml);
    // 给所有 assets 引用加上版本号（用文件修改时间，每次构建都变）
    $version = filemtime($appHtml);
    $html = preg_replace('/(src|href)="\/assets\/([^"]+)"/', '$1="/assets/$2?v=' . $version . '"', $html);
    echo $html;
} else {
    header('HTTP/1.1 503 Service Unavailable');
    echo '网站文件不完整，请重新上传';
}
