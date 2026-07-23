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
        $clubId              = (int) $this->request->post('club_id', 0);
        $fanCount            = (int) $this->request->post('fan_count', 0);
        $platform            = $this->request->post('platform', '');
        $platformAccountId   = $this->request->post('platform_account_id', '');
        $platformAccountUrl  = $this->request->post('platform_account_url', '');
        $screenshots         = $this->request->post('screenshots', []);
        $videoUrl            = $this->request->post('video_url', '');

        $validate = Validate::rule([
            'club_id'    => 'require|integer|>:0',
            'fan_count'  => 'require|integer|>=:100',
            'platform'   => 'require|in:抖音,快手,B站,小红书,微信视频号',
            'video_url'  => 'require',
            'screenshots' => 'require|array',
        ]);

        if (!$validate->check($this->request->post())) {
            return $this->error($validate->getError());
        }

        try {
            $service = new UpMasterService();
            $result = $service->submitCertification(
                $userId, $clubId, $fanCount, $platform,
                $platformAccountId, $platformAccountUrl, $screenshots, $videoUrl
            );
            return $this->success($result, '认证申请已提交，请等待管理员审核');
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

    /**
     * 获取我所属的俱乐部列表（用于申请时选择）
     */
    public function myClubs()
    {
        $userId = request()->userId();
        $clubs = [];
        
        // 1. 用户自己创建的俱乐部
        $ownClubs = \app\model\UserVBadge::where('user_id', $userId)
            ->where('audit_status', \app\model\UserVBadge::AUDIT_PASSED)
            ->where('is_active', 1)
            ->select()
            ->toArray();
        foreach ($ownClubs as $c) {
            $clubs[] = ['id' => $c['id'], 'club_name' => $c['club_name'], 'is_owner' => true];
        }
        
        // 2. 用户所在群聊的俱乐部
        $memberGroupIds = \app\model\GroupChatMember::where('user_id', $userId)
            ->where('status', 1)
            ->column('group_id');
        if (!empty($memberGroupIds)) {
            $groupCreatorIds = \app\model\GroupChat::whereIn('id', $memberGroupIds)
                ->where('status', 1)
                ->column('creator_id');
            if (!empty($groupCreatorIds)) {
                $groupClubs = \app\model\UserVBadge::whereIn('user_id', $groupCreatorIds)
                    ->where('audit_status', \app\model\UserVBadge::AUDIT_PASSED)
                    ->where('is_active', 1)
                    ->select()
                    ->toArray();
                $existIds = array_column($clubs, 'id');
                foreach ($groupClubs as $c) {
                    if (!in_array($c['id'], $existIds)) {
                        $clubs[] = ['id' => $c['id'], 'club_name' => $c['club_name'], 'is_owner' => false];
                    }
                }
            }
        }
        
        return $this->success($clubs);
    }
}