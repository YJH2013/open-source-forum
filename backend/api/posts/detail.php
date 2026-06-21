<?php
/**
 * GET /api/posts/:id
 */

$pdo = getDB();
$postId = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT p.*, u.username, u.avatar, u.signature,
           c.name as category_name, c.slug as category_slug
    FROM posts p
    JOIN users u ON p.user_id = u.id
    JOIN categories c ON p.category_id = c.id
    WHERE p.id = ?
");
$stmt->execute([$postId]);
$post = $stmt->fetch();

if (!$post) error('帖子不存在', 404);

// 更新浏览量
$pdo->prepare('UPDATE posts SET view_count = view_count + 1 WHERE id = ?')->execute([$postId]);
$post['view_count'] = (int)$post['view_count'] + 1;

// 获取当前用户（可能未登录）
$currentUser = getAuthUser();

// 付费检查
$isAuthor = $currentUser && (int)$currentUser['id'] === (int)$post['user_id'];
$isPurchased = false;
$price = (float)($post['price'] ?? 0);
$payMode = $post['pay_mode'] ?? 'full';
$purchaseCount = 0;

if ($price > 0) {
    $stmt = $pdo->prepare('SELECT COUNT(*) as cnt FROM purchases WHERE post_id = ?');
    $stmt->execute([$postId]);
    $purchaseCount = (int)$stmt->fetch()['cnt'];

    if ($currentUser && !$isAuthor) {
        $stmt = $pdo->prepare('SELECT id FROM purchases WHERE user_id = ? AND post_id = ?');
        $stmt->execute([$currentUser['id'], $postId]);
        $isPurchased = (bool)$stmt->fetch();
    }
}

$canView = ($price == 0 || $isAuthor || $isPurchased);
$fullContent = $post['content'];
$contentPreview = '';

if (!$canView && $payMode === 'full') {
    // 全部付费模式：隐藏内容
    $contentPreview = mb_substr($fullContent, 0, 150);
    if (mb_strlen($fullContent) > 150) $contentPreview .= '...';
} elseif (!$canView && $payMode === 'partial') {
    // 部分付费模式：内容可见
    $contentPreview = '';
}

// 回复列表
$stmt = $pdo->prepare("
    SELECT r.*, u.username, u.avatar, u.role
    FROM replies r
    JOIN users u ON r.user_id = u.id
    WHERE r.post_id = ?
    ORDER BY r.created_at ASC
");
$stmt->execute([$postId]);
$replies = $stmt->fetchAll();

// 帖子附件
$stmt = $pdo->prepare('SELECT * FROM files WHERE post_id = ?');
$stmt->execute([$postId]);
$postFiles = $stmt->fetchAll();

// 回复附件
$replyIds = array_column($replies, 'id');
$replyFiles = [];
if ($replyIds) {
    $placeholders = implode(',', array_fill(0, count($replyIds), '?'));
    $stmt = $pdo->prepare("SELECT * FROM files WHERE reply_id IN ($placeholders)");
    $stmt->execute($replyIds);
    foreach ($stmt->fetchAll() as $f) {
        $replyFiles[$f['reply_id']][] = $f;
    }
}

// 类型转换
foreach (['id','user_id','category_id','is_pinned','is_locked','view_count','reply_count'] as $k) {
    $post[$k] = (int)$post[$k];
}
$post['price'] = $price;

success([
    'post' => $post,
    'replies' => $replies,
    'postFiles' => $postFiles,
    'replyFiles' => $replyFiles,
    // 付费相关
    'paid' => [
        'price' => $price,
        'payMode' => $payMode,
        'isAuthor' => $isAuthor,
        'isPurchased' => $isPurchased,
        'purchaseCount' => $purchaseCount,
        'canView' => $canView,
        'contentPreview' => $contentPreview,
        'fullContent' => $canView || $payMode === 'partial' ? $fullContent : null,
        // partial 模式下，文件需要付费才能下载
        'canDownload' => $canView,
    ]
]);
