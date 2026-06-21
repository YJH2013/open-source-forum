<?php
/**
 * POST /api/files/upload
 * 通用文件上传（独立于发帖）
 */

$auth = requireAuth();

if (empty($_FILES['file'])) error('请选择文件');

$file = $_FILES['file'];
if ($file['error'] !== UPLOAD_ERR_OK) error('上传失败');

if ($file['size'] > 10 * 1024 * 1024) error('文件不能超过10MB');

$ext = pathinfo($file['name'], PATHINFO_EXTENSION);
$savedName = uniqid() . ($ext ? '.' . $ext : '');

$uploadDir = __DIR__ . '/../../uploads/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
move_uploaded_file($file['tmp_name'], $uploadDir . $savedName);

$pdo = getDB();
$stmt = $pdo->prepare('INSERT INTO files (filename, original_name, file_size, mime_type, user_id) VALUES (?, ?, ?, ?, ?)');
$stmt->execute([$savedName, $file['name'], $file['size'], $file['type'], $auth['id']]);

success([
    'id' => (int)$pdo->lastInsertId(),
    'filename' => $savedName,
    'original_name' => $file['name'],
    'file_size' => $file['size']
], '上传成功');
