<?php
/**
 * POST /api/payment/balance
 * Body: { post_id }
 * 使用余额支付查看付费内容
 */

$auth = requireAuth();
$data = json_decode(file_get_contents('php://input'), true);
$postId = (int)($data['post_id'] ?? 0);

if ($postId <= 0) error('参数错误');

$pdo = getDB();

// 帖子信息
$stmt = $pdo->prepare('SELECT id, title, price, user_id FROM posts WHERE id = ?');
$stmt->execute([$postId]);
$post = $stmt->fetch();
if (!$post) error('帖子不存在', 404);

$price = (float)$post['price'];
if ($price <= 0) error('该帖子无需付费');
if ((int)$post['user_id'] === (int)$auth['id']) error('您是作者，无需付费');

// 检查已购买
$stmt = $pdo->prepare('SELECT id FROM purchases WHERE user_id = ? AND post_id = ?');
$stmt->execute([$auth['id'], $postId]);
if ($stmt->fetch()) error('已购买过');

// 查余额
$stmt = $pdo->prepare('SELECT balance FROM users WHERE id = ?');
$stmt->execute([$auth['id']]);
$user = $stmt->fetch();

if ((float)$user['balance'] < $price) error('余额不足，当前余额 ¥' . number_format($user['balance'], 2));

$pdo->beginTransaction();
try {
    // 扣除付款人余额（按1:1扣除，因为是余额消费）
    $pdo->prepare('UPDATE users SET balance = balance - ? WHERE id = ?')
        ->execute([$price, $auth['id']]);
    $pdo->prepare('INSERT INTO balance_logs (user_id, amount, type, ref_id, remark) VALUES (?, ?, ?, ?, ?)')
        ->execute([$auth['id'], -$price, 'pay', $postId, '余额支付查看帖子']);

    // 创建订单记录（虚拟订单）
    $orderNo = 'BAL' . date('YmdHis') . rand(100, 999);
    $pdo->prepare('INSERT INTO orders (order_no, user_id, post_id, amount, status, paid_at) VALUES (?, ?, ?, ?, 1, NOW())')
        ->execute([$orderNo, $auth['id'], $postId, $price]);
    $orderId = $pdo->lastInsertId();

    // 记录购买
    $pdo->prepare('INSERT IGNORE INTO purchases (user_id, post_id, order_id) VALUES (?, ?, ?)')
        ->execute([$auth['id'], $postId, $orderId]);

    // 余额兑换给作者（1元 = 5余额）
    if ((int)$post['user_id'] !== (int)$auth['id']) {
        $bonus = round($price * 5, 2);
        $pdo->prepare('UPDATE users SET balance = balance + ? WHERE id = ?')
            ->execute([$bonus, $post['user_id']]);
        $pdo->prepare('INSERT INTO balance_logs (user_id, amount, type, ref_id, remark) VALUES (?, ?, ?, ?, ?)')
            ->execute([$post['user_id'], $bonus, 'income', $orderId, '付费查看收入']);
    }

    $pdo->commit();
    success(['balance' => round($user['balance'] - $price, 2)], '支付成功，内容已解锁');
} catch (Exception $e) {
    $pdo->rollBack();
    error('支付失败: ' . $e->getMessage(), 500);
}
