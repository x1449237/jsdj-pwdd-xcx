<?php
declare(strict_types=1);

namespace app\service;

use app\model\UpMasterCertification;
use app\model\UpMasterTierConfig;
use app\model\User;
use think\facade\Db;
use think\facade\Log;

/**
 * UP主认证服务
 */
class UpMasterService
{
    /**
     * 提交认证申请
     * @param int    $userId
     * @param int    $clubId
     * @param int    $fanCount
     * @param string $platform
     * @param string $platformAccountId
     * @param string $platformAccountUrl
     * @param array  $screenshots
     * @param string $videoUrl
     * @return array
     */
    public function submitCertification(
        int $userId,
        int $clubId,
        int $fanCount,
        string $platform,
        string $platformAccountId,
        string $platformAccountUrl,
        array $screenshots,
        string $videoUrl
    ): array {
        // 1. 校验俱乐部存在且有效
        $club = \app\model\UserVBadge::where('id', $clubId)
            ->where('audit_status', \app\model\UserVBadge::AUDIT_PASSED)
            ->where('is_active', 1)
            ->find();
        if (!$club) {
            throw new \RuntimeException('俱乐部不存在或未通过审核');
        }

        // 2. 校验用户属于该俱乐部
        $isMember = false;
        // 方式1：检查群聊成员
        $clubGroupIds = \app\model\GroupChat::where('creator_id', $club->user_id)
            ->where('status', 1)
            ->column('id');
        if (!empty($clubGroupIds)) {
            $memberCount = \app\model\GroupChatMember::whereIn('group_id', $clubGroupIds)
                ->where('user_id', $userId)
                ->where('status', 1)
                ->count();
            $isMember = $memberCount > 0;
        }
        // 方式2：用户自己就是俱乐部创始人
        if (!$isMember && $userId == $club->user_id) {
            $isMember = true;
        }

        if (!$isMember) {
            throw new \RuntimeException('您不属于该俱乐部，无法通过该俱乐部申请认证');
        }

        // 3. 校验录屏材料
        if (empty($videoUrl)) {
            throw new \RuntimeException('请上传从手机桌面到个人主页的录屏视频');
        }

        // 4. 判断是否有待审核的申请
        $pending = UpMasterCertification::where('user_id', $userId)
            ->where('audit_status', UpMasterCertification::AUDIT_PENDING)
            ->find();
        if ($pending) {
            throw new \RuntimeException('您有正在审核中的认证申请，请耐心等待');
        }

        // 5. 计算粉丝数对应等级
        $tierConfig = $this->getTierByFanCount($fanCount);
        if (!$tierConfig) {
            throw new \RuntimeException('粉丝数未达到最低认证门槛（100粉）');
        }

        $cert = UpMasterCertification::create([
            'user_id'             => $userId,
            'club_id'             => $clubId,
            'tier'                => $tierConfig['tier'],
            'tier_name'           => $tierConfig['tier_name'],
            'fan_count'           => $fanCount,
            'platform'            => $platform,
            'platform_account_id' => $platformAccountId,
            'platform_account_url'=> $platformAccountUrl,
            'screenshot_urls'     => json_encode($screenshots, JSON_UNESCAPED_UNICODE),
            'video_url'           => $videoUrl,
            'audit_status'        => UpMasterCertification::AUDIT_PENDING,
            'badge_color'         => $tierConfig['bg_color'],
            'badge_size'          => $tierConfig['badge_size'],
        ]);

        write_action_log('up_master_submit', "UP主认证申请: user_id={$userId}, club_id={$clubId}, tier={$tierConfig['tier_name']}, fan_count={$fanCount}");

        return $cert->toArray();
    }

    /**
     * 审核通过
     * @param int    $certId
     * @param int    $auditorId
     * @param int    $verifiedFanCount
     * @param string $remark
     * @return bool
     */
    public function approveCertification(int $certId, int $auditorId, int $verifiedFanCount = 0, string $remark = ''): bool
    {
        $cert = UpMasterCertification::find($certId);
        if (!$cert) {
            throw new \RuntimeException('认证记录不存在');
        }

        // 停用该用户之前的认证
        UpMasterCertification::where('user_id', $cert->user_id)
            ->where('is_active', 1)
            ->update(['is_active' => 0]);

        $cert->audit_status       = UpMasterCertification::AUDIT_PASSED;
        $cert->audit_time         = date('Y-m-d H:i:s');
        $cert->auditor_id         = $auditorId;
        $cert->audit_remark       = $remark;
        $cert->fan_count_verified = $verifiedFanCount > 0 ? $verifiedFanCount : $cert->fan_count;
        $cert->is_active          = 1;
        $cert->save();

        write_action_log('up_master_approve', "UP主认证通过: cert_id={$certId}, user_id={$cert->user_id}, tier={$cert->tier_name}");

        return true;
    }

    /**
     * 审核驳回
     * @param int    $certId
     * @param int    $auditorId
     * @param string $remark
     * @return bool
     */
    public function rejectCertification(int $certId, int $auditorId, string $remark): bool
    {
        $cert = UpMasterCertification::find($certId);
        if (!$cert) {
            throw new \RuntimeException('认证记录不存在');
        }

        $cert->audit_status = UpMasterCertification::AUDIT_REJECTED;
        $cert->audit_time   = date('Y-m-d H:i:s');
        $cert->auditor_id   = $auditorId;
        $cert->audit_remark = $remark;
        $cert->save();

        write_action_log('up_master_reject', "UP主认证驳回: cert_id={$certId}, remark={$remark}");

        return true;
    }

    /**
     * 吊销认证
     * @param int $certId
     * @param int $operatorId
     * @return bool
     */
    public function revokeCertification(int $certId, int $operatorId): bool
    {
        $cert = UpMasterCertification::find($certId);
        if (!$cert) {
            throw new \RuntimeException('认证记录不存在');
        }

        $cert->is_active = 0;
        $cert->save();

        write_action_log('up_master_revoke', "UP主认证吊销: cert_id={$certId}, user_id={$cert->user_id}");

        return true;
    }

    /**
     * 获取用户当前UP主认证信息
     * @param int $userId
     * @return array|null
     */
    public function getUserCertification(int $userId): ?array
    {
        $cert = UpMasterCertification::where('user_id', $userId)
            ->where('is_active', 1)
            ->where('audit_status', UpMasterCertification::AUDIT_PASSED)
            ->find();

        return $cert ? $cert->toArray() : null;
    }

    /**
     * 获取用户UP主徽标信息（供前端渲染用）
     * @param int $userId
     * @return array|null 返回 {tier, tier_name, badge_text, badge_color, badge_size, effect_type}
     */
    public function getUserBadgeInfo(int $userId): ?array
    {
        $cert = $this->getUserCertification($userId);
        if (!$cert) {
            return null;
        }

        $tierConfig = UpMasterTierConfig::where('tier', $cert['tier'])->find();
        if (!$tierConfig) {
            return null;
        }

        return [
            'tier'            => $cert['tier'],
            'tier_name'       => $cert['tier_name'],
            'badge_text'      => $cert['badge_text'] ?? 'UP',
            'badge_color'     => $tierConfig->bg_color,
            'badge_size'      => $tierConfig->badge_size,
            'highlight_color' => $tierConfig->highlight_color,
            'text_color'      => $tierConfig->text_color,
            'effect_type'     => $tierConfig->effect_type,
            'badge_type'      => 'up_master_' . $cert['tier'],
        ];
    }

    /**
     * 根据粉丝数计算对应等级
     * @param int $fanCount
     * @return array|null
     */
    private function getTierByFanCount(int $fanCount): ?array
    {
        $tiers = UpMasterTierConfig::order('tier', 'desc')->select()->toArray();
        foreach ($tiers as $tier) {
            if ($fanCount >= $tier['fan_threshold']) {
                return $tier;
            }
        }
        return null;
    }

    /**
     * 获取所有认证等级配置
     * @return array
     */
    public function getTierConfigs(): array
    {
        return UpMasterTierConfig::order('sort', 'asc')->select()->toArray();
    }

    /**
     * 认证列表（管理后台用）
     * @param int    $page
     * @param int    $limit
     * @param int    $auditStatus
     * @param int    $tier
     * @return array
     */
    public function getCertificationList(int $page, int $limit, int $auditStatus = -1, int $tier = 0): array
    {
        $query = UpMasterCertification::with(['club'])->order('create_time', 'desc');

        if ($auditStatus >= 0) {
            $query->where('audit_status', $auditStatus);
        }
        if ($tier > 0) {
            $query->where('tier', $tier);
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        // 关联 club_name
        foreach ($list as &$item) {
            $item['club_name'] = $item['club']['club_name'] ?? '';
            unset($item['club']);
        }

        return ['list' => $list, 'total' => $total];
    }
}