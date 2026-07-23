<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseController;
use app\service\ServiceDepositService;
use think\facade\Log;
use think\Request;

class ServiceDeposit extends BaseController
{
    public function depositList(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $status  = $request->param('status', '');
        $keyword = $request->param('keyword', '');

        $service = new ServiceDepositService();
        $result = $service->getAllDepositList([
            'status'  => $status,
            'keyword' => $keyword,
        ], $page, $limit);

        $this->operationLog('admin_service_deposit_list', '查看保证金列表');

        return $this->page($result['list'], $result['total'], $page, $limit);
    }

    public function depositDetail(Request $request)
    {
        $playerUserId = $request->paramInt('player_user_id', 0);

        if ($playerUserId <= 0) {
            return $this->error('用户ID无效');
        }

        $service = new ServiceDepositService();
        $deposit = $service->getDeposit($playerUserId);

        return $this->success($deposit);
    }

    public function logList(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $type         = $request->param('type', '');
        $playerUserId = $request->paramInt('player_user_id', 0);

        $service = new ServiceDepositService();
        $result = $service->getAllLogList([
            'type'            => $type,
            'player_user_id'  => $playerUserId,
        ], $page, $limit);

        $this->operationLog('admin_service_deposit_log_list', '查看保证金流水列表');

        return $this->page($result['list'], $result['total'], $page, $limit);
    }

    public function manualDeposit(Request $request)
    {
        $adminId      = $this->adminId();
        $playerUserId = $request->paramInt('player_user_id', 0);
        $amount       = $request->paramInt('amount', 0);
        $description  = $request->param('description', '');

        $error = $this->validateRequired([
            'player_user_id' => $playerUserId,
            'amount'         => $amount,
        ], ['player_user_id', 'amount']);
        if ($error) {
            return $this->error($error);
        }

        if ($amount <= 0) {
            return $this->error('金额必须大于0');
        }

        try {
            $service = new ServiceDepositService();
            $deposit = $service->deposit($playerUserId, $amount, 0, $description ?: '管理员手动充值');

            $this->operationLog('admin_service_deposit_manual_deposit', "手动充值保证金: 打手ID: {$playerUserId}, 金额: {$amount}分");

            return $this->success($deposit, '充值成功');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('手动充值保证金异常: ' . $e->getMessage());
            return $this->error('操作失败');
        }
    }

    public function manualDeduct(Request $request)
    {
        $adminId      = $this->adminId();
        $playerUserId = $request->paramInt('player_user_id', 0);
        $amount       = $request->paramInt('amount', 0);
        $description  = $request->param('description', '');

        $error = $this->validateRequired([
            'player_user_id' => $playerUserId,
            'amount'         => $amount,
        ], ['player_user_id', 'amount']);
        if ($error) {
            return $this->error($error);
        }

        if ($amount <= 0) {
            return $this->error('金额必须大于0');
        }

        try {
            $service = new ServiceDepositService();
            $deposit = $service->deductDeposit($playerUserId, $amount, 0, $description ?: '管理员手动扣除');

            $this->operationLog('admin_service_deposit_manual_deduct', "手动扣除保证金: 打手ID: {$playerUserId}, 金额: {$amount}分");

            return $this->success($deposit, '扣除成功');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('手动扣除保证金异常: ' . $e->getMessage());
            return $this->error('操作失败');
        }
    }

    public function manualRefund(Request $request)
    {
        $adminId      = $this->adminId();
        $playerUserId = $request->paramInt('player_user_id', 0);
        $amount       = $request->paramInt('amount', 0);
        $description  = $request->param('description', '');

        $error = $this->validateRequired([
            'player_user_id' => $playerUserId,
            'amount'         => $amount,
        ], ['player_user_id', 'amount']);
        if ($error) {
            return $this->error($error);
        }

        if ($amount <= 0) {
            return $this->error('金额必须大于0');
        }

        try {
            $service = new ServiceDepositService();
            $deposit = $service->refundDeposit($playerUserId, $amount, 0, $description ?: '管理员手动退还');

            $this->operationLog('admin_service_deposit_manual_refund', "手动退还保证金: 打手ID: {$playerUserId}, 金额: {$amount}分");

            return $this->success($deposit, '退还成功');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('手动退还保证金异常: ' . $e->getMessage());
            return $this->error('操作失败');
        }
    }

    public function freeze(Request $request)
    {
        $adminId      = $this->adminId();
        $playerUserId = $request->paramInt('player_user_id', 0);
        $amount       = $request->paramInt('amount', 0);
        $description  = $request->param('description', '');

        $error = $this->validateRequired([
            'player_user_id' => $playerUserId,
            'amount'         => $amount,
        ], ['player_user_id', 'amount']);
        if ($error) {
            return $this->error($error);
        }

        if ($amount <= 0) {
            return $this->error('金额必须大于0');
        }

        try {
            $service = new ServiceDepositService();
            $deposit = $service->freezeDeposit($playerUserId, $amount, 0, $description ?: '管理员冻结');

            $this->operationLog('admin_service_deposit_freeze', "冻结保证金: 打手ID: {$playerUserId}, 金额: {$amount}分");

            return $this->success($deposit, '冻结成功');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('冻结保证金异常: ' . $e->getMessage());
            return $this->error('操作失败');
        }
    }

    public function unfreeze(Request $request)
    {
        $adminId      = $this->adminId();
        $playerUserId = $request->paramInt('player_user_id', 0);
        $amount       = $request->paramInt('amount', 0);
        $description  = $request->param('description', '');

        $error = $this->validateRequired([
            'player_user_id' => $playerUserId,
            'amount'         => $amount,
        ], ['player_user_id', 'amount']);
        if ($error) {
            return $this->error($error);
        }

        if ($amount <= 0) {
            return $this->error('金额必须大于0');
        }

        try {
            $service = new ServiceDepositService();
            $deposit = $service->unfreezeDeposit($playerUserId, $amount, 0, $description ?: '管理员解冻');

            $this->operationLog('admin_service_deposit_unfreeze', "解冻保证金: 打手ID: {$playerUserId}, 金额: {$amount}分");

            return $this->success($deposit, '解冻成功');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('解冻保证金异常: ' . $e->getMessage());
            return $this->error('操作失败');
        }
    }
}
