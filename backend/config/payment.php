<?php
/**
 * 码支付 配置
 * 对接文档: /xpay/epay/mapi.php
 * 优先从后台设置读取，未设置时使用默认值
 */

// 从数据库读取配置
function loadPaymentConfig(): array {
    static $config = null;
    if ($config !== null) return $config;

    $defaults = [
        'codepay_pid'  => '',
        'codepay_key'  => '',
        'codepay_host' => 'https://xarr.02aj.cn',
    ];
    try {
        $pdo = getDB();
        $stmt = $pdo->query("SELECT `key`, `value` FROM settings WHERE `key` LIKE 'codepay_%'");
        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            $defaults[$row['key']] = $row['value'];
        }
    } catch (\Exception $e) {
        // 数据库不可用时用默认值
    }
    $config = $defaults;
    return $config;
}

// 获取单个配置
function codepayConfig(string $key): string {
    $cfg = loadPaymentConfig();
    return $cfg[$key] ?? '';
}

// 获取系统配置（带默认值，从 settings 表直接查）
function sysConfig(string $key, $default = ''): string {
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT `value` FROM settings WHERE `key` = ?");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return ($val !== false && $val !== null && $val !== '') ? $val : (string)$default;
    } catch (\Exception $e) {
        return (string)$default;
    }
}

define('CODEPAY_PID', codepayConfig('codepay_pid'));
define('CODEPAY_KEY', codepayConfig('codepay_key'));
define('CODEPAY_API_HOST', rtrim(codepayConfig('codepay_host'), '/') ?: 'https://xarr.02aj.cn');

define('CODEPAY_CREATE_URL', CODEPAY_API_HOST . '/xpay/epay/mapi.php');
define('CODEPAY_QUERY_URL',  CODEPAY_API_HOST . '/xpay/epay/api.php');
define('CODEPAY_NOTIFY_URL', 'http://localhost:8080/api/payment/notify');
define('CODEPAY_RETURN_URL', 'http://localhost:5173/post/');

/**
 * 易支付签名算法
 * 将所有请求参数按 key 排序，拼接为 key=value&key=value 格式
 * 末尾附上商户密钥，计算 MD5
 */
function codepaySign(array $data): string {
    // 移除 sign 和 sign_type 字段
    unset($data['sign'], $data['sign_type']);
    // 过滤空值
    $data = array_filter($data, function($v) { return $v !== '' && $v !== null; });
    // 按 key 排序
    ksort($data);
    $str = '';
    foreach ($data as $k => $v) {
        $str .= $k . '=' . $v . '&';
    }
    $str = rtrim($str, '&');
    $str .= CODEPAY_KEY;
    return md5($str);
}

/**
 * 验证易支付回调签名
 */
function codepayVerifySign(array $data): bool {
    if (empty($data['sign'])) return false;
    return strtolower($data['sign']) === strtolower(codepaySign($data));
}

/**
 * 创建支付订单
 * @return array ['success'=>bool, 'trade_no'=>'', 'payurl'=>'', 'qrcode'=>'', 'urlscheme'=>'', 'money'=>'', 'error'=>'']
 */
function codepayCreateOrder(string $outTradeNo, float $amount, string $name, string $type, string $param = ''): array {
    $data = [
        'pid'          => CODEPAY_PID,
        'type'         => $type,             // alipay 或 wxpay
        'out_trade_no' => $outTradeNo,
        'notify_url'   => CODEPAY_NOTIFY_URL,
        'return_url'   => CODEPAY_RETURN_URL . '?order=' . $outTradeNo,
        'name'         => $name,
        'money'        => number_format($amount, 2, '.', ''),
        'clientip'     => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        'device'       => 'pc',
        'param'        => $param,
    ];
    $data['sign'] = codepaySign($data);
    $data['sign_type'] = 'MD5';

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => CODEPAY_CREATE_URL,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) return ['success' => false, 'error' => '支付网关连接失败: ' . $error];

    $result = json_decode($response, true);
    if (!$result) return ['success' => false, 'error' => '支付网关响应异常'];

    // code=1 表示成功
    if (($result['code'] ?? 0) != 1) {
        return ['success' => false, 'error' => $result['msg'] ?? '创建支付失败'];
    }

    return [
        'success'    => true,
        'trade_no'   => $result['trade_no'] ?? '',
        'payurl'     => $result['payurl'] ?? '',
        'qrcode'     => $result['qrcode'] ?? '',
        'urlscheme'  => $result['urlscheme'] ?? '',
        'money'      => $result['money'] ?? '',
    ];
}

/**
 * 查询单个订单
 * @return array|null
 */
function codepayQueryOrder(string $outTradeNo): ?array {
    $data = [
        'act'          => 'order',
        'pid'          => CODEPAY_PID,
        'key'          => CODEPAY_KEY,
        'out_trade_no' => $outTradeNo,
    ];
    // 查询接口通常直接用 key 做签名，不同平台可能略有差异
    // 这里提供基础实现，实际按平台文档调整

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => CODEPAY_QUERY_URL . '?' . http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($response, true);
    if (!$result) return null;

    // status=1 表示已支付
    return [
        'paid'     => ($result['status'] ?? 0) == 1,
        'trade_no' => $result['trade_no'] ?? '',
        'money'    => $result['money'] ?? '',
        'raw'      => $result,
    ];
}
