<?php
declare(strict_types=1);

namespace app\service;

use app\model\GroupChat;
use app\model\GroupChatBlacklist;
use app\model\GroupChatMember;
use app\model\GroupChatMessage;
use app\model\PlatformAccount;
use app\model\PunishmentLog;
use think\facade\Db;
use think\facade\Log;

/**
 * 群聊服务
 */
class GroupChatService
{
    /**
     * 创建群聊
     * @param int    $creatorId
     * @param string $groupName
     * @param int    $groupType
     * @param string $avatar
     * @return array
     * @throws \RuntimeException
     */
    public function createGroup(int $creatorId, string $groupName, int $groupType, string $avatar): array
    {
        Db::startTrans();
        try {
            $group = GroupChat::create([
                'group_name'  => $groupName,
                'avatar'      => $avatar,
                'group_type'  => $groupType,
                'creator_id'  => $creatorId,
                'status'      => GroupChat::STATUS_NORMAL,
                'is_muted_all' => 0,
            ]);

            // 创建者自动成为创始人
            GroupChatMember::create([
                'group_id'  => $group->id,
                'user_id'   => $creatorId,
                'role'      => GroupChatMember::ROLE_FOUNDER,
                'join_time' => date('Y-m-d H:i:s'),
            ]);

            // 自动拉入平台官方账号（取第一个启用的）
            $platformAccount = PlatformAccount::where('status', PlatformAccount::STATUS_ENABLED)
                ->order('id', 'asc')
                ->find();
            if ($platformAccount) {
                GroupChatMember::create([
                    'group_id'  => $group->id,
                    'user_id'   => $platformAccount->id,
                    'role'      => GroupChatMember::ROLE_ADMIN,
                    'join_time' => date('Y-m-d H:i:s'),
                ]);
            }

            Db::commit();

            write_action_log('group_chat_create', "创建群聊: {$groupName}, 创建者ID: {$creatorId}, 群ID: {$group->id}");

            return $group->toArray();
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('创建群聊失败: ' . $e->getMessage());
            throw new \RuntimeException('创建群聊失败');
        }
    }

    /**
     * 解散群聊（仅平台官方账号有权）
     * @param int    $groupId
     * @param int    $operatorId
     * @param string $reason
     * @return bool
     * @throws \RuntimeException
     */
    public function dissolveGroup(int $groupId, int $operatorId, string $reason): bool
    {
        if (!$this->isPlatformAccount($operatorId)) {
            throw new \RuntimeException('仅平台官方账号有权解散群聊');
        }

        $group = GroupChat::find($groupId);
        if (!$group) {
            throw new \RuntimeException('群聊不存在');
        }

        if ($group->status == GroupChat::STATUS_DISSOLVED) {
            throw new \RuntimeException('群聊已解散');
        }

        $group->status = GroupChat::STATUS_DISSOLVED;
        $group->save();

        // 记录处罚日志
        PunishmentLog::create([
            'target_type'         => PunishmentLog::TARGET_GROUP,
            'target_id'           => $groupId,
            'punishment_type'     => PunishmentLog::TYPE_DISSOLVE,
            'operator_account_id' => $operatorId,
            'reason'              => $reason,
        ]);

        write_action_log('group_chat_dissolve', "解散群聊: {$group->group_name}, 群ID: {$groupId}, 原因: {$reason}");

        return true;
    }

    /**
     * 添加成员
     * @param int $groupId
     * @param int $userId
     * @param int $operatorId
     * @return bool
     * @throws \RuntimeException
     */
    public function addMember(int $groupId, int $userId, int $operatorId): bool
    {
        // 检查黑名单
        $blacklisted = GroupChatBlacklist::where('group_id', $groupId)
            ->where('user_id', $userId)
            ->find();
        if ($blacklisted) {
            throw new \RuntimeException('该用户已被加入黑名单，无法添加');
        }

        // 检查是否已在群内
        $exist = GroupChatMember::where('group_id', $groupId)
            ->where('user_id', $userId)
            ->find();
        if ($exist) {
            throw new \RuntimeException('该用户已在群内');
        }

        GroupChatMember::create([
            'group_id'  => $groupId,
            'user_id'   => $userId,
            'role'      => GroupChatMember::ROLE_MEMBER,
            'join_time' => date('Y-m-d H:i:s'),
        ]);

        write_action_log('group_chat_add_member', "添加群成员: 群ID: {$groupId}, 用户ID: {$userId}");

        return true;
    }

    /**
     * 移出成员
     * @param int $groupId
     * @param int $userId
     * @param int $operatorId
     * @return bool
     * @throws \RuntimeException
     */
    public function removeMember(int $groupId, int $userId, int $operatorId): bool
    {
        $member = GroupChatMember::where('group_id', $groupId)
            ->where('user_id', $userId)
            ->find();
        if (!$member) {
            throw new \RuntimeException('该用户不在群内');
        }

        // 平台官方账号可移出任何人
        if ($this->isPlatformAccount($operatorId)) {
            $member->delete();
            write_action_log('group_chat_remove_member', "平台官方账号移出成员: 群ID: {$groupId}, 用户ID: {$userId}");
            return true;
        }

        // 检查操作者是否在群内及角色
        $operator = GroupChatMember::where('group_id', $groupId)
            ->where('user_id', $operatorId)
            ->find();
        if (!$operator) {
            throw new \RuntimeException('无权限操作');
        }

        // 管理员只能移出普通成员
        if ($operator->role == GroupChatMember::ROLE_ADMIN || $operator->role == GroupChatMember::ROLE_FOUNDER) {
            if ($member->role != GroupChatMember::ROLE_MEMBER) {
                throw new \RuntimeException('无权移出该成员');
            }
            $member->delete();
            write_action_log('group_chat_remove_member', "管理员移出成员: 群ID: {$groupId}, 用户ID: {$userId}");
            return true;
        }

        throw new \RuntimeException('无权限操作');
    }

    /**
     * 禁言成员
     * @param int $groupId
     * @param int $userId
     * @param int $operatorId
     * @param int $durationSeconds
     * @return bool
     * @throws \RuntimeException
     */
    public function muteMember(int $groupId, int $userId, int $operatorId, int $durationSeconds): bool
    {
        // 平台官方账号可禁言任何人
        if (!$this->isPlatformAccount($operatorId)) {
            // 检查操作者权限
            $operator = GroupChatMember::where('group_id', $groupId)
                ->where('user_id', $operatorId)
                ->find();
            if (!$operator || ($operator->role != GroupChatMember::ROLE_ADMIN && $operator->role != GroupChatMember::ROLE_FOUNDER)) {
                throw new \RuntimeException('无权限操作');
            }
        }

        $member = GroupChatMember::where('group_id', $groupId)
            ->where('user_id', $userId)
            ->find();
        if (!$member) {
            throw new \RuntimeException('该用户不在群内');
        }

        $member->is_muted   = 1;
        $member->mute_until = $durationSeconds > 0 ? date('Y-m-d H:i:s', time() + $durationSeconds) : null;
        $member->save();

        write_action_log('group_chat_mute', "禁言成员: 群ID: {$groupId}, 用户ID: {$userId}, 时长: {$durationSeconds}秒");

        return true;
    }

    /**
     * 解除禁言
     * @param int $groupId
     * @param int $userId
     * @param int $operatorId
     * @return bool
     * @throws \RuntimeException
     */
    public function unmuteMember(int $groupId, int $userId, int $operatorId): bool
    {
        $member = GroupChatMember::where('group_id', $groupId)
            ->where('user_id', $userId)
            ->find();
        if (!$member) {
            throw new \RuntimeException('该用户不在群内');
        }

        if (!$member->is_muted) {
            throw new \RuntimeException('该用户未被禁言');
        }

        $member->is_muted   = 0;
        $member->mute_until = null;
        $member->save();

        write_action_log('group_chat_unmute', "解除禁言: 群ID: {$groupId}, 用户ID: {$userId}");

        return true;
    }

    /**
     * 全员禁言
     * @param int $groupId
     * @param int $operatorId
     * @return bool
     * @throws \RuntimeException
     */
    public function muteAll(int $groupId, int $operatorId): bool
    {
        $group = GroupChat::find($groupId);
        if (!$group) {
            throw new \RuntimeException('群聊不存在');
        }

        $group->is_muted_all = 1;
        $group->save();

        write_action_log('group_chat_mute_all', "全员禁言: 群ID: {$groupId}");

        return true;
    }

    /**
     * 解除全员禁言
     * @param int $groupId
     * @param int $operatorId
     * @return bool
     * @throws \RuntimeException
     */
    public function unmuteAll(int $groupId, int $operatorId): bool
    {
        $group = GroupChat::find($groupId);
        if (!$group) {
            throw new \RuntimeException('群聊不存在');
        }

        $group->is_muted_all = 0;
        $group->save();

        write_action_log('group_chat_unmute_all', "解除全员禁言: 群ID: {$groupId}");

        return true;
    }

    /**
     * 更新群公告
     * @param int    $groupId
     * @param string $content
     * @param int    $operatorId
     * @return bool
     * @throws \RuntimeException
     */
    public function updateAnnouncement(int $groupId, string $content, int $operatorId): bool
    {
        $group = GroupChat::find($groupId);
        if (!$group) {
            throw new \RuntimeException('群聊不存在');
        }

        // 平台官方账号或管理员/创始人
        if (!$this->isPlatformAccount($operatorId)) {
            $member = GroupChatMember::where('group_id', $groupId)
                ->where('user_id', $operatorId)
                ->find();
            if (!$member || ($member->role != GroupChatMember::ROLE_ADMIN && $member->role != GroupChatMember::ROLE_FOUNDER)) {
                throw new \RuntimeException('无权限操作');
            }
        }

        $group->announcement = $content;
        $group->save();

        write_action_log('group_chat_announcement', "更新群公告: 群ID: {$groupId}");

        return true;
    }

    /**
     * 修改群名称
     * @param int    $groupId
     * @param string $name
     * @param int    $operatorId
     * @return bool
     * @throws \RuntimeException
     */
    public function updateGroupName(int $groupId, string $name, int $operatorId): bool
    {
        $group = GroupChat::find($groupId);
        if (!$group) {
            throw new \RuntimeException('群聊不存在');
        }

        $group->group_name = $name;
        $group->save();

        write_action_log('group_chat_update_name', "修改群名称: 群ID: {$groupId}, 新名称: {$name}");

        return true;
    }

    /**
     * 发送群消息
     * @param int    $groupId
     * @param int    $senderId
     * @param int    $senderType
     * @param int    $msgType
     * @param string $content
     * @return array
     * @throws \RuntimeException
     */
    public function sendGroupMessage(int $groupId, int $senderId, int $senderType, int $msgType, string $content): array
    {
        // 检查是否全员禁言
        $group = GroupChat::find($groupId);
        if (!$group) {
            throw new \RuntimeException('群聊不存在');
        }

        if ($group->status == GroupChat::STATUS_DISSOLVED) {
            throw new \RuntimeException('群聊已解散');
        }

        // 平台官方账号不受禁言限制
        if ($senderType == GroupChatMessage::SENDER_USER) {
            if ($group->is_muted_all) {
                throw new \RuntimeException('群聊已全员禁言');
            }

            $member = GroupChatMember::where('group_id', $groupId)
                ->where('user_id', $senderId)
                ->find();
            if (!$member) {
                throw new \RuntimeException('您不在群内');
            }

            if ($member->is_muted) {
                if ($member->mute_until && strtotime($member->mute_until) > time()) {
                    throw new \RuntimeException('您已被禁言至 ' . $member->mute_until);
                }
                throw new \RuntimeException('您已被禁言');
            }
        }

        $message = GroupChatMessage::create([
            'group_id'    => $groupId,
            'sender_id'   => $senderId,
            'sender_type' => $senderType,
            'msg_type'    => $msgType,
            'content'     => $content,
            'status'      => GroupChatMessage::STATUS_NORMAL,
        ]);

        return $message->toArray();
    }

    /**
     * 获取群消息列表
     * @param int $groupId
     * @param int $page
     * @param int $limit
     * @return array
     */
    public function getGroupMessages(int $groupId, int $page, int $limit): array
    {
        $query = GroupChatMessage::where('group_id', $groupId)
            ->where('status', GroupChatMessage::STATUS_NORMAL)
            ->order('create_time', 'asc');

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        return ['list' => $list, 'total' => $total];
    }

    /**
     * 获取我的群聊列表
     * @param int $userId
     * @return array
     */
    public function getMyGroups(int $userId): array
    {
        // 获取用户所在的所有群
        $groupIds = GroupChatMember::where('user_id', $userId)
            ->column('group_id');

        if (empty($groupIds)) {
            return [];
        }

        $groups = GroupChat::whereIn('id', $groupIds)
            ->where('status', GroupChat::STATUS_NORMAL)
            ->order('id', 'desc')
            ->select()
            ->toArray();

        return $groups;
    }

    /**
     * 获取群成员列表
     * @param int $groupId
     * @return array
     */
    public function getGroupMembers(int $groupId): array
    {
        return GroupChatMember::where('group_id', $groupId)
            ->order('role', 'desc')
            ->order('create_time', 'asc')
            ->select()
            ->toArray();
    }

    /**
     * 加入黑名单
     * @param int $groupId
     * @param int $userId
     * @param int $operatorId
     * @return bool
     * @throws \RuntimeException
     */
    public function addToBlacklist(int $groupId, int $userId, int $operatorId): bool
    {
        $exist = GroupChatBlacklist::where('group_id', $groupId)
            ->where('user_id', $userId)
            ->find();
        if ($exist) {
            throw new \RuntimeException('该用户已在黑名单中');
        }

        GroupChatBlacklist::create([
            'group_id'    => $groupId,
            'user_id'     => $userId,
            'operator_id' => $operatorId,
        ]);

        write_action_log('group_chat_blacklist', "加入黑名单: 群ID: {$groupId}, 用户ID: {$userId}");

        return true;
    }

    /**
     * 检查用户是否是平台官方账号
     * @param int $userId
     * @return bool
     */
    public function isPlatformAccount(int $userId): bool
    {
        $account = PlatformAccount::where('id', $userId)
            ->where('status', PlatformAccount::STATUS_ENABLED)
            ->find();
        return $account !== null;
    }
}