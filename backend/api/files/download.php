<?php
/**
 * GET /api/files/:id
 * 文件下载
 */

$pdo = getDB();
$fileId = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM files WHERE id = ?');
$stmt->execute([$fileId]);
$file = $stmt->fetch();

if (!$file) {
    http_response_code(404);
    exit('文件不存在');
}

$filePath = __DIR__ . '/../../uploads/' . $file['filename'];
if (!file_exists($filePath)) {
    http_response_code(404);
    exit('文件已被删除');
}

header('Content-Type: ' . $file['mime_type']);
header('Content-Disposition: attachment; filename="' . $file['original_name'] . '"');
header('Content-Length: ' . $file['file_size']);
readfile($filePath);
exit;
