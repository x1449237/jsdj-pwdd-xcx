<?php
declare(strict_types=1);

namespace app\controller\api;

use app\controller\BaseController;
use think\facade\Log;

/**
 * 订阅消息授权上报
 */
class Subscribe extends BaseController
{
    /**
     * 上报订阅消息授权结果
     * 小程序端调用 wx.requestSubscribeMessage 后上报结果
     */
    public function report()
    {
        $accepted = $this->request->post('accepted', []);
        $rejected = $this->request->post('rejected', []);
        $scene    = $this->request->post('scene', '');
        $userId   = request()->userId();

        if (empty($userId)) {
            return $this->error('用户未登录');
        }

        Log::info("[订阅消息上报] user_id={$userId}, scene={$scene}, accepted=" . json_encode($accepted) . ", rejected=" . json_encode($rejected));

        // 记录用户订阅状态
        try {
            $redis = get_redis();
            $key = "subscribe:user:{$userId}";
            $data = [
                'accepted'     => $accepted,
                'rejected'     => $rejected,
                'scene'        => $scene,
                'update_time'  => date('Y-m-d H:i:s'),
            ];
            $redis->setex($key, 86400 * 30, json_encode($data, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            Log::error("[订阅消息上报] Redis存储失败: " . $e->getMessage());
        }

        return $this->success([], '上报成功');
    }

    /**
     * 获取用户订阅状态
     */
    public function status()
    {
        $userId = $this->request->userId ?? 0;
        if (empty($userId)) {
            return $this->error('用户未登录');
        }

        try {
            $redis = get_redis();
            $key = "subscribe:user:{$userId}";
            $data = $redis->get($key);
            if ($data) {
                return $this->success(json_decode($data, true));
            }
        } catch (\Throwable $e) {
            Log::error("[订阅消息状态] 查询失败: " . $e->getMessage());
        }

        return $this->success(['accepted' => [], 'rejected' => []]);
    }
}