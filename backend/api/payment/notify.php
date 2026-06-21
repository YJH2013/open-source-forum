<?php
/**
 * GET/POST /api/payment/notify
 * 码支付异步回调 — 验证签名 → 更新订单 → 加余额
 */

require_once __DIR__ . '/../../config/payment.php';

$params = $_REQUEST;

file_put_contents(__DIR__ . '/../../logs/pay_notify.log',
    date('Y-m-d H:i:s') . ' | ' . json_encode($params, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

if (!codepayVerifySign($params)) { echo 'fail'; exit; }

$outTradeNo = $params['out_trade_no'] ?? '';
$tradeNo   = $params['trade_no'] ?? '';
$tradeStatus = $params['trade_status'] ?? '';

if (strtoupper($tradeStatus) !== 'TRADE_SUCCESS') { echo 'success'; exit; }
if (!$outTradeNo) { echo 'fail'; exit; }

$pdo = getDB();

$stmt = $pdo->prepare('SELECT * FROM orders WHERE order_no = ?');
$stmt->execute([$outTradeNo]);
$order = $stmt->fetch();

if (!$order) { echo 'fail'; exit; }
if ($order['status'] == 1) { echo 'success'; exit; }

$isRecharge = ((int)$order['post_id'] === 0);

$pdo->beginTransaction();
try {
    // 更新订单
    $pdo->prepare("UPDATE orders SET status = 1, trade_no = ?, paid_at = NOW() WHERE id = ?")
        ->execute([$tradeNo, $order['id']]);

    if ($isRecharge) {
        // 充值订单：给充值用户加余额（1元 = 2余额）
        $balanceAdd = round($order['amount'] * 2, 2);
        $pdo->prepare('UPDATE users SET balance = balance + ? WHERE id = ?')
            ->execute([$balanceAdd, $order['user_id']]);
        $pdo->prepare('INSERT INTO balance_logs (user_id, amount, type, ref_id, remark) VALUES (?, ?, ?, ?, ?)')
            ->execute([$order['user_id'], $balanceAdd, 'income', $order['id'], '余额充值']);
    } else {
        // 购买帖子
        $pdo->prepare('INSERT IGNORE INTO purchases (user_id, post_id, order_id) VALUES (?, ?, ?)')
            ->execute([$order['user_id'], $order['post_id'], $order['id']]);

        // 给帖子作者加余额（1元 = 5余额）
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
    }

    $pdo->commit();
    echo 'success';
} catch (Exception $e) {
    $pdo->rollBack();
    echo 'fail';
}
exit;
