<?php
declare(strict_types=1);

namespace app\controller\api;

use app\controller\BaseController;
use app\model\PlayerService;
use app\model\RiskControlLog;
use app\model\ServiceType;
use app\model\SystemConfig;
use app\model\User as UserModel;
use think\facade\Cache;
use think\facade\Log;
use think\Request;

/**
 * 公共接口控制器（微信小程序端）
 */
class Common extends BaseController
{
    /**
     * 文件上传（图片/语音）
     */
    public function upload(Request $request)
    {
        $userId = request()->userId();
        $file   = $request->file('file');

        if (!$file) {
            return $this->error('请选择文件');
        }

        $type = $request->param('type', 'image'); // image/voice

        // 验证文件大小
        $maxSize = $type === 'voice' ? 5 * 1024 * 1024 : 10 * 1024 * 1024; // 语音5MB，图片10MB
        if ($file->getSize() > $maxSize) {
            return $this->error('文件大小超出限制');
        }

        // 验证文件类型
        $allowedExt = $type === 'voice' ? ['mp3', 'wav', 'aac'] : ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $ext = strtolower($file->getOriginalExtension());
        if (!in_array($ext, $allowedExt)) {
            return $this->error('不支持的文件类型: ' . $ext);
        }

        try {
            // 生成保存路径
            $subDir  = $type . '/' . date('Ymd');
            $saveDir = public_path() . 'uploads/' . $subDir;
            if (!is_dir($saveDir)) {
                mkdir($saveDir, 0755, true);
            }

            $fileName = generate_sn('UP') . '.' . $ext;
            $file->move($saveDir, $fileName);

            $fileUrl = '/uploads/' . $subDir . '/' . $fileName;

            write_action_log('api_upload', "用户 ID:{$userId} 上传文件: {$fileUrl}");

            return $this->success([
                'url'  => $fileUrl,
                'name' => $fileName,
                'size' => $file->getSize(),
                'ext'  => $ext,
            ], '上传成功');
        } catch (\Throwable $e) {
            Log::error('文件上传异常: ' . $e->getMessage());
            return $this->error('文件上传失败，请稍后重试', 500);
        }
    }

    /**
     * 获取带签名的文件URL（300秒临时签名）
     */
    public function signedUrl(Request $request)
    {
        $fileUrl = $request->param('file_url', '');

        if (empty($fileUrl)) {
            return $this->error('文件URL不能为空');
        }

        // 生成临时签名
        $timestamp = (string) time();
        $secret    = config_get('upload.sign_secret', 'default_secret');
        $sign      = md5($fileUrl . $timestamp . $secret);
        $expire    = $timestamp + 300; // 300秒后过期

        $signedUrl = $fileUrl . '?sign=' . $sign . '&t=' . $timestamp . '&expire=' . $expire;

        return $this->success([
            'url'       => $signedUrl,
            'expire_at' => $expire,
            'expire_in' => 300,
        ]);
    }

    /**
     * 服务类型列表
     */
    public function serviceTypes(Request $request)
    {
        $types = ServiceType::where('status', ServiceType::STATUS_ENABLED)
            ->order('sort', 'asc')
            ->select()
            ->toArray();

        return $this->success($types);
    }

    /**
     * 打手服务列表
     */
    public function playerServiceList(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $serviceTypeId = $request->paramInt('service_type_id', 0);
        $keyword       = $request->param('keyword', '');

        $query = PlayerService::where('status', PlayerService::STATUS_ONLINE)
            ->order('rating', 'desc')
            ->order('order_count', 'desc');

        if ($serviceTypeId > 0) {
            $query->where('service_type_id', $serviceTypeId);
        }

        if (!empty($keyword)) {
            $query->where('game_name', 'like', "%{$keyword}%");
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        // 补充打手信息
        foreach ($list as &$item) {
            $player = UserModel::find($item['user_id']);
            if ($player) {
                $item['player'] = [
                    'id'       => $player->id,
                    'nickname' => $player->getData('nickname'),
                    'avatar'   => $player->getData('avatar'),
                    'level'    => $player->getData('level'),
                ];
            }
            $serviceType = ServiceType::find($item['service_type_id']);
            $item['service_type_name'] = $serviceType ? $serviceType->getData('name') : '';
        }

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 打手详情
     */
    public function playerDetail(Request $request)
    {
        $playerId = $request->paramInt('player_id', 0);

        if ($playerId <= 0) {
            return $this->error('打手ID无效');
        }

        $player = UserModel::find($playerId);
        if (!$player) {
            return $this->error('打手不存在', 404);
        }

        $playerData = $player->hidden(['openid', 'unionid', 'id_card'])->toArray();

        // 服务列表
        $playerData['services'] = $player->playerServices()
            ->where('status', PlayerService::STATUS_ONLINE)
            ->select()
            ->toArray();

        // 评价统计
        $evalCount = $player->evaluations()->where('status', \app\model\Evaluation::STATUS_SHOW)->count();
        $avgRating = $player->evaluations()->where('status', \app\model\Evaluation::STATUS_SHOW)->avg('rating');
        $playerData['eval_count'] = $evalCount;
        $playerData['avg_rating'] = $avgRating ? round((float) $avgRating, 1) : 0;

        // 完成订单数
        $playerData['completed_orders'] = $player->orders()
            ->where('status', \app\model\Order::STATUS_COMPLETED)
            ->where('player_id', $playerId)
            ->count();

        return $this->success($playerData);
    }

    /**
     * 获取公共配置（协议URL、客服微信号等）
     */
    public function config(Request $request)
    {
        $configs = [];

        // 获取系统配置
        $systemConfigs = SystemConfig::select();
        foreach ($systemConfigs as $config) {
            $configs[$config->getData('key')] = $config->getData('value');
        }

        // 整理公共配置
        $publicConfig = [
            'user_agreement_url'  => $configs['user_agreement_url'] ?? '',
            'privacy_policy_url'  => $configs['privacy_policy_url'] ?? '',
            'service_agreement_url'=> $configs['service_agreement_url'] ?? '',
            'service_wechat'      => $configs['service_wechat'] ?? '',
            'service_phone'       => $configs['service_phone'] ?? '',
            'service_email'       => $configs['service_email'] ?? '',
            'service_qq'          => $configs['service_qq'] ?? '',
            'working_hours'       => $configs['working_hours'] ?? '09:00-22:00',
            'notice'              => $configs['notice'] ?? '',
            'app_version'         => $configs['app_version'] ?? '1.0.0',
            'minor_amount_limit'  => $configs['minor_amount_limit'] ?? '200',
            'large_amount_threshold' => $configs['large_amount_threshold'] ?? '500',
            'club_join_switch'    => $configs['club_join_switch'] ?? '1',
        ];

        return $this->success($publicConfig);
    }

    /**
     * 发送短信验证码
     */
    public function sendSms(Request $request)
    {
        $phone = $request->param('phone', '');
        $scene = $request->param('scene', 'register'); // register/login/verify

        if (empty($phone)) {
            return $this->error('手机号不能为空');
        }

        if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
            return $this->error('手机号格式不正确');
        }

        // 发送频率限制（60秒一次）
        $rateKey = 'sms_rate:' . $phone;
        if (!rate_limit_check($rateKey, 1, 60)) {
            return $this->error('发送过于频繁，请60秒后再试');
        }

        // 每日发送上限
        $dailyKey = 'sms_daily:' . $phone;
        $dailyCount = (int) Cache::get($dailyKey, 0);
        if ($dailyCount >= 10) {
            return $this->error('今日发送次数已达上限');
        }

        // 生成验证码
        $code = generate_code(6);

        // 缓存验证码（5分钟有效）
        $cacheKey = 'sms_' . $scene . ':' . $phone;
        Cache::set($cacheKey, $code, 300);

        // 更新每日计数
        $todayEnd = strtotime(date('Y-m-d') . ' 23:59:59') - time();
        Cache::set($dailyKey, $dailyCount + 1, $todayEnd > 0 ? $todayEnd : 86400);

        // 调用第三方短信API发送
        $this->sendSmsApi($phone, $code, $scene);

        write_action_log('api_send_sms', "发送短信验证码: scene={$scene}, phone=" . mask_sensitive($phone, 'phone'));

        return $this->success([
            'expire_in' => 300,
        ], '验证码已发送');
    }

    // ===================== 私有辅助方法 =====================

    /**
     * 调用短信发送API
     * @param string $phone
     * @param string $code
     * @param string $scene
     */
    private function sendSmsApi(string $phone, string $code, string $scene): void
    {
        try {
            $apiUrl    = config_get('sms.api_url', '');
            $apiKey    = config_get('sms.api_key', '');
            $templateId = config_get('sms.template_id.' . $scene, '');

            if (empty($apiUrl)) {
                // 开发环境模拟
                Log::info("短信验证码模拟发送: phone={$phone}, code={$code}, scene={$scene}");
                return;
            }

            $ch = curl_init($apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'phone'       => $phone,
                'code'        => $code,
                'template_id' => $templateId,
                'scene'       => $scene,
            ], JSON_UNESCAPED_UNICODE));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'X-Api-Key: ' . $apiKey,
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                Log::error('短信发送失败: HTTP ' . $httpCode . ', response: ' . $response);
            }
        } catch (\Throwable $e) {
            Log::error('短信发送异常: ' . $e->getMessage());
        }
    }
}