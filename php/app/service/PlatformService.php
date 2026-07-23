<?php
declare(strict_types=1);

namespace app\service;

use app\model\Admin;
use app\model\GroupChat;
use app\model\GroupChatBlacklist;
use app\model\GroupChatMember;
use app\model\PlatformAccount;
use app\model\PunishmentLog;
use app\model\UpMasterCertification;
use app\model\UpMasterTierConfig;
use app\model\UserVBadge;
use think\facade\Db;
use think\facade\Log;

/**
 * 平台服务
 */
class PlatformService
{
    /**
     * 创建平台官方账号（仅超级管理员）
     * @param string $nickname
     * @param string $avatar
     * @param int    $creatorId
     * @return array
     * @throws \RuntimeException
     */
    public function createOfficialAccount(string $nickname, string $avatar, int $creatorId): array
    {
        // 检查创建者是否为超级管理员
        $admin = Admin::find($creatorId);
        if (!$admin || $admin->getData('status') != Admin::STATUS_ENABLED) {
            throw new \RuntimeException('仅超级管理员可创建平台官方账号');
        }

        $account = PlatformAccount::create([
            'nickname'   => $nickname,
            'avatar'     => $avatar,
            'status'     => PlatformAccount::STATUS_ENABLED,
            'creator_id' => $creatorId,
        ]);

        write_action_log('platform_account_create', "创建平台官方账号: {$nickname}, ID: {$account->id}");

        return $account->toArray();
    }

    /**
     * 停用平台官方账号
     * @param int $accountId
     * @return bool
     * @throws \RuntimeException
     */
    public function disableAccount(int $accountId): bool
    {
        $account = PlatformAccount::find($accountId);
        if (!$account) {
            throw new \RuntimeException('平台官方账号不存在');
        }

        $account->status = PlatformAccount::STATUS_DISABLED;
        $account->save();

        write_action_log('platform_account_disable', "停用平台官方账号: ID: {$accountId}");

        return true;
    }

    /**
     * 获取第一个启用的平台官方账号
     * @return array|null
     */
    public function getActiveAccount(): ?array
    {
        $account = PlatformAccount::where('status', PlatformAccount::STATUS_ENABLED)
            ->order('id', 'asc')
            ->find();
        return $account ? $account->toArray() : null;
    }

    /**
     * 获取用户V标状态
     * @param int $userId
     * @return array|null
     */
    public function getUserVBadge(int $userId): ?array
    {
        $badge = UserVBadge::where('user_id', $userId)->find();
        if (!$badge) {
            return null;
        }

        return [
            'v_badge_type'    => $badge->v_badge_type,
            'v_badge_display' => $badge->v_badge_display,
        ];
    }

    /**
     * 返回V标显示信息供前端渲染
     * @param int $userId
     * @return array|null
     */
    public function getVBadgeDisplay(int $userId): ?array
    {
        $badge = $this->getUserVBadge($userId);
        if (!$badge) {
            return null;
        }

        return [
            'type' => $badge['v_badge_type'],
            'text' => $badge['v_badge_display'],
            'icon' => '',
        ];
    }

    /**
     * 获取用户完整徽标信息（V标 + UP主徽标）
     * @param int $userId
     * @return array
     */
    public function getUserBadgeInfo(int $userId): array
    {
        $result = [
            'v_badge'       => null,
            'up_master_badge' => null,
        ];

        // V标
        $vBadge = $this->getVBadgeDisplay($userId);
        if ($vBadge) {
            $result['v_badge'] = $vBadge;
        }

        // UP主认证徽标
        $upCert = UpMasterCertification::where('user_id', $userId)
            ->where('is_active', 1)
            ->where('audit_status', UpMasterCertification::AUDIT_PASSED)
            ->find();

        if ($upCert) {
            $tierConfig = UpMasterTierConfig::where('tier', $upCert->tier)->find();
            $result['up_master_badge'] = [
                'tier'            => $upCert->tier,
                'tier_name'       => $upCert->tier_name,
                'badge_type'      => 'up_' . ['bronze', 'advanced', 'high', 'elite', 'master', 'supreme'][$upCert->tier - 1],
                'badge_text'      => 'UP',
                'bg_color'        => $tierConfig ? $tierConfig->bg_color : '',
                'highlight_color' => $tierConfig ? $tierConfig->highlight_color : '',
                'text_color'      => $tierConfig ? $tierConfig->text_color : '#FFFFFF',
                'badge_size'      => $tierConfig ? $tierConfig->badge_size : 'small',
                'effect_type'     => $tierConfig ? $tierConfig->effect_type : '',
            ];
        }

        return $result;
    }

    /**
     * 处罚用户
     * @param int    $targetUserId
     * @param string $punishmentType
     * @param string $durationType
     * @param int    $durationSeconds
     * @param int    $operatorAccountId
     * @param string $reason
     * @return bool
     * @throws \RuntimeException
     */
    public function punishUser(int $targetUserId, string $punishmentType, string $durationType, int $durationSeconds, int $operatorAccountId, string $reason): bool
    {
        $log = PunishmentLog::create([
            'target_type'          => PunishmentLog::TARGET_USER,
            'target_id'            => $targetUserId,
            'punishment_type'      => $punishmentType,
            'duration_type'        => $durationType,
            'duration_seconds'     => $durationSeconds,
            'operator_account_id'  => $operatorAccountId,
            'reason'               => $reason,
        ]);

        write_action_log('platform_punish_user', "处罚用户: 用户ID: {$targetUserId}, 类型: {$punishmentType}, 原因: {$reason}");

        return true;
    }

    /**
     * 处罚群聊（解散）
     * @param int    $targetGroupId
     * @param string $punishmentType
     * @param int    $operatorAccountId
     * @param string $reason
     * @return bool
     * @throws \RuntimeException
     */
    public function punishGroup(int $targetGroupId, string $punishmentType, int $operatorAccountId, string $reason): bool
    {
        $group = GroupChat::find($targetGroupId);
        if (!$group) {
            throw new \RuntimeException('群聊不存在');
        }

        $group->status = GroupChat::STATUS_DISSOLVED;
        $group->save();

        PunishmentLog::create([
            'target_type'          => PunishmentLog::TARGET_GROUP,
            'target_id'            => $targetGroupId,
            'punishment_type'      => $punishmentType,
            'operator_account_id'  => $operatorAccountId,
            'reason'               => $reason,
        ]);

        write_action_log('platform_punish_group', "处罚群聊: 群ID: {$targetGroupId}, 类型: {$punishmentType}, 原因: {$reason}");

        return true;
    }

    /**
     * 移出成员并加入黑名单
     * @param int    $groupId
     * @param int    $userId
     * @param int    $operatorAccountId
     * @param string $reason
     * @return bool
     * @throws \RuntimeException
     */
    public function expelMember(int $groupId, int $userId, int $operatorAccountId, string $reason): bool
    {
        // 移出成员
        $member = GroupChatMember::where('group_id', $groupId)
            ->where('user_id', $userId)
            ->find();
        if ($member) {
            $member->delete();
        }

        // 加入黑名单
        $exist = GroupChatBlacklist::where('group_id', $groupId)
            ->where('user_id', $userId)
            ->find();
        if (!$exist) {
            GroupChatBlacklist::create([
                'group_id'    => $groupId,
                'user_id'     => $userId,
                'operator_id' => $operatorAccountId,
            ]);
        }

        write_action_log('platform_expel_member', "移出成员并加入黑名单: 群ID: {$groupId}, 用户ID: {$userId}, 原因: {$reason}");

        return true;
    }

    /**
     * 获取处罚记录列表
     * @param int   $page
     * @param int   $limit
     * @param array $filters
     * @return array
     */
    public function getPunishmentLogs(int $page, int $limit, array $filters): array
    {
        $query = PunishmentLog::order('id', 'desc');

        if (!empty($filters['target_type'])) {
            $query->where('target_type', $filters['target_type']);
        }
        if (!empty($filters['punishment_type'])) {
            $query->where('punishment_type', $filters['punishment_type']);
        }
        if (!empty($filters['target_id'])) {
            $query->where('target_id', (int)$filters['target_id']);
        }
        if (!empty($filters['start_time'])) {
            $query->where('create_time', '>=', $filters['start_time']);
        }
        if (!empty($filters['end_time'])) {
            $query->where('create_time', '<=', $filters['end_time']);
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        return ['list' => $list, 'total' => $total];
    }

    /**
     * 检查用户是否拥有平台权限（是平台官方账号）
     * @param int $userId
     * @return bool
     */
    public function hasPlatformPermission(int $userId): bool
    {
        $account = PlatformAccount::where('id', $userId)
            ->where('status', PlatformAccount::STATUS_ENABLED)
            ->find();
        return $account !== null;
    }
}