<?php
/**
 * GET /api/payment/check?order_no=xxx
 */

$auth = requireAuth();
require_once __DIR__ . '/../../config/payment.php';

$orderNo = $_GET['order_no'] ?? '';
if (!$orderNo) error('缺少订单号');

$pdo = getDB();
$stmt = $pdo->prepare('SELECT * FROM orders WHERE order_no = ? AND user_id = ?');
$stmt->execute([$orderNo, $auth['id']]);
$order = $stmt->fetch();

if (!$order) error('订单不存在', 404);

// 本地已支付
if ($order['status'] == 1) {
    success(['paid' => true, 'amount' => (float)$order['amount'], 'post_id' => (int)$order['post_id']]);
    return;
}

// 远程查询
$remote = codepayQueryOrder($orderNo);

if ($remote && $remote['paid']) {
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE orders SET status = 1, trade_no = ?, paid_at = NOW() WHERE order_no = ?")
            ->execute([$remote['trade_no'], $orderNo]);
        $pdo->prepare('INSERT IGNORE INTO purchases (user_id, post_id, order_id) VALUES (?, ?, ?)')
            ->execute([$order['user_id'], $order['post_id'], $order['id']]);

        // 给作者加余额
        $stmt = $pdo->prepare('SELECT user_id FROM posts WHERE id = ?');
        $stmt->execute([$order['post_id']]);
        $post = $stmt->fetch();
        if ($post && (int)$post['user_id'] !== (int)$order['user_id']) {
            $balanceAdd = round($order['amount'] * 5, 2);
            $pdo->prepare('UPDATE users SET balance = balance + ? WHERE id = ?')
                ->execute([$balanceAdd, $post['user_id']]);
            $pdo->prepare('INSERT INTO balance_logs (user_id, amount, type, ref_id, remark) VALUES (?, ?, ?, ?, ?)')
                ->execute([$post['user_id'], $balanceAdd, 'income', $order['id'], '付费查看收入']);
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
    }
    success(['paid' => true, 'amount' => (float)$order['amount'], 'post_id' => (int)$order['post_id']]);
    return;
}

success(['paid' => false, 'amount' => (float)$order['amount'], 'post_id' => (int)$order['post_id']]);
