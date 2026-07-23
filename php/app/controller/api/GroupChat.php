<?php
declare(strict_types=1);

namespace app\controller\api;

use app\controller\BaseController;
use app\model\GroupChat;
use app\model\GroupChatMember;
use app\model\GroupChatMessage;
use app\model\User as UserModel;
use app\service\GroupChatService;
use app\service\MinorProtectService;
use think\facade\Log;
use think\Request;

/**
 * 群聊API控制器（用户端）
 */
class GroupChat extends BaseController
{
    /**
     * 我的群聊列表
     */
    public function groupList(Request $request)
    {
        $userId = request()->userId();

        $service = new GroupChatService();
        $list = $service->getMyGroups($userId);

        return $this->success($list);
    }

    /**
     * 创建群聊
     */
    public function createGroup(Request $request)
    {
        $userId    = request()->userId();
        $groupName = $request->param('group_name', '');
        $groupType = $request->paramInt('group_type', 0);
        $avatar    = $request->param('avatar', '');

        $error = $this->validateRequired([
            'group_name' => $groupName,
        ], ['group_name']);
        if ($error) {
            return $this->error($error);
        }

        try {
            $service = new GroupChatService();
            $group = $service->createGroup($userId, $groupName, $groupType, $avatar);

            $this->operationLog('api_group_create', "创建群聊: {$groupName}");

            return $this->success($group, '群聊创建成功');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('创建群聊异常: ' . $e->getMessage());
            return $this->error('创建群聊失败');
        }
    }

    /**
     * 群聊详情
     */
    public function groupDetail(Request $request)
    {
        $userId  = request()->userId();
        $groupId = $request->paramInt('group_id', 0);

        if ($groupId <= 0) {
            return $this->error('群聊ID无效');
        }

        $group = GroupChat::find($groupId);
        if (!$group) {
            return $this->error('群聊不存在', 404);
        }

        // 检查用户是否在群内
        $member = GroupChatMember::where('group_id', $groupId)
            ->where('user_id', $userId)
            ->find();
        if (!$member) {
            return $this->error('无权查看该群聊', 403);
        }

        $data = $group->toArray();
        $data['member_count'] = GroupChatMember::where('group_id', $groupId)->count();
        $data['my_role'] = $member->role;

        $this->operationLog('api_group_detail', "查看群聊详情: ID: {$groupId}");

        return $this->success($data);
    }

    /**
     * 群消息列表
     */
    public function groupMessages(Request $request)
    {
        $userId  = request()->userId();
        $groupId = $request->paramInt('group_id', 0);
        [$page, $limit] = $this->pageParams();

        if ($groupId <= 0) {
            return $this->error('群聊ID无效');
        }

        // 检查用户是否在群内
        $member = GroupChatMember::where('group_id', $groupId)
            ->where('user_id', $userId)
            ->find();
        if (!$member) {
            return $this->error('无权查看该群聊消息', 403);
        }

        $service = new GroupChatService();
        $result = $service->getGroupMessages($groupId, $page, $limit);

        return $this->page($result['list'], $result['total'], $page, $limit);
    }

    /**
     * 发送文字消息
     */
    public function sendText(Request $request)
    {
        $userId  = request()->userId();
        $groupId = $request->paramInt('group_id', 0);
        $content = $request->param('content', '');

        $error = $this->validateRequired([
            'group_id' => $groupId,
            'content'  => $content,
        ], ['group_id', 'content']);
        if ($error) {
            return $this->error($error);
        }

        if (mb_strlen($content) > 500) {
            return $this->error('消息内容不能超过500字');
        }

        try {
            $service = new GroupChatService();
            $message = $service->sendGroupMessage($groupId, $userId, GroupChatMessage::SENDER_USER, GroupChatMessage::TYPE_TEXT, $content);

            write_action_log('api_group_send_text', "发送群文字消息: 群ID: {$groupId}, 用户ID: {$userId}");

            return $this->success($message, '发送成功');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('发送群消息异常: ' . $e->getMessage());
            return $this->error('发送失败');
        }
    }

    /**
     * 发送语音消息
     */
    public function sendVoice(Request $request)
    {
        $userId   = request()->userId();
        $groupId  = $request->paramInt('group_id', 0);
        $voiceUrl = $request->param('voice_url', '');
        $duration = $request->paramInt('duration', 0);

        $error = $this->validateRequired([
            'group_id'  => $groupId,
            'voice_url' => $voiceUrl,
        ], ['group_id', 'voice_url']);
        if ($error) {
            return $this->error($error);
        }

        if ($duration > 60) {
            return $this->error('语音消息不能超过60秒');
        }

        try {
            $service = new GroupChatService();
            $message = $service->sendGroupMessage($groupId, $userId, GroupChatMessage::SENDER_USER, GroupChatMessage::TYPE_VOICE, $voiceUrl);

            write_action_log('api_group_send_voice', "发送群语音消息: 群ID: {$groupId}, 用户ID: {$userId}");

            return $this->success($message, '发送成功');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('发送群语音消息异常: ' . $e->getMessage());
            return $this->error('发送失败');
        }
    }

    /**
     * 发送图片消息
     */
    public function sendImage(Request $request)
    {
        $userId   = request()->userId();
        $groupId  = $request->paramInt('group_id', 0);
        $imageUrl = $request->param('image_url', '');

        $error = $this->validateRequired([
            'group_id'  => $groupId,
            'image_url' => $imageUrl,
        ], ['group_id', 'image_url']);
        if ($error) {
            return $this->error($error);
        }

        try {
            $service = new GroupChatService();
            $message = $service->sendGroupMessage($groupId, $userId, GroupChatMessage::SENDER_USER, GroupChatMessage::TYPE_IMAGE, $imageUrl);

            write_action_log('api_group_send_image', "发送群图片消息: 群ID: {$groupId}, 用户ID: {$userId}");

            return $this->success($message, '发送成功');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('发送群图片消息异常: ' . $e->getMessage());
            return $this->error('发送失败');
        }
    }

    /**
     * 群成员列表
     */
    public function groupMembers(Request $request)
    {
        $userId  = request()->userId();
        $groupId = $request->paramInt('group_id', 0);

        if ($groupId <= 0) {
            return $this->error('群聊ID无效');
        }

        // 检查用户是否在群内
        $member = GroupChatMember::where('group_id', $groupId)
            ->where('user_id', $userId)
            ->find();
        if (!$member) {
            return $this->error('无权查看群成员', 403);
        }

        $service = new GroupChatService();
        $members = $service->getGroupMembers($groupId);

        $this->operationLog('api_group_members', "查看群成员: 群ID: {$groupId}");

        return $this->success($members);
    }

    /**
     * 更新群公告
     */
    public function updateAnnouncement(Request $request)
    {
        $userId  = request()->userId();
        $groupId = $request->paramInt('group_id', 0);
        $content = $request->param('content', '');

        $error = $this->validateRequired([
            'group_id' => $groupId,
            'content'  => $content,
        ], ['group_id', 'content']);
        if ($error) {
            return $this->error($error);
        }

        try {
            $service = new GroupChatService();
            $service->updateAnnouncement($groupId, $content, $userId);

            $this->operationLog('api_group_announcement', "更新群公告: 群ID: {$groupId}");

            return $this->success(null, '群公告更新成功');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('更新群公告异常: ' . $e->getMessage());
            return $this->error('更新失败');
        }
    }

    /**
     * 禁言成员
     */
    public function muteMember(Request $request)
    {
        $userId     = request()->userId();
        $groupId    = $request->paramInt('group_id', 0);
        $targetUid  = $request->paramInt('user_id', 0);
        $duration   = $request->paramInt('duration', 3600);

        $error = $this->validateRequired([
            'group_id' => $groupId,
            'user_id'  => $targetUid,
        ], ['group_id', 'user_id']);
        if ($error) {
            return $this->error($error);
        }

        try {
            $service = new GroupChatService();
            $service->muteMember($groupId, $targetUid, $userId, $duration);

            $this->operationLog('api_group_mute', "禁言成员: 群ID: {$groupId}, 用户ID: {$targetUid}");

            return $this->success(null, '禁言成功');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('禁言成员异常: ' . $e->getMessage());
            return $this->error('禁言失败');
        }
    }

    /**
     * 解除禁言
     */
    public function unmuteMember(Request $request)
    {
        $userId    = request()->userId();
        $groupId   = $request->paramInt('group_id', 0);
        $targetUid = $request->paramInt('user_id', 0);

        $error = $this->validateRequired([
            'group_id' => $groupId,
            'user_id'  => $targetUid,
        ], ['group_id', 'user_id']);
        if ($error) {
            return $this->error($error);
        }

        try {
            $service = new GroupChatService();
            $service->unmuteMember($groupId, $targetUid, $userId);

            $this->operationLog('api_group_unmute', "解除禁言: 群ID: {$groupId}, 用户ID: {$targetUid}");

            return $this->success(null, '解除禁言成功');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('解除禁言异常: ' . $e->getMessage());
            return $this->error('解除禁言失败');
        }
    }

    /**
     * 移出成员
     */
    public function removeMember(Request $request)
    {
        $userId    = request()->userId();
        $groupId   = $request->paramInt('group_id', 0);
        $targetUid = $request->paramInt('user_id', 0);

        $error = $this->validateRequired([
            'group_id' => $groupId,
            'user_id'  => $targetUid,
        ], ['group_id', 'user_id']);
        if ($error) {
            return $this->error($error);
        }

        try {
            $service = new GroupChatService();
            $service->removeMember($groupId, $targetUid, $userId);

            $this->operationLog('api_group_remove_member', "移出成员: 群ID: {$groupId}, 用户ID: {$targetUid}");

            return $this->success(null, '移出成功');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('移出成员异常: ' . $e->getMessage());
            return $this->error('移出失败');
        }
    }

    /**
     * 解散群聊
     */
    public function dissolveGroup(Request $request)
    {
        $userId  = request()->userId();
        $groupId = $request->paramInt('group_id', 0);
        $reason  = $request->param('reason', '无');

        if ($groupId <= 0) {
            return $this->error('群聊ID无效');
        }

        try {
            $service = new GroupChatService();
            $service->dissolveGroup($groupId, $userId, $reason);

            $this->operationLog('api_group_dissolve', "解散群聊: 群ID: {$groupId}");

            return $this->success(null, '群聊已解散');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('解散群聊异常: ' . $e->getMessage());
            return $this->error('解散失败');
        }
    }

    /**
     * 添加成员
     */
    public function addMember(Request $request)
    {
        $userId    = request()->userId();
        $groupId   = $request->paramInt('group_id', 0);
        $targetUid = $request->paramInt('user_id', 0);

        $error = $this->validateRequired([
            'group_id' => $groupId,
            'user_id'  => $targetUid,
        ], ['group_id', 'user_id']);
        if ($error) {
            return $this->error($error);
        }

        $targetUser = UserModel::find($targetUid);
        if (!$targetUser) {
            return $this->error('目标用户不存在', 404);
        }

        if (!$targetUser->getData('is_real_verified')) {
            return $this->error('目标用户未完成实名认证，无法加入群聊');
        }

        $minorProtectService = new MinorProtectService();

        $curfewCheck = $minorProtectService->checkCurfew($targetUid, 'join_group');
        if (!$curfewCheck['pass']) {
            return $this->error($curfewCheck['message'], 403);
        }

        try {
            $service = new GroupChatService();
            $service->addMember($groupId, $targetUid, $userId);

            $this->operationLog('api_group_add_member', "添加成员: 群ID: {$groupId}, 用户ID: {$targetUid}");

            return $this->success(null, '添加成功');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('添加成员异常: ' . $e->getMessage());
            return $this->error('添加失败');
        }
    }

    /**
     * 撤回群消息
     */
    public function recallMessage(Request $request)
    {
        $userId    = request()->userId();
        $messageId = $request->paramInt('message_id', 0);

        if ($messageId <= 0) {
            return $this->error('消息ID无效');
        }

        try {
            $service = new GroupChatService();
            $service->recallGroupMessage($messageId, $userId);
            return $this->success(null, '消息已撤回');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('撤回群消息异常: ' . $e->getMessage());
            return $this->error('撤回失败');
        }
    }

    /**
     * 创建定时公告
     */
    public function createScheduleAnnouncement(Request $request)
    {
        $userId       = request()->userId();
        $groupId      = $request->paramInt('group_id', 0);
        $title        = $request->param('title', '');
        $content      = $request->param('content', '');
        $scheduleTime = $request->param('schedule_time', '');

        $error = $this->validateRequired([
            'group_id'      => $groupId,
            'title'         => $title,
            'content'       => $content,
            'schedule_time' => $scheduleTime,
        ], ['group_id', 'title', 'content', 'schedule_time']);
        if ($error) {
            return $this->error($error);
        }

        try {
            $service = new GroupChatService();
            $schedule = $service->createScheduleAnnouncement($groupId, $userId, $title, $content, $scheduleTime);

            $this->operationLog('api_group_schedule_create', "创建定时公告: 群ID: {$groupId}, 标题: {$title}");

            return $this->success($schedule, '创建成功');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('创建定时公告异常: ' . $e->getMessage());
            return $this->error('创建失败');
        }
    }

    /**
     * 定时公告列表
     */
    public function scheduleAnnouncementList(Request $request)
    {
        $userId  = request()->userId();
        $groupId = $request->paramInt('group_id', 0);
        [$page, $limit] = $this->pageParams();

        if ($groupId <= 0) {
            return $this->error('群聊ID无效');
        }

        $member = GroupChatMember::where('group_id', $groupId)
            ->where('user_id', $userId)
            ->find();
        if (!$member) {
            return $this->error('无权查看', 403);
        }

        $service = new GroupChatService();
        $result = $service->getScheduleAnnouncements($groupId, $page, $limit);

        return $this->page($result['list'], $result['total'], $page, $limit);
    }

    /**
     * 取消定时公告
     */
    public function cancelScheduleAnnouncement(Request $request)
    {
        $userId     = request()->userId();
        $scheduleId = $request->paramInt('schedule_id', 0);

        if ($scheduleId <= 0) {
            return $this->error('定时公告ID无效');
        }

        try {
            $service = new GroupChatService();
            $service->cancelScheduleAnnouncement($scheduleId, $userId);

            $this->operationLog('api_group_schedule_cancel', "取消定时公告: schedule_id: {$scheduleId}");

            return $this->success(null, '已取消');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('取消定时公告异常: ' . $e->getMessage());
            return $this->error('操作失败');
        }
    }
}