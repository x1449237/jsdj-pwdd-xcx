<?php
declare(strict_types=1);

namespace app\service;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use think\facade\Log;
use think\facade\Config;

/**
 * JWT 服务
 * 负责 Token 的创建、验证、刷新和黑名单管理
 */
class JwtService
{
    /**
     * @var string 密钥
     */
    private $secretKey;

    /**
     * @var string 加密算法
     */
    private $algorithm;

    /**
     * @var int Token 有效期（秒）
     */
    private $ttl;

    /**
     * @var int 刷新 Token 有效期（秒）
     */
    private $refreshTtl;

    /**
     * @var string 签发者
     */
    private $issuer;

    /**
     * @var string Redis 黑名单 Key 前缀
     */
    private const BLACKLIST_PREFIX = 'jwt:blacklist:';

    public function __construct()
    {
        $this->secretKey  = config_get('jwt.secret_key', '');
        $this->algorithm  = config_get('jwt.algorithm', 'HS256');
        $this->ttl        = (int) config_get('jwt.ttl', 7200);
        $this->refreshTtl = (int) config_get('jwt.refresh_ttl', 604800);
        $this->issuer     = config_get('jwt.issuer', 'game-platform');
    }

    /**
     * 创建 Token
     * @param int    $adminId
     * @param string $type 用户类型 admin/user
     * @return array [token, refresh_token, expires_in]
     */
    public function createToken(int $adminId, string $type = 'admin'): array
    {
        $now = time();
        $tokenId = bin2hex(random_bytes(16));

        $payload = [
            'iss'      => $this->issuer,
            'iat'      => $now,
            'exp'      => $now + $this->ttl,
            'nbf'      => $now,
            'jti'      => $tokenId,
            'sub'      => $adminId,
            'type'     => $type,
        ];

        $token = JWT::encode($payload, $this->secretKey, $this->algorithm);

        // 生成刷新 Token
        $refreshPayload = [
            'iss'      => $this->issuer,
            'iat'      => $now,
            'exp'      => $now + $this->refreshTtl,
            'jti'      => bin2hex(random_bytes(16)),
            'sub'      => $adminId,
            'type'     => $type,
            'purpose'  => 'refresh',
        ];
        $refreshToken = JWT::encode($refreshPayload, $this->secretKey, $this->algorithm);

        Log::info("JWT token created: admin_id={$adminId}, type={$type}, jti={$tokenId}");

        return [
            'token'         => $token,
            'refresh_token' => $refreshToken,
            'expires_in'    => $this->ttl,
        ];
    }

    /**
     * 验证 Token
     * @param string $token
     * @return array|null 返回 payload 或 null
     * @throws \RuntimeException
     */
    public function verifyToken(string $token): ?array
    {
        try {
            // 检查黑名单
            if ($this->isBlacklisted($token)) {
                Log::warning('JWT token is blacklisted');
                return null;
            }

            $decoded = JWT::decode($token, new Key($this->secretKey, $this->algorithm));
            $payload = (array) $decoded;

            // 不允许用 refresh token 做认证
            if (isset($payload['purpose']) && $payload['purpose'] === 'refresh') {
                Log::warning('JWT refresh token used for authentication');
                return null;
            }

            return $payload;
        } catch (ExpiredException $e) {
            Log::info('JWT token expired: ' . $e->getMessage());
            return null;
        } catch (\Throwable $e) {
            Log::error('JWT verify error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 刷新 Token
     * @param string $refreshToken
     * @return array|null [token, refresh_token, expires_in]
     */
    public function refreshToken(string $refreshToken): ?array
    {
        try {
            $decoded = JWT::decode($refreshToken, new Key($this->secretKey, $this->algorithm));
            $payload = (array) $decoded;

            if (!isset($payload['purpose']) || $payload['purpose'] !== 'refresh') {
                Log::warning('JWT refresh: invalid token purpose');
                return null;
            }

            if ($this->isBlacklisted($refreshToken)) {
                Log::warning('JWT refresh token is blacklisted');
                return null;
            }

            // 将旧 refresh token 加入黑名单
            $this->blacklistToken($refreshToken);

            $adminId = (int) $payload['sub'];
            $type    = $payload['type'] ?? 'admin';

            return $this->createToken($adminId, $type);
        } catch (\Throwable $e) {
            Log::error('JWT refresh error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 将 Token 加入黑名单
     * @param string $token
     * @return bool
     */
    public function blacklistToken(string $token): bool
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secretKey, $this->algorithm));
            $payload = (array) $decoded;
            $exp     = $payload['exp'] ?? 0;
            $ttl     = max((int) $exp - time(), 1);

            $redis = get_redis();
            $key   = self::BLACKLIST_PREFIX . md5($token);
            $redis->setex($key, $ttl, '1');

            Log::info("JWT token blacklisted, ttl={$ttl}s");
            return true;
        } catch (\Throwable $e) {
            Log::error('JWT blacklist error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 检查 Token 是否在黑名单中
     * @param string $token
     * @return bool
     */
    private function isBlacklisted(string $token): bool
    {
        try {
            $redis = get_redis();
            $key   = self::BLACKLIST_PREFIX . md5($token);
            return (bool) $redis->exists($key);
        } catch (\Throwable $e) {
            Log::error('JWT blacklist check error: ' . $e->getMessage());
            return false;
        }
    }
}