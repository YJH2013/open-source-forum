<?php
/**
 * 聚合登录工具 (u.0mz.cn)
 * 支持 QQ、微信、支付宝 等第三方登录
 *
 * API 文档说明：
 *   Step1: act=login    → 获取跳转登录地址
 *   Step2: 用户跳转授权
 *   Step3: 回调带 code
 *   Step4: act=callback → 通过 code 获取用户信息
 */

class OAuthLogin
{
    private string $appId;
    private string $appKey;
    private string $baseUrl;

    public function __construct(?array $config = null)
    {
        if ($config === null) {
            $config = self::loadConfig();
        }
        $this->appId   = $config['appid']  ?? '';
        $this->appKey  = $config['appkey'] ?? '';
        $this->baseUrl = 'http://u.0mz.cn/connect.php';
    }

    /**
     * 从数据库加载配置
     */
    public static function loadConfig(): array
    {
        try {
            $pdo = getDB();
            $stmt = $pdo->query("SELECT `key`, `value` FROM settings WHERE `key` IN ('oauth_appid','oauth_appkey')");
            $cfg = [];
            foreach ($stmt->fetchAll() as $row) {
                $key = str_replace('oauth_', '', $row['key']);
                $cfg[$key] = $row['value'];
            }
            return $cfg;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * 检查聚合登录是否已启用
     */
    public static function isEnabled(): bool
    {
        try {
            $pdo = getDB();
            $stmt = $pdo->prepare("SELECT `value` FROM settings WHERE `key` = 'oauth_enabled'");
            $stmt->execute();
            return ($stmt->fetchColumn() ?: '0') === '1';
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 检查配置是否完整
     */
    public function isConfigured(): bool
    {
        return !empty($this->appId) && !empty($this->appKey);
    }

    /**
     * Step1: 获取跳转登录地址
     * @param string $type         登录方式: qq, alipay, wechat 等
     * @param string $redirectUri  回调地址
     * @return array|null          [url, qrcode, type]
     */
    public function getAuthUrl(string $type, string $redirectUri): ?array
    {
        $params = http_build_query([
            'act'          => 'login',
            'appid'        => $this->appId,
            'appkey'       => $this->appKey,
            'type'         => $type,
            'redirect_uri' => $redirectUri,
        ]);

        $url = $this->baseUrl . '?' . $params;
        $response = $this->httpGet($url);
        if (!$response) return null;

        $data = json_decode($response, true);
        if (($data['code'] ?? -1) !== 0) {
            return null;
        }

        return [
            'url'    => $data['url']    ?? '',
            'qrcode' => $data['qrcode'] ?? '',
            'type'   => $data['type']   ?? $type,
        ];
    }

    /**
     * Step4: 通过 code 获取用户信息
     * @param string $code  回调时带的 Authorization Code
     * @param string $type  登录方式
     * @return array|null   [social_uid, access_token, nickname, faceimg, gender, location]
     */
    public function getUserInfo(string $code, string $type): ?array
    {
        $params = http_build_query([
            'act'    => 'callback',
            'appid'  => $this->appId,
            'appkey' => $this->appKey,
            'type'   => $type,
            'code'   => $code,
        ]);

        $url = $this->baseUrl . '?' . $params;
        $response = $this->httpGet($url);
        if (!$response) return null;

        $data = json_decode($response, true);

        // code=2 表示用户未完成登录
        if (($data['code'] ?? -1) !== 0) {
            return null;
        }

        return [
            'social_uid'    => $data['social_uid']    ?? '',
            'access_token'  => $data['access_token']  ?? '',
            'nickname'      => $data['nickname']      ?? '',
            'faceimg'       => $data['faceimg']       ?? '',
            'gender'        => $data['gender']        ?? '',
            'location'      => $data['location']      ?? '',
            'type'          => $data['type']           ?? $type,
        ];
    }

    /**
     * 查询用户最新信息（登录后任意时间可调用）
     */
    public function queryUser(string $type, string $socialUid): ?array
    {
        $params = http_build_query([
            'act'        => 'query',
            'appid'      => $this->appId,
            'appkey'     => $this->appKey,
            'type'       => $type,
            'social_uid' => $socialUid,
        ]);

        $url = $this->baseUrl . '?' . $params;
        $response = $this->httpGet($url);
        if (!$response) return null;

        $data = json_decode($response, true);
        if (($data['code'] ?? -1) !== 0) return null;

        return [
            'social_uid'   => $data['social_uid']   ?? '',
            'access_token' => $data['access_token'] ?? '',
            'nickname'     => $data['nickname']     ?? '',
            'faceimg'      => $data['faceimg']      ?? '',
            'gender'       => $data['gender']       ?? '',
            'location'     => $data['location']     ?? '',
        ];
    }

    /**
     * HTTP GET 请求
     */
    private function httpGet(string $url): string|false
    {
        $ctx = stream_context_create([
            'http' => [
                'timeout'    => 10,
                'user_agent' => 'Mozilla/5.0 (compatible; ForumOAuth/1.0)',
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);
        return @file_get_contents($url, false, $ctx);
    }
}

/**
 * 通过聚合登录信息查找或创建用户
 * @param string $type      登录方式 (qq, alipay, wechat等)
 * @param string $socialUid 第三方用户唯一ID
 * @param array  $userInfo  用户信息 [nickname, faceimg, gender, location]
 * @return array 用户记录
 */
function findOrCreateOAuthUser(string $type, string $socialUid, array $userInfo): array
{
    $pdo = getDB();
    $nickname = $userInfo['nickname'] ?? '第三方用户';
    $avatar   = $userInfo['faceimg']  ?? '';

    // 查找是否已有绑定
    $stmt = $pdo->prepare("
        SELECT u.* FROM users u
        JOIN user_oauths o ON u.id = o.user_id
        WHERE o.provider = ? AND o.openid = ?
    ");
    $stmt->execute([$type, $socialUid]);
    $user = $stmt->fetch();

    if ($user) {
        // 更新头像（如果用户还没设置头像）
        if ($avatar && empty($user['avatar'])) {
            $pdo->prepare("UPDATE users SET avatar = ? WHERE id = ?")
                ->execute([$avatar, $user['id']]);
        }
        return $user;
    }

    // 生成唯一用户名
    $baseName = mb_substr(preg_replace('/[^\x{4e00}-\x{9fa5}a-zA-Z0-9_]/u', '', $nickname), 0, 20) ?: ($type . '_user');
    $username = $baseName;
    $suffix = 1;
    while (true) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if (!$stmt->fetch()) break;
        $username = $baseName . $suffix;
        $suffix++;
    }

    // 生成虚拟邮箱
    $email = "{$type}_{$socialUid}@oauth.local";

    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO users (username, email, password, avatar, role) VALUES (?, ?, ?, ?, 'user')")
            ->execute([$username, $email, '', $avatar]);

        $userId = $pdo->lastInsertId();

        $pdo->prepare("INSERT INTO user_oauths (user_id, provider, openid, nickname, avatar) VALUES (?, ?, ?, ?, ?)")
            ->execute([$userId, $type, $socialUid, $nickname, $avatar]);

        $pdo->commit();

        return [
            'id'        => (int)$userId,
            'username'  => $username,
            'email'     => $email,
            'role'      => 'user',
            'avatar'    => $avatar,
            'signature' => '',
            'balance'   => 0,
        ];
    } catch (\Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}
