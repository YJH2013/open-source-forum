<?php
/**
 * POST /api/payment/create
 * Body: { post_id, pay_type }
 * pay_type: "alipay" | "wxpay"，默认 "alipay"
 */

$auth = requireAuth();
require_once __DIR__ . '/../../config/payment.php';

$data = json_decode(file_get_contents('php://input'), true);
$postId = (int)($data['post_id'] ?? 0);
$payType = ($data['pay_type'] ?? 'alipay') === 'wxpay' ? 'wxpay' : 'alipay';

if ($postId <= 0) error('参数错误');

// 检查支付配置
if (!CODEPAY_PID || !CODEPAY_KEY) {
    error('支付接口未配置（pid/key为空），请在后台→码支付配置中填写');
}

$pdo = getDB();

// 检查帖子
$stmt = $pdo->prepare('SELECT id, title, price, user_id FROM posts WHERE id = ?');
$stmt->execute([$postId]);
$post = $stmt->fetch();

if (!$post) error('帖子不存在', 404);
if ((float)$post['price'] <= 0) error('该帖子无需付费');
if ((int)$post['user_id'] === (int)$auth['id']) error('您是作者，无需付费');

// 检查是否已购买
$stmt = $pdo->prepare('SELECT id FROM purchases WHERE user_id = ? AND post_id = ?');
$stmt->execute([$auth['id'], $postId]);
if ($stmt->fetch()) error('您已购买过该帖子');

$amount = (float)$post['price'];

// 生成商户订单号
$outTradeNo = date('YmdHis') . rand(1000, 9999);

// 创建本地订单
$stmt = $pdo->prepare('INSERT INTO orders (order_no, user_id, post_id, amount) VALUES (?, ?, ?, ?)');
$stmt->execute([$outTradeNo, $auth['id'], $postId, $amount]);

// 调用易支付
$payResult = codepayCreateOrder($outTradeNo, $amount, $post['title'], $payType, (string)$postId);

if (!$payResult['success']) {
    error($payResult['error'], 500);
}

// 保存交易号
if ($payResult['trade_no']) {
    $pdo->prepare('UPDATE orders SET trade_no = ? WHERE order_no = ?')
        ->execute([$payResult['trade_no'], $outTradeNo]);
}

success([
    'order_no'    => $outTradeNo,
    'trade_no'    => $payResult['trade_no'],
    'payurl'      => $payResult['payurl'],
    'qrcode'      => $payResult['qrcode'],
    'urlscheme'   => $payResult['urlscheme'],
    'money'       => $payResult['money'],
    'pay_type'    => $payType,
], '订单已创建');
