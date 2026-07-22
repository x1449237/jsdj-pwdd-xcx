<?php
declare(strict_types=1);

namespace app\service;

use app\model\SubscribeMessageTemplate;
use app\model\SubscribeMessageLog;
use think\facade\Cache;
use think\facade\Log;
use think\facade\Db;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * 微信服务
 * - access_token 管理（Redis 缓存 + 自动刷新）
 * - 订阅消息发送
 * - 重试机制
 */
class WeChatService
{
    // 微信API地址
    private const API_ACCESS_TOKEN = 'https://api.weixin.qq.com/cgi-bin/token';
    private const API_SUBSCRIBE_SEND = 'https://api.weixin.qq.com/cgi-bin/message/subscribe/send';

    // Redis 缓存 Key
    private const CACHE_TOKEN_KEY = 'wechat:access_token';
    private const CACHE_TOKEN_LOCK = 'wechat:access_token:lock';

    // access_token 提前刷新阈值（秒）
    private const TOKEN_REFRESH_AHEAD = 300;

    /**
     * 获取 access_token（带缓存）
     * @return string
     * @throws \Exception
     */
    public function getAccessToken(): string
    {
        // 尝试从缓存获取
        $token = Cache::get(self::CACHE_TOKEN_KEY);
        if ($token) {
            return $token;
        }

        return $this->refreshAccessToken();
    }

    /**
     * 刷新 access_token
     * @return string
     * @throws \Exception
     */
    public function refreshAccessToken(): string
    {
        // 分布式锁防并发刷新
        $redis = get_redis();
        $lockKey = self::CACHE_TOKEN_LOCK;
        $lockValue = uniqid('', true);
        
        // 尝试获取锁，最多等待3秒
        $locked = $redis->set($lockKey, $lockValue, ['nx', 'ex' => 3]);
        if (!$locked) {
            // 等锁释放后重试拿缓存
            usleep(500000);
            $token = Cache::get(self::CACHE_TOKEN_KEY);
            if ($token) {
                return $token;
            }
            // 再次尝试获取锁
            $locked = $redis->set($lockKey, $lockValue, ['nx', 'ex' => 3]);
            if (!$locked) {
                throw new \Exception('Failed to acquire access_token refresh lock');
            }
        }

        try {
            $appId = $this->getConfig('wechat_appid');
            $secret = $this->getConfig('wechat_secret');

            if (empty($appId) || empty($secret)) {
                throw new \Exception('微信小程序 AppID 或 AppSecret 未配置');
            }

            $client = new Client(['timeout' => 5]);
            $response = $client->get(self::API_ACCESS_TOKEN, [
                'query' => [
                    'grant_type' => 'client_credential',
                    'appid'      => $appId,
                    'secret'     => $secret,
                ],
            ]);

            $result = json_decode((string) $response->getBody(), true);

            if (empty($result['access_token'])) {
                $errMsg = $result['errmsg'] ?? 'Unknown error';
                $errCode = $result['errcode'] ?? -1;
                Log::error("获取 access_token 失败: errcode={$errCode}, errmsg={$errMsg}");
                throw new \Exception("获取 access_token 失败: {$errMsg}");
            }

            $token = $result['access_token'];
            $expiresIn = (int) ($result['expires_in'] ?? 7200);

            // 缓存 access_token，提前5分钟过期
            $cacheTtl = max(0, $expiresIn - self::TOKEN_REFRESH_AHEAD);
            Cache::set(self::CACHE_TOKEN_KEY, $token, $cacheTtl);

            Log::info("access_token 刷新成功，有效期: {$expiresIn}s");
            return $token;
        } catch (\Throwable $e) {
            Log::error("刷新 access_token 异常: " . $e->getMessage());
            throw $e;
        } finally {
            // 释放锁
            $redis->del($lockKey);
        }
    }

    /**
     * 发送订阅消息
     * @param string $openid      接收者openid
     * @param string $templateId  模板ID
     * @param array  $data        模板数据 ['thing1' => ['value' => 'xxx'], ...]
     * @param string $page        跳转小程序页面路径
     * @param string $relatedId   关联业务ID
     * @param string $relatedType 关联业务类型
     * @return array ['success' => bool, 'error' => string]
     */
    public function sendSubscribeMessage(
        string $openid,
        string $templateId,
        array $data,
        string $page = '',
        string $relatedId = '',
        string $relatedType = ''
    ): array {
        $result = ['success' => false, 'error' => ''];

        try {
            // 检查总开关
            if (!$this->isSubscribeEnabled()) {
                $result['error'] = '订阅消息总开关已关闭';
                return $result;
            }

            if (empty($openid)) {
                $result['error'] = 'openid 为空';
                return $result;
            }

            $accessToken = $this->getAccessToken();

            $postData = [
                'touser'      => $openid,
                'template_id' => $templateId,
                'data'        => $data,
            ];

            if (!empty($page)) {
                $postData['page'] = $page;
            }

            // 调试模式：占位符模板ID跳过实际发送
            if (strpos($templateId, 'PLACEHOLDER') !== false) {
                Log::info("[订阅消息-模拟] openid={$openid}, template={$templateId}, data=" . json_encode($data, JSON_UNESCAPED_UNICODE));
                $result['success'] = true;
                $this->logSend(0, $templateId, '', $openid, $data, ['simulated' => true], true, '', $relatedId, $relatedType);
                return $result;
            }

            $client = new Client(['timeout' => $this->getTimeout()]);
            $response = $client->post(self::API_SUBSCRIBE_SEND . '?access_token=' . $accessToken, [
                'json' => $postData,
            ]);

            $resp = json_decode((string) $response->getBody(), true);
            $errCode = $resp['errcode'] ?? -1;
            $errMsg = $resp['errmsg'] ?? '';

            $isSuccess = ($errCode === 0);
            $result['success'] = $isSuccess;
            $result['error'] = $isSuccess ? '' : "{$errCode}: {$errMsg}";

            // 记录发送日志
            $this->logSend(0, $templateId, '', $openid, $data, $resp, $isSuccess, $errMsg, $relatedId, $relatedType);

            if ($isSuccess) {
                Log::info("[订阅消息] 发送成功: openid={$openid}, template={$templateId}");
            } else {
                Log::warning("[订阅消息] 发送失败: openid={$openid}, template={$templateId}, errcode={$errCode}, errmsg={$errMsg}");
            }
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
            Log::error("[订阅消息] 发送异常: " . $e->getMessage());
            $this->logSend(0, $templateId, '', $openid, $data, [], false, $e->getMessage(), $relatedId, $relatedType);
        }

        return $result;
    }

    /**
     * 根据用户ID发送订阅消息
     * @param int    $userId      用户ID
     * @param string $scene       场景标识
     * @param array  $dataValues  模板数据值 ['thing1' => 'xxx', ...]
     * @param string $page        跳转页面
     * @param string $relatedId   关联业务ID
     * @param string $relatedType 关联业务类型
     * @return array
     */
    public function sendToUser(
        int $userId,
        string $scene,
        array $dataValues,
        string $page = '',
        string $relatedId = '',
        string $relatedType = ''
    ): array {
        $result = ['success' => false, 'error' => ''];

        try {
            // 获取模板
            $template = SubscribeMessageTemplate::where('scene', $scene)
                ->where('is_enabled', 1)
                ->find();

            if (!$template) {
                $result['error'] = "场景 {$scene} 的订阅消息模板未配置或已禁用";
                return $result;
            }

            // 获取用户 openid
            $user = Db::name('user')->where('id', $userId)->field('id,openid')->find();
            if (!$user || empty($user['openid'])) {
                $result['error'] = "用户 {$userId} 的 openid 不存在";
                return $result;
            }

            // 构建模板数据
            $templateFields = json_decode($template->fields, true) ?: [];
            $sendData = [];
            foreach ($templateFields as $key => $label) {
                $sendData[$key] = ['value' => $dataValues[$key] ?? ''];
            }

            $result = $this->sendSubscribeMessage(
                $user['openid'],
                $template->template_id,
                $sendData,
                $page,
                $relatedId,
                $relatedType
            );

            // 更新模板命中次数
            if ($result['success']) {
                $template->setInc('hit_count');
            }
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
            Log::error("[订阅消息] sendToUser 异常: " . $e->getMessage());
        }

        return $result;
    }

    /**
     * 带重试的发送
     * @param int    $userId
     * @param string $scene
     * @param array  $dataValues
     * @param string $page
     * @param string $relatedId
     * @param string $relatedType
     * @return array
     */
    public function sendToUserWithRetry(
        int $userId,
        string $scene,
        array $dataValues,
        string $page = '',
        string $relatedId = '',
        string $relatedType = ''
    ): array {
        $maxRetry = (int) $this->getConfig('subscribe_message_retry', '3');
        $result = ['success' => false, 'error' => ''];

        for ($i = 0; $i < $maxRetry; $i++) {
            $result = $this->sendToUser($userId, $scene, $dataValues, $page, $relatedId, $relatedType);
            if ($result['success']) {
                break;
            }
            if ($i < $maxRetry - 1) {
                Log::info("[订阅消息] 第 " . ($i + 1) . " 次发送失败，准备重试...");
                usleep(200000); // 200ms 后重试
            }
        }

        return $result;
    }

    /**
     * 记录发送日志
     */
    private function logSend(
        int $userId,
        string $templateId,
        string $scene,
        string $openid,
        array $sendData,
        array $sendResult,
        bool $isSuccess,
        string $errorMsg,
        string $relatedId = '',
        string $relatedType = ''
    ): void {
        try {
            SubscribeMessageLog::create([
                'user_id'      => $userId,
                'template_id'  => $templateId,
                'scene'        => $scene,
                'openid'       => $openid,
                'send_data'    => json_encode($sendData, JSON_UNESCAPED_UNICODE),
                'send_result'  => json_encode($sendResult, JSON_UNESCAPED_UNICODE),
                'is_success'   => $isSuccess ? 1 : 0,
                'error_msg'    => $errorMsg,
                'related_id'   => $relatedId,
                'related_type' => $relatedType,
            ]);
        } catch (\Throwable $e) {
            Log::error("记录订阅消息日志失败: " . $e->getMessage());
        }
    }

    /**
     * 检查订阅消息总开关
     */
    public function isSubscribeEnabled(): bool
    {
        return $this->getConfig('subscribe_message_switch', '1') === '1';
    }

    /**
     * 获取超时设置
     */
    private function getTimeout(): int
    {
        return (int) $this->getConfig('subscribe_message_timeout', '3');
    }

    /**
     * 获取系统配置
     */
    private function getConfig(string $key, string $default = ''): string
    {
        try {
            $value = Db::name('system_config')
                ->where('config_key', $key)
                ->value('config_value');
            return $value !== null ? $value : $default;
        } catch (\Throwable $e) {
            return $default;
        }
    }
}