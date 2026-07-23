<?php
declare(strict_types=1);

namespace app\controller\api;

use app\controller\BaseController;
use app\model\SystemConfig;
use app\model\UserVBadge;
use think\facade\Validate;

/**
 * 俱乐部入驻（用户端）
 */
class Club extends BaseController
{
    /**
     * 检查俱乐部入驻开关状态
     */
    public function checkSwitch()
    {
        $switch = SystemConfig::getValue('club_join_switch', '1');
        $isOpen = $switch === '1' || $switch === 'true' || $switch === true;

        return $this->success([
            'club_join_open' => (bool) $isOpen,
        ]);
    }

    /**
     * 提交俱乐部入驻申请
     */
    public function submit()
    {
        // 检查开关
        $switch = SystemConfig::getValue('club_join_switch', '1');
        $isOpen = $switch === '1' || $switch === 'true' || $switch === true;
        if (!$isOpen) {
            return $this->error('俱乐部入驻功能暂未开放');
        }

        $userId   = request()->userId();
        $clubName = $this->request->post('club_name', '');
        $clubType = $this->request->post('club_type', ''); // blue_v / green_v
        $description = $this->request->post('description', '');

        $validate = Validate::rule([
            'club_name'   => 'require|length:2,50',
            'club_type'   => 'require|in:blue_v,green_v',
        ]);

        if (!$validate->check($this->request->post())) {
            return $this->error($validate->getError());
        }

        // 检查该用户是否已有待审核或已通过的俱乐部
        $existClub = UserVBadge::where('user_id', $userId)
            ->where('audit_status', 'in', [UserVBadge::AUDIT_PENDING, UserVBadge::AUDIT_PASSED])
            ->where('is_active', 1)
            ->find();
        if ($existClub) {
            return $this->error('您已有俱乐部入驻记录，请勿重复申请');
        }

        // 检查俱乐部名称是否重复
        $nameExist = UserVBadge::where('club_name', $clubName)
            ->where('audit_status', UserVBadge::AUDIT_PASSED)
            ->where('is_active', 1)
            ->find();
        if ($nameExist) {
            return $this->error('该俱乐部名称已被占用');
        }

        $club = UserVBadge::create([
            'user_id'      => $userId,
            'badge_type'   => $clubType,
            'badge_display'=> $clubType,
            'club_name'    => $clubName,
            'audit_status' => UserVBadge::AUDIT_PENDING,
            'is_active'    => 1,
        ]);

        write_action_log('club_join_submit', "俱乐部入驻申请: user_id={$userId}, club_name={$clubName}, type={$clubType}");

        return $this->success([
            'id'          => $club->id,
            'club_name'   => $club->club_name,
            'club_type'   => $club->badge_type,
            'audit_status'=> $club->audit_status,
        ], '入驻申请已提交，请等待管理员审核');
    }

    /**
     * 获取我的俱乐部入驻状态
     */
    public function myStatus()
    {
        $userId = request()->userId();

        $clubs = UserVBadge::where('user_id', $userId)
            ->order('create_time', 'desc')
            ->select()
            ->toArray();

        return $this->success($clubs ?: []);
    }

    /**
     * 获取俱乐部列表（公开，已审核通过且点亮）
     */
    public function list()
    {
        [$page, $limit] = $this->pageParams();

        $query = UserVBadge::where('audit_status', UserVBadge::AUDIT_PASSED)
            ->where('is_active', 1)
            ->order('create_time', 'desc');

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        // 关联创始人信息
        foreach ($list as &$item) {
            $user = \app\model\User::find($item['user_id']);
            $item['founder'] = $user ? [
                'id'       => $user->id,
                'nickname' => $user->getData('nickname'),
                'avatar'   => $user->getData('avatar'),
            ] : null;
        }

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 俱乐部详情
     */
    public function detail()
    {
        $clubId = $this->request->param('id', 0);

        $club = UserVBadge::where('id', $clubId)
            ->where('audit_status', UserVBadge::AUDIT_PASSED)
            ->where('is_active', 1)
            ->find();

        if (!$club) {
            return $this->error('俱乐部不存在或未通过审核', 404);
        }

        $clubData = $club->toArray();

        // 创始人信息
        $user = \app\model\User::find($club->user_id);
        $clubData['founder'] = $user ? [
            'id'       => $user->id,
            'nickname' => $user->getData('nickname'),
            'avatar'   => $user->getData('avatar'),
        ] : null;

        // 群聊数
        $clubData['group_count'] = \app\model\GroupChat::where('creator_id', $club->user_id)
            ->where('status', 1)
            ->count();

        // UP主数
        $clubData['up_master_count'] = \app\model\UpMasterCertification::where('club_id', $club->id)
            ->where('audit_status', \app\model\UpMasterCertification::AUDIT_PASSED)
            ->where('is_active', 1)
            ->count();

        return $this->success($clubData);
    }
}