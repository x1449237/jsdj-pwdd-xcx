<?php
declare(strict_types=1);

namespace app\controller\api;

use app\controller\BaseController;
use app\service\UpMasterService;
use think\facade\Validate;

/**
 * UP主认证（用户端）
 */
class UpMaster extends BaseController
{
    /**
     * 提交认证申请
     */
    public function submit()
    {
        $userId              = request()->userId();
        $fanCount            = (int) $this->request->post('fan_count', 0);
        $platform            = $this->request->post('platform', '');
        $platformAccountId   = $this->request->post('platform_account_id', '');
        $platformAccountUrl  = $this->request->post('platform_account_url', '');
        $screenshots         = $this->request->post('screenshots', []);

        $validate = Validate::rule([
            'fan_count' => 'require|integer|>=:100',
            'platform'  => 'require|in:抖音,快手,B站,小红书,微信视频号',
        ]);

        if (!$validate->check($this->request->post())) {
            return $this->error($validate->getError());
        }

        try {
            $service = new UpMasterService();
            $result = $service->submitCertification(
                $userId, $fanCount, $platform,
                $platformAccountId, $platformAccountUrl, $screenshots
            );
            return $this->success($result, '认证申请已提交');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 获取我的认证状态
     */
    public function myStatus()
    {
        $userId = request()->userId();
        $service = new UpMasterService();
        $cert = $service->getUserCertification($userId);

        return $this->success($cert ?: (object)[]);
    }

    /**
     * 获取我的徽标信息
     */
    public function myBadge()
    {
        $userId = request()->userId();
        $service = new UpMasterService();
        $badge = $service->getUserBadgeInfo($userId);

        return $this->success($badge ?: (object)[]);
    }

    /**
     * 获取等级配置
     */
    public function tierConfigs()
    {
        $service = new UpMasterService();
        $configs = $service->getTierConfigs();
        return $this->success($configs);
    }
}