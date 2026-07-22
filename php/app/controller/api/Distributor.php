<?php
declare(strict_types=1);

namespace app\controller\api;

use app\controller\BaseController;
use app\model\DistributorCommission;
use app\model\DistributorFirstReward;
use app\model\InviteBindLog;
use app\model\RiskControlLog;
use app\model\User as UserModel;
use think\facade\Log;
use think\Request;

/**
 * 分销商端控制器（微信小程序端）
 */
class Distributor extends BaseController
{
    /**
     * 分销中心（下级统计、佣金总览）
     */
    public function center(Request $request)
    {
        $userId = request()->userId();

        $user = UserModel::find($userId);
        if (!$user) {
            return $this->error('用户不存在', 404);
        }

        // 统计下级人数
        $level1Count = InviteBindLog::where('inviter_id', $userId)->count();
        $level1Ids   = InviteBindLog::where('inviter_id', $userId)->column('user_id');

        $level2Count = 0;
        if (!empty($level1Ids)) {
            $level2Count = InviteBindLog::whereIn('inviter_id', $level1Ids)->count();
        }

        // 佣金总览
        $totalCommission = DistributorCommission::where('user_id', $userId)
            ->where('status', DistributorCommission::STATUS_SETTLED)
            ->sum('amount');
        $pendingCommission = DistributorCommission::where('user_id', $userId)
            ->where('status', DistributorCommission::STATUS_PENDING)
            ->sum('amount');

        // 首单奖励
        $firstReward = DistributorFirstReward::where('user_id', $userId)
            ->where('status', DistributorFirstReward::STATUS_GRANTED)
            ->sum('amount');

        return $this->success([
            'balance'            => $user->getData('balance'),
            'frozen_balance'     => $user->getData('frozen_balance'),
            'level1_count'       => $level1Count,
            'level2_count'       => $level2Count,
            'total_subordinates' => $level1Count + $level2Count,
            'total_commission'   => $totalCommission ? fen_to_yuan((int) $totalCommission) : '0.00',
            'pending_commission' => $pendingCommission ? fen_to_yuan((int) $pendingCommission) : '0.00',
            'first_reward'       => $firstReward ? fen_to_yuan((int) $firstReward) : '0.00',
        ]);
    }

    /**
     * 下级管理（强制2级）
     */
    public function subordinates(Request $request)
    {
        $userId = request()->userId();
        [$page, $limit] = $this->pageParams();
        $level = $request->paramInt('level', 1);

        if (!in_array($level, [1, 2])) {
            return $this->error('只能查看1级或2级下级');
        }

        if ($level === 1) {
            // 一级下级
            $query = InviteBindLog::where('inviter_id', $userId)->order('id', 'desc');
            $total = $query->count();
            $binds = $query->page($page, $limit)->select()->toArray();

            $list = [];
            foreach ($binds as $bind) {
                $subUser = UserModel::find($bind['user_id']);
                if ($subUser) {
                    $list[] = [
                        'user_id'     => $subUser->id,
                        'nickname'    => $subUser->getData('nickname'),
                        'avatar'      => $subUser->getData('avatar'),
                        'bind_time'   => $bind['bind_time'] ?? $bind['create_time'],
                        'level'       => 1,
                        'order_count' => $subUser->orders()->count(),
                    ];
                }
            }
        } else {
            // 二级下级
            $level1Ids = InviteBindLog::where('inviter_id', $userId)->column('user_id');
            if (empty($level1Ids)) {
                return $this->page([], 0, $page, $limit);
            }

            $query = InviteBindLog::whereIn('inviter_id', $level1Ids)->order('id', 'desc');
            $total = $query->count();
            $binds = $query->page($page, $limit)->select()->toArray();

            $list = [];
            foreach ($binds as $bind) {
                $subUser = UserModel::find($bind['user_id']);
                if ($subUser) {
                    $list[] = [
                        'user_id'     => $subUser->id,
                        'nickname'    => $subUser->getData('nickname'),
                        'avatar'      => $subUser->getData('avatar'),
                        'bind_time'   => $bind['bind_time'] ?? $bind['create_time'],
                        'level'       => 2,
                        'inviter_id'  => $bind['inviter_id'],
                        'order_count' => $subUser->orders()->count(),
                    ];
                }
            }
        }

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 佣金收益列表
     */
    public function commissionList(Request $request)
    {
        $userId = request()->userId();
        [$page, $limit] = $this->pageParams();
        $status = $request->param('status', '');
        $startDate = $request->param('start_date', '');
        $endDate   = $request->param('end_date', '');

        $query = DistributorCommission::where('user_id', $userId)->order('id', 'desc');

        if ($status !== '') {
            $query->where('status', (int) $status);
        }
        if (!empty($startDate)) {
            $query->where('create_time', '>=', $startDate . ' 00:00:00');
        }
        if (!empty($endDate)) {
            $query->where('create_time', '<=', $endDate . ' 23:59:59');
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        // 补充订单信息
        foreach ($list as &$item) {
            $order = \app\model\Order::find($item['order_id']);
            if ($order) {
                $item['order_sn']    = $order->getData('order_sn');
                $item['order_amount'] = $order->getData('order_amount');
            }
        }

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 首单奖励状态
     */
    public function firstReward(Request $request)
    {
        $userId = request()->userId();

        $rewards = DistributorFirstReward::where('user_id', $userId)
            ->order('id', 'desc')
            ->select()
            ->toArray();

        $totalGranted = DistributorFirstReward::where('user_id', $userId)
            ->where('status', DistributorFirstReward::STATUS_GRANTED)
            ->sum('amount');
        $totalPending = DistributorFirstReward::where('user_id', $userId)
            ->where('status', DistributorFirstReward::STATUS_PENDING)
            ->sum('amount');

        return $this->success([
            'total_granted'    => $totalGranted ? fen_to_yuan((int) $totalGranted) : '0.00',
            'total_pending'    => $totalPending ? fen_to_yuan((int) $totalPending) : '0.00',
            'reward_count'     => count($rewards),
            'list'             => $rewards,
        ]);
    }
}