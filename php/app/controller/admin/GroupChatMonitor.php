<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseController;
use app\model\GroupChat;
use app\model\GroupChatMember;
use app\model\GroupChatMessage;
use app\service\GroupChatService;
use app\service\PlatformService;
use think\facade\Log;
use think\Request;

/**
 * 群聊监察控制器
 */
class GroupChatMonitor extends BaseController
{
    /**
     * 全平台群聊列表
     */
    public function list(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $groupName  = $request->param('group_name', '');
        $groupType  = $request->param('group_type', '');
        $status     = $request->param('status', '');

        $query = GroupChat::order('id', 'desc');

        if (!empty($groupName)) {
            $query->where('group_name', 'like', "%{$groupName}%");
        }
        if ($groupType !== '') {
            $query->where('group_type', (int)$groupType);
        }
        if ($status !== '') {
            $query->where('status', (int)$status);
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        // 补充成员数
        foreach ($list as &$item) {
            $item['member_count'] = GroupChatMember::where('group_id', $item['id'])->count();
        }

        $this->operationLog('admin_group_monitor_list', '查看全平台群聊列表');

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 群聊详情（含成员列表、消息记录）
     */
    public function detail(Request $request)
    {
        $groupId = $request->paramInt('group_id', 0);

        if ($groupId <= 0) {
            return $this->error('群聊ID无效');
        }

        $group = GroupChat::find($groupId);
        if (!$group) {
            return $this->error('群聊不存在', 404);
        }

        $data = $group->toArray();
        $data['members'] = GroupChatMember::where('group_id', $groupId)
            ->order('role', 'desc')
            ->order('create_time', 'asc')
            ->select()
            ->toArray();
        $data['member_count'] = count($data['members']);

        $this->operationLog('admin_group_monitor_detail', "查看群聊详情: 群ID: {$groupId}");

        return $this->success($data);
    }

    /**
     * 群消息查看
     */
    public function messages(Request $request)
    {
        $groupId = $request->paramInt('group_id', 0);
        [$page, $limit] = $this->pageParams();

        if ($groupId <= 0) {
            return $this->error('群聊ID无效');
        }

        $query = GroupChatMessage::where('group_id', $groupId)
            ->order('create_time', 'asc');

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        $this->operationLog('admin_group_monitor_messages', "查看群消息: 群ID: {$groupId}");

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 解散群聊
     */
    public function dissolve(Request $request)
    {
        $adminId       = $this->adminId();
        $groupId       = $request->paramInt('group_id', 0);
        $reason        = $request->param('reason', '无');
        $operatorAccountId = $request->paramInt('account_id', 0);

        if ($groupId <= 0) {
            return $this->error('群聊ID无效');
        }

        try {
            $platformService = new PlatformService();
            $platformService->punishGroup($groupId, 'dissolve', $operatorAccountId, $reason);

            $this->operationLog('admin_group_monitor_dissolve', "解散群聊: 群ID: {$groupId}, 原因: {$reason}");

            return $this->success(null, '群聊已解散');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('解散群聊异常: ' . $e->getMessage());
            return $this->error('操作失败');
        }
    }

    /**
     * 禁言成员
     */
    public function muteMember(Request $request)
    {
        $groupId    = $request->paramInt('group_id', 0);
        $userId     = $request->paramInt('user_id', 0);
        $duration   = $request->paramInt('duration', 3600);
        $operatorAccountId = $request->paramInt('account_id', 0);

        $error = $this->validateRequired([
            'group_id' => $groupId,
            'user_id'  => $userId,
        ], ['group_id', 'user_id']);
        if ($error) {
            return $this->error($error);
        }

        try {
            $service = new GroupChatService();
            $service->muteMember($groupId, $userId, $operatorAccountId, $duration);

            $this->operationLog('admin_group_monitor_mute', "禁言成员: 群ID: {$groupId}, 用户ID: {$userId}");

            return $this->success(null, '禁言成功');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('禁言成员异常: ' . $e->getMessage());
            return $this->error('操作失败');
        }
    }

    /**
     * 解除禁言
     */
    public function unmuteMember(Request $request)
    {
        $groupId    = $request->paramInt('group_id', 0);
        $userId     = $request->paramInt('user_id', 0);
        $operatorAccountId = $request->paramInt('account_id', 0);

        $error = $this->validateRequired([
            'group_id' => $groupId,
            'user_id'  => $userId,
        ], ['group_id', 'user_id']);
        if ($error) {
            return $this->error($error);
        }

        try {
            $service = new GroupChatService();
            $service->unmuteMember($groupId, $userId, $operatorAccountId);

            $this->operationLog('admin_group_monitor_unmute', "解除禁言: 群ID: {$groupId}, 用户ID: {$userId}");

            return $this->success(null, '解除禁言成功');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('解除禁言异常: ' . $e->getMessage());
            return $this->error('操作失败');
        }
    }

    /**
     * 移出成员
     */
    public function expelMember(Request $request)
    {
        $groupId    = $request->paramInt('group_id', 0);
        $userId     = $request->paramInt('user_id', 0);
        $reason     = $request->param('reason', '无');
        $operatorAccountId = $request->paramInt('account_id', 0);

        $error = $this->validateRequired([
            'group_id' => $groupId,
            'user_id'  => $userId,
        ], ['group_id', 'user_id']);
        if ($error) {
            return $this->error($error);
        }

        try {
            $platformService = new PlatformService();
            $platformService->expelMember($groupId, $userId, $operatorAccountId, $reason);

            $this->operationLog('admin_group_monitor_expel', "移出成员: 群ID: {$groupId}, 用户ID: {$userId}");

            return $this->success(null, '移出成功');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('移出成员异常: ' . $e->getMessage());
            return $this->error('操作失败');
        }
    }

    /**
     * 封禁用户
     */
    public function banUser(Request $request)
    {
        $userId     = $request->paramInt('user_id', 0);
        $reason     = $request->param('reason', '无');
        $operatorAccountId = $request->paramInt('account_id', 0);

        if ($userId <= 0) {
            return $this->error('用户ID无效');
        }

        try {
            $platformService = new PlatformService();
            $platformService->punishUser($userId, 'ban', 'permanent', 0, $operatorAccountId, $reason);

            $this->operationLog('admin_group_monitor_ban_user', "封禁用户: 用户ID: {$userId}, 原因: {$reason}");

            return $this->success(null, '用户已封禁');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('封禁用户异常: ' . $e->getMessage());
            return $this->error('操作失败');
        }
    }

    /**
     * 冻结账户资金
     */
    public function freezeAccount(Request $request)
    {
        $userId     = $request->paramInt('user_id', 0);
        $reason     = $request->param('reason', '无');
        $operatorAccountId = $request->paramInt('account_id', 0);

        if ($userId <= 0) {
            return $this->error('用户ID无效');
        }

        try {
            $platformService = new PlatformService();
            $platformService->punishUser($userId, 'freeze', 'permanent', 0, $operatorAccountId, $reason);

            $this->operationLog('admin_group_monitor_freeze', "冻结账户资金: 用户ID: {$userId}, 原因: {$reason}");

            return $this->success(null, '账户资金已冻结');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('冻结账户资金异常: ' . $e->getMessage());
            return $this->error('操作失败');
        }
    }

    /**
     * 处罚记录列表
     */
    public function punishmentLog(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $targetType    = $request->param('target_type', '');
        $punishmentType = $request->param('punishment_type', '');
        $targetId      = $request->param('target_id', '');

        $filters = [];
        if (!empty($targetType)) {
            $filters['target_type'] = $targetType;
        }
        if (!empty($punishmentType)) {
            $filters['punishment_type'] = $punishmentType;
        }
        if (!empty($targetId)) {
            $filters['target_id'] = $targetId;
        }

        $platformService = new PlatformService();
        $result = $platformService->getPunishmentLogs($page, $limit, $filters);

        $this->operationLog('admin_group_monitor_punishment_log', '查看处罚记录列表');

        return $this->page($result['list'], $result['total'], $page, $limit);
    }
}