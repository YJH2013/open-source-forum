<?php
/**
 * POST /api/posts
 * Body (multipart/form-data): title, content, category_id, price, files[]
 */

$auth = requireAuth();

$title = trim($_POST['title'] ?? '');
$content = trim($_POST['content'] ?? '');
$categoryId = (int)($_POST['category_id'] ?? 0);
$price = floatval($_POST['price'] ?? 0);
$payMode = ($_POST['pay_mode'] ?? 'full') === 'partial' ? 'partial' : 'full';

if (mb_strlen($title) < 2) error('标题至少2个字符');
if (mb_strlen($content) < 2) error('内容至少2个字符');
if ($categoryId <= 0) error('请选择版块');
require_once __DIR__ . '/../../config/payment.php';

if ($price < 0) error('价格不能为负数');
if ($price > 0 && $price < 0.1) error('最低0.1元');
$maxPrice = (float)(sysConfig('max_price', '50') ?: '50');
if ($price > $maxPrice) error('价格不能超过' . $maxPrice . '元');

// 文件大小检查（默认100MB）
$maxUploadMB = (float)(sysConfig('max_upload_mb', '100') ?: '100');
$maxBytes = (int)($maxUploadMB * 1048576);
if (!empty($_FILES['files']['name'][0])) {
    foreach ($_FILES['files']['size'] as $size) {
        if ($size > $maxBytes) error('单个文件不能超过' . $maxUploadMB . 'MB');
    }
}

$pdo = getDB();

// 检查版块存在及权限
$stmt = $pdo->prepare('SELECT id, only_admin FROM categories WHERE id = ?');
$stmt->execute([$categoryId]);
$cat = $stmt->fetch();
if (!$cat) error('版块不存在');
if ($cat['only_admin'] && $auth['role'] !== 'admin') error('该版块仅限管理员发帖');

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('INSERT INTO posts (title, content, user_id, category_id, price, pay_mode) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$title, $content, $auth['id'], $categoryId, $price, $payMode]);
    $postId = $pdo->lastInsertId();

    // 更新版块帖子数
    $pdo->prepare('UPDATE categories SET post_count = post_count + 1 WHERE id = ?')->execute([$categoryId]);

    // 处理上传文件
    if (!empty($_FILES['files']['name'][0])) {
        $stmt = $pdo->prepare('INSERT INTO files (filename, original_name, file_size, mime_type, post_id, user_id) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($_FILES['files']['name'] as $i => $name) {
            if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) continue;
            $tmpName = $_FILES['files']['tmp_name'][$i];
            $size = $_FILES['files']['size'][$i];
            $mime = $_FILES['files']['type'][$i] ?: 'application/octet-stream';

            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $savedName = uniqid() . ($ext ? '.' . $ext : '');

            $uploadDir = __DIR__ . '/../../uploads/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            move_uploaded_file($tmpName, $uploadDir . $savedName);

            $stmt->execute([$savedName, $name, $size, $mime, $postId, $auth['id']]);
        }
    }

    $pdo->commit();

    success(['id' => (int)$postId], '发帖成功');
} catch (Exception $e) {
    $pdo->rollBack();
    error('发帖失败: ' . $e->getMessage(), 500);
}
