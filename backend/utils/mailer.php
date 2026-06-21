<?php
/**
 * 简易 SMTP 邮件发送工具
 * 无需 Composer，纯 PHP socket 实现
 */

class Mailer
{
    private string $host;
    private int $port;
    private string $user;
    private string $pass;
    private string $fromEmail;
    private string $fromName;
    private int $timeout;

    public function __construct(array $config = [])
    {
        $this->host      = $config['host']       ?? '';
        $this->port      = (int)($config['port'] ?? 465);
        $this->user      = $config['user']       ?? '';
        $this->pass      = $config['pass']       ?? '';
        $this->fromEmail = $config['from_email'] ?? $this->user;
        $this->fromName  = $config['from_name']  ?? '论坛';
        $this->timeout   = (int)($config['timeout'] ?? 10);
    }

    /**
     * 发送邮件
     * @param string|array $to     收件人邮箱或 [email => name]
     * @param string       $subject 主题
     * @param string       $body    HTML 正文
     * @return array [success: bool, message: string]
     */
    public function send($to, string $subject, string $body): array
    {
        if (!$this->host || !$this->user || !$this->pass) {
            return [false, 'SMTP 未配置'];
        }

        $toEmail = is_array($to) ? key($to) : $to;
        $toName  = is_array($to) ? current($to) : '';

        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return [false, '收件人邮箱无效'];
        }

        // 构建 MIME 邮件
        $boundary = '=_Part_' . md5(uniqid(mt_rand(), true));
        $headers = $this->buildHeaders($toEmail, $toName, $subject, $boundary);
        $message = $this->buildMessage($subject, $body, $boundary);

        // 发送
        try {
            $socket = $this->connect();
            $this->dialogue($socket, $headers . $message);
            $this->disconnect($socket);
            return [true, '发送成功'];
        } catch (\Exception $e) {
            return [false, $e->getMessage()];
        }
    }

    private function connect()
    {
        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $remote = ($this->port === 465 ? 'ssl' : 'tcp') . "://{$this->host}:{$this->port}";

        $socket = @stream_socket_client($remote, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$socket) {
            throw new \Exception("连接 SMTP 服务器失败: $errstr ($errno)");
        }

        stream_set_timeout($socket, $this->timeout);
        $this->read($socket); // 读取欢迎信息

        // EHLO
        $this->cmd($socket, "EHLO " . gethostname());

        // STARTTLS（仅 587 端口需要）
        if ($this->port === 587) {
            $this->cmd($socket, "STARTTLS");
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $this->cmd($socket, "EHLO " . gethostname());
        }

        // AUTH LOGIN
        $this->cmd($socket, "AUTH LOGIN");
        $this->cmd($socket, base64_encode($this->user));
        $this->cmd($socket, base64_encode($this->pass));

        return $socket;
    }

    private function dialogue($socket, string $data): void
    {
        $this->cmd($socket, "MAIL FROM:<{$this->fromEmail}>");
        $this->cmd($socket, "RCPT TO:<{$this->getToFromData($data)}>");
        $this->cmd($socket, "DATA");
        $this->cmd($socket, $data . "\r\n.");
    }

    private function disconnect($socket): void
    {
        $this->cmd($socket, "QUIT", false);
        fclose($socket);
    }

    private function cmd($socket, string $cmd, bool $check = true): string
    {
        fwrite($socket, $cmd . "\r\n");
        $response = $this->read($socket);
        if ($check) {
            $code = (int)substr($response, 0, 3);
            if ($code >= 400) {
                throw new \Exception("SMTP 错误: " . trim($response));
            }
        }
        return $response;
    }

    private function read($socket): string
    {
        $data = '';
        while ($line = fgets($socket, 515)) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $data;
    }

    private function buildHeaders(string $toEmail, string $toName, string $subject, string $boundary): string
    {
        $from = $this->fromName
            ? "{$this->fromName} <{$this->fromEmail}>"
            : $this->fromEmail;

        $to = $toName ? "{$toName} <{$toEmail}>" : $toEmail;

        $subjectEncoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        return "MIME-Version: 1.0\r\n"
             . "From: $from\r\n"
             . "To: $to\r\n"
             . "Subject: $subjectEncoded\r\n"
             . "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";
    }

    private function buildMessage(string $subject, string $body, string $boundary): string
    {
        $plain = strip_tags($body);
        return "\r\n--$boundary\r\n"
             . "Content-Type: text/plain; charset=utf-8\r\n"
             . "Content-Transfer-Encoding: base64\r\n\r\n"
             . chunk_split(base64_encode($plain))
             . "--$boundary\r\n"
             . "Content-Type: text/html; charset=utf-8\r\n"
             . "Content-Transfer-Encoding: base64\r\n\r\n"
             . chunk_split(base64_encode($body))
             . "--$boundary--\r\n";
    }

    private function getToFromData(string $data): string
    {
        if (preg_match('/^To: .*?<(.+?)>/m', $data, $m)) {
            return $m[1];
        }
        if (preg_match('/^To: (.+)/m', $data, $m)) {
            return trim($m[1]);
        }
        return '';
    }
}

/**
 * 从数据库设置加载邮件配置并创建 Mailer 实例
 */
function getMailer(): ?Mailer
{
    $pdo = getDB();
    $stmt = $pdo->query("SELECT `key`, `value` FROM settings WHERE `key` IN ('smtp_host','smtp_port','smtp_user','smtp_pass','smtp_from_email','smtp_from_name')");
    $cfg = [];
    foreach ($stmt->fetchAll() as $row) {
        $cfg[$row['key']] = $row['value'];
    }

    $config = [
        'host'       => $cfg['smtp_host']       ?? '',
        'port'       => $cfg['smtp_port']       ?? 465,
        'user'       => $cfg['smtp_user']       ?? '',
        'pass'       => $cfg['smtp_pass']       ?? '',
        'from_email' => $cfg['smtp_from_email'] ?? ($cfg['smtp_user'] ?? ''),
        'from_name'  => $cfg['smtp_from_name']  ?? '论坛',
    ];

    return new Mailer($config);
}

/**
 * 获取邮件验证开关状态
 */
function isEmailVerifyEnabled(): bool
{
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT `value` FROM settings WHERE `key` = 'email_verify_enabled'");
    $stmt->execute();
    $row = $stmt->fetch();
    return ($row['value'] ?? '0') === '1';
}
