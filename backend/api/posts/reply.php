<?php
/**
 * POST /api/posts/:id/reply
 * Body: content, files[]
 */

$auth = requireAuth();
$postId = (int)($_GET['id'] ?? 0);
$content = trim($_POST['content'] ?? '');

if (mb_strlen($content) < 1) error('回复内容不能为空');

require_once __DIR__ . '/../../config/payment.php';

// 文件大小检查（默认100MB）
$maxUploadMB = (float)(sysConfig('max_upload_mb', '100') ?: '100');
$maxBytes = (int)($maxUploadMB * 1048576);
if (!empty($_FILES['files']['name'][0])) {
    foreach ($_FILES['files']['size'] as $size) {
        if ($size > $maxBytes) error('单个文件不能超过' . $maxUploadMB . 'MB');
    }
}

$pdo = getDB();

$stmt = $pdo->prepare('SELECT id, is_locked FROM posts WHERE id = ?');
$stmt->execute([$postId]);
$post = $stmt->fetch();

if (!$post) error('帖子不存在', 404);
if ($post['is_locked']) error('帖子已锁定，无法回复');

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('INSERT INTO replies (content, user_id, post_id) VALUES (?, ?, ?)');
    $stmt->execute([$content, $auth['id'], $postId]);
    $replyId = $pdo->lastInsertId();

    $pdo->prepare('UPDATE posts SET reply_count = reply_count + 1, updated_at = NOW() WHERE id = ?')->execute([$postId]);

    // 处理文件
    if (!empty($_FILES['files']['name'][0])) {
        $stmt = $pdo->prepare('INSERT INTO files (filename, original_name, file_size, mime_type, reply_id, user_id) VALUES (?, ?, ?, ?, ?, ?)');
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

            $stmt->execute([$savedName, $name, $size, $mime, $replyId, $auth['id']]);
        }
    }

    $pdo->commit();
    success(['id' => (int)$replyId], '回复成功');
} catch (Exception $e) {
    $pdo->rollBack();
    error('回复失败: ' . $e->getMessage(), 500);
}
