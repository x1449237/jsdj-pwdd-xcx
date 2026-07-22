<?php
declare(strict_types=1);

namespace app\service;

use app\model\FaceVerifyRateLimit;
use think\facade\Log;
use GuzzleHttp\Client;

/**
 * 活体检测服务
 * 负责创建活体检测会话、1:1人脸比对、年龄计算和频控
 */
class FaceVerifyService
{
    /**
     * 每日每人验证次数上限
     */
    private const DAILY_LIMIT_PER_USER = 5;

    /**
     * 每日每IP验证次数上限
     */
    private const DAILY_LIMIT_PER_IP = 5;

    /**
     * 频控 Key 前缀
     */
    private const RATE_LIMIT_KEY = 'face:verify:rate:';

    /**
     * 创建活体检测会话
     * @param int    $userId
     * @param string $name      真实姓名
     * @param string $idCard    身份证号
     * @return array
     * @throws \RuntimeException
     */
    public function createSession(int $userId, string $name, string $idCard): array
    {
        try {
            // 频控检查
            $ip = get_client_ip();
            if (!$this->checkRateLimit($userId, $ip)) {
                throw new \RuntimeException('今日验证次数已达上限，请明日再试');
            }

            // 计算年龄
            $age = $this->calculateAge($idCard);

            $sessionId = generate_token(32);
            $redis = get_redis();
            $redis->setex('face:session:' . $sessionId, 600, json_encode([
                'user_id' => $userId,
                'name'    => $name,
                'id_card' => $idCard,
                'age'     => $age,
                'status'  => 'pending',
            ], JSON_UNESCAPED_UNICODE));

            Log::info("活体检测会话创建: session_id={$sessionId}, user_id={$userId}, age={$age}");

            return [
                'session_id' => $sessionId,
                'age'        => $age,
                'expires_in' => 600,
            ];
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error("创建活体检测会话失败: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * 活体验证（1:1人脸比对）
     * @param string $sessionId 会话ID
     * @param string $videoData 视频数据（Base64或文件路径）
     * @return array
     * @throws \RuntimeException
     */
    public function verify(string $sessionId, string $videoData): array
    {
        try {
            $redis = get_redis();
            $sessionData = $redis->get('face:session:' . $sessionId);

            if (!$sessionData) {
                throw new \RuntimeException('会话已过期或不存在');
            }

            $session = json_decode($sessionData, true);
            if ($session['status'] !== 'pending') {
                throw new \RuntimeException('会话状态异常');
            }

            // 调用第三方活体检测 API
            $apiResult = $this->callFaceVerifyApi($session['name'], $session['id_card'], $videoData);

            // 更新会话状态
            $session['status'] = $apiResult['passed'] ? 'success' : 'failed';
            $session['score'] = $apiResult['score'];
            $session['verify_time'] = date('Y-m-d H:i:s');
            $redis->setex('face:session:' . $sessionId, 600, json_encode($session, JSON_UNESCAPED_UNICODE));

            // 记录限流
            $this->recordRateLimit($session['user_id'], get_client_ip());

            Log::info("活体验证完成: session_id={$sessionId}, passed={$apiResult['passed']}, score={$apiResult['score']}");

            return [
                'passed'       => $apiResult['passed'],
                'score'        => $apiResult['score'],
                'session_id'   => $sessionId,
            ];
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error("活体验证失败: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * 根据身份证号计算年龄
     * @param string $idCard
     * @return int
     */
    public function calculateAge(string $idCard): int
    {
        if (strlen($idCard) < 14) {
            return 0;
        }

        $birthday = substr($idCard, 6, 8);
        $year  = (int) substr($birthday, 0, 4);
        $month = (int) substr($birthday, 4, 2);
        $day   = (int) substr($birthday, 6, 2);

        $currentYear  = (int) date('Y');
        $currentMonth = (int) date('m');
        $currentDay   = (int) date('d');

        $age = $currentYear - $year;
        if ($currentMonth < $month || ($currentMonth == $month && $currentDay < $day)) {
            $age--;
        }

        return $age;
    }

    /**
     * 频控检查
     * 同一用户/IP每日最多5次
     * @param int    $userId
     * @param string $ip
     * @return bool
     */
    public function checkRateLimit(int $userId, string $ip): bool
    {
        try {
            $today = date('Y-m-d');

            // 检查用户维度
            $userCount = FaceVerifyRateLimit::where('user_id', $userId)
                ->where('verify_date', $today)
                ->count();

            if ($userCount >= self::DAILY_LIMIT_PER_USER) {
                Log::warning("活体检测频控: user_id={$userId}, 已超过每日上限");
                return false;
            }

            // 检查IP维度
            $ipCount = FaceVerifyRateLimit::where('verify_ip', $ip)
                ->where('verify_date', $today)
                ->count();

            if ($ipCount >= self::DAILY_LIMIT_PER_IP) {
                Log::warning("活体检测频控: ip={$ip}, 已超过每日上限");
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error("活体检测频控检查失败: {$e->getMessage()}");
            return true; // 频控异常时放行
        }
    }

    /**
     * 记录频控
     * @param int    $userId
     * @param string $ip
     * @return bool
     */
    private function recordRateLimit(int $userId, string $ip): bool
    {
        try {
            FaceVerifyRateLimit::create([
                'user_id'     => $userId,
                'verify_ip'   => $ip,
                'verify_date' => date('Y-m-d'),
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error("记录活体检测频控失败: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * 调用第三方活体检测 API
     * 优先使用阿里云，失败降级到腾讯云
     * @param string $name
     * @param string $idCard
     * @param string $videoData
     * @return array [passed, score]
     */
    private function callFaceVerifyApi(string $name, string $idCard, string $videoData): array
    {
        try {
            // 优先使用阿里云
            $result = $this->callAliyunFaceVerify($name, $idCard, $videoData);
            if ($result !== null) {
                return $result;
            }
        } catch (\Throwable $e) {
            Log::warning("阿里云活体检测失败，降级到腾讯云: {$e->getMessage()}");
        }

        try {
            // 降级到腾讯云
            $result = $this->callTencentFaceVerify($name, $idCard, $videoData);
            if ($result !== null) {
                return $result;
            }
        } catch (\Throwable $e) {
            Log::warning("腾讯云活体检测失败: {$e->getMessage()}");
        }

        // 全部失败，返回模拟结果
        Log::warning('所有活体检测渠道均失败，使用模拟结果');
        return [
            'passed' => false,
            'score'  => 0,
        ];
    }

    /**
     * 阿里云活体检测
     * @param string $name
     * @param string $idCard
     * @param string $videoData
     * @return array|null
     */
    private function callAliyunFaceVerify(string $name, string $idCard, string $videoData): ?array
    {
        $accessKeyId = config_get('aliyun.access_key_id', '');
        $accessKeySecret = config_get('aliyun.access_key_secret', '');
        $endpoint = config_get('aliyun.face_compare_endpoint', '');

        if (empty($accessKeyId) || empty($endpoint)) {
            return null;
        }

        // 构建签名和请求
        $client = new Client(['timeout' => 30]);
        $response = $client->post('https://' . $endpoint, [
            'headers' => [
                'Authorization' => 'APPCODE ' . $accessKeyId,
                'Content-Type'  => 'application/json',
            ],
            'json' => [
                'name'    => $name,
                'id_card' => $idCard,
                'video'   => $videoData,
            ],
        ]);

        $result = json_decode($response->getBody()->getContents(), true);

        if (isset($result['code']) && $result['code'] == 0) {
            return [
                'passed' => ($result['confidence'] ?? 0) >= 80,
                'score'  => $result['confidence'] ?? 0,
            ];
        }

        return null;
    }

    /**
     * 腾讯云活体检测
     * @param string $name
     * @param string $idCard
     * @param string $videoData
     * @return array|null
     */
    private function callTencentFaceVerify(string $name, string $idCard, string $videoData): ?array
    {
        $secretId = config_get('tencent.secret_id', '');
        $secretKey = config_get('tencent.secret_key', '');
        $endpoint = config_get('tencent.faceid_endpoint', '');

        if (empty($secretId) || empty($endpoint)) {
            return null;
        }

        $client = new Client(['timeout' => 30]);
        $response = $client->post('https://' . $endpoint, [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'SecretId'  => $secretId,
                'SecretKey' => $secretKey,
                'Name'      => $name,
                'IdCard'    => $idCard,
                'VideoBase64' => $videoData,
            ],
        ]);

        $result = json_decode($response->getBody()->getContents(), true);

        if (isset($result['Response']['Result'])) {
            $sim = $result['Response']['Sim'] ?? 0;
            return [
                'passed' => $sim >= 80,
                'score'  => $sim,
            ];
        }

        return null;
    }
}