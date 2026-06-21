<?php
// PHP 内置服务器路由
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $uri;
if ($uri !== '/' && file_exists($file) && is_file($file)) {
    if (pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
        return false;
    }
}
require __DIR__ . '/index.php';
