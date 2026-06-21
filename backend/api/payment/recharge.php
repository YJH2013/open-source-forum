<?php
/**
 * POST /api/payment/recharge
 * Body: { amount, pay_type }
 * 使用页面跳转模式：前端拿到 payurl 后 window.location 跳转
 * 汇率: 1元 = 2余额
 */

$auth = requireAuth();
require_once __DIR__ . '/../../config/payment.php';

$data = json_decode(file_get_contents('php://input'), true);
$amount = floatval($data['amount'] ?? 0);
$payType = ($data['pay_type'] ?? 'alipay') === 'wxpay' ? 'wxpay' : 'alipay';

if ($amount < 1) error('最低充值 1 元');
if ($amount > 500) error('单次最多充值 500 元');

if (!CODEPAY_PID || !CODEPAY_KEY) {
    error('支付接口未配置（pid/key为空），请在后台→码支付配置中填写');
}

$pdo = getDB();
$outTradeNo = 'R' . date('YmdHis') . rand(1000, 9999);
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$notifyUrl = $scheme . '://' . $host . '/api/payment/notify';
$returnUrl = $scheme . '://' . $host . '/recharge';

// 创建充值订单
$stmt = $pdo->prepare('INSERT INTO orders (order_no, user_id, post_id, amount) VALUES (?, ?, 0, ?)');
$stmt->execute([$outTradeNo, $auth['id'], $amount]);

// 构造页面跳转参数（submit.php）
$params = [
    'pid'          => CODEPAY_PID,
    'type'         => $payType,
    'out_trade_no' => $outTradeNo,
    'notify_url'   => $notifyUrl,
    'return_url'   => $returnUrl,
    'name'         => '余额充值 ' . number_format($amount, 2) . '元（到账' . ($amount * 2) . '余额）',
    'money'        => number_format($amount, 2, '.', ''),
    'clientip'     => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    'device'       => 'pc',
    'param'        => 'recharge',
];
$params['sign'] = codepaySign($params);
$params['sign_type'] = 'MD5';

// 构建跳转 URL（浏览器直连，不走服务器 curl）
$payurl = CODEPAY_API_HOST . '/xpay/epay/submit.php?' . http_build_query($params);

success([
    'order_no'    => $outTradeNo,
    'payurl'      => $payurl,
    'pay_type'    => $payType,
    'balance_add' => $amount * 2,
    'money'       => number_format($amount, 2, '.', ''),
], '订单已创建');
