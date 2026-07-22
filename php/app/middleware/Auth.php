<?php
declare(strict_types=1);

namespace app\middleware;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\SignatureInvalidException;
use think\facade\Cache;
use think\facade\Config;

/**
 * JWT 认证中间件
 * 支持 admin 和 user 两种 token 类型
 */
class Auth
{
    /**
     * 处理请求
     * @param \think\Request $request
     * @param \Closure $next
     * @param string $guard 认证类型：admin|user
     * @return mixed
     */
    public function handle(\think\Request $request, \Closure $next, string $guard = 'user')
    {
        // 获取 token
        $token = $this->getToken($request);

        if (empty($token)) {
            return api_error('请先登录', 401, null, 401);
        }

        try {
            // 解析 JWT
            $secret = Config::get('jwt.secret_key');
            $algorithm = Config::get('jwt.algorithm', 'HS256');
            $payload = JWT::decode($token, new Key($secret, $algorithm));

            // 验证签发者
            if (!$this->validateIssuer($payload)) {
                return api_error('token 无效', 401, null, 401);
            }

            // 验证 token 类型
            if ($guard === 'admin' && !$this->isAdminToken($payload)) {
                return api_error('无管理员权限', 403, null, 403);
            }

            if ($guard === 'user' && !$this->isUserToken($payload)) {
                return api_error('token 类型错误', 401, null, 401);
            }

            // 检查 token 是否在黑名单中
            if ($this->isBlacklisted($token)) {
                return api_error('token 已失效', 401, null, 401);
            }

            // 检查 token 是否即将过期，自动刷新
            $token = $this->autoRefreshToken($token, $payload);

            // 设置用户信息到请求
            $this->setUserInfo($request, $payload, $guard);

        } catch (ExpiredException $e) {
            return api_error('token 已过期', 401, null, 401);
        } catch (BeforeValidException $e) {
            return api_error('token 尚未生效', 401, null, 401);
        } catch (SignatureInvalidException $e) {
            return api_error('token 签名无效', 401, null, 401);
        } catch (\UnexpectedValueException $e) {
            return api_error('token 格式错误', 401, null, 401);
        } catch (\Throwable $e) {
            return api_error('token 验证失败', 401, null, 401);
        }

        // 继续执行
        $response = $next($request);

        // 如果 token 被刷新，在响应头中返回新 token
        if (isset($token) && $token !== $this->getToken($request)) {
            $response->header([
                'X-New-Token' => $token,
            ]);
        }

        return $response;
    }

    /**
     * 从请求中获取 token
     * @param \think\Request $request
     * @return string|null
     */
    protected function getToken(\think\Request $request): ?string
    {
        // 1. 从 Authorization header 获取 Bearer token
        $header = $request->header('Authorization', '');
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return $matches[1];
        }

        // 2. 从请求参数获取
        $token = $request->param('token', '');
        if (!empty($token)) {
            return $token;
        }

        return null;
    }

    /**
     * 验证签发者
     * @param object $payload
     * @return bool
     */
    protected function validateIssuer(object $payload): bool
    {
        $issuer = Config::get('jwt.issuer', 'game-platform');
        return isset($payload->iss) && $payload->iss === $issuer;
    }

    /**
     * 判断是否为管理员 token
     * @param object $payload
     * @return bool
     */
    protected function isAdminToken(object $payload): bool
    {
        return isset($payload->type) && $payload->type === 'admin';
    }

    /**
     * 判断是否为用户 token
     * @param object $payload
     * @return bool
     */
    protected function isUserToken(object $payload): bool
    {
        return isset($payload->type) && $payload->type === 'user';
    }

    /**
     * 检查 token 是否在黑名单中
     * @param string $token
     * @return bool
     */
    protected function isBlacklisted(string $token): bool
    {
        $key = 'token_blacklist:' . md5($token);
        return Cache::store('redis')->has($key);
    }

    /**
     * 自动刷新即将过期的 token
     * @param string $token
     * @param object $payload
     * @return string
     */
    protected function autoRefreshToken(string $token, object $payload): string
    {
        $ttl = Config::get('jwt.ttl', 7200);
        $refreshTtl = Config::get('jwt.refresh_ttl', 604800);

        // 剩余有效时间
        $remainingTime = $payload->exp - time();

        // 如果剩余时间小于 TTL 的 30%，自动刷新
        if ($remainingTime > 0 && $remainingTime < ($ttl * 0.3)) {
            // 检查是否还在刷新有效期内
            if (($payload->iat + $refreshTtl) > time()) {
                $newPayload = [
                    'iss'  => $payload->iss,
                    'iat'  => time(),
                    'exp'  => time() + $ttl,
                    'type' => $payload->type,
                    'uid'  => $payload->uid,
                ];

                $secret = Config::get('jwt.secret_key');
                $algorithm = Config::get('jwt.algorithm', 'HS256');
                return JWT::encode($newPayload, $secret, $algorithm);
            }
        }

        return $token;
    }

    /**
     * 设置用户信息到请求
     * @param \think\Request $request
     * @param object $payload
     * @param string $guard
     */
    protected function setUserInfo(\think\Request $request, object $payload, string $guard): void
    {
        if ($guard === 'admin') {
            $request->setAdminId($payload->uid ?? 0);
            $request->setUserType('admin');
        } else {
            $request->setUserId($payload->uid ?? 0);
            $request->setUserType('user');
        }
    }
}