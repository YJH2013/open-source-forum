<?php
/**
 * JWT 认证中间件
 * 简易实现：HMAC-SHA256
 */

define('JWT_SECRET', 'forum-jwt-secret-key-change-in-production');

function generateToken(array $payload): string {
    $header = base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload['iat'] = time();
    $payload['exp'] = time() + 7 * 24 * 3600; // 7天过期
    $payloadEncoded = base64url_encode(json_encode($payload));
    $signature = base64url_encode(hash_hmac('sha256', "$header.$payloadEncoded", JWT_SECRET, true));
    return "$header.$payloadEncoded.$signature";
}

function verifyToken(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;

    [$header, $payload, $signature] = $parts;
    $expected = base64url_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));

    if (!hash_equals($expected, $signature)) return null;

    $data = json_decode(base64url_decode($payload), true);
    if (!$data || (isset($data['exp']) && $data['exp'] < time())) return null;

    return $data;
}

function getAuthUser(): ?array {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) return null;
    return verifyToken($m[1]);
}

function requireAuth(): array {
    $user = getAuthUser();
    if (!$user) {
        error('请先登录', 401);
    }
    // 更新在线状态
    try {
        $pdo = getDB();
        $pdo->prepare("UPDATE users SET last_active = NOW() WHERE id = ?")->execute([$user['id']]);
    } catch (\Exception $e) {}
    return $user;
}

function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/'));
}
