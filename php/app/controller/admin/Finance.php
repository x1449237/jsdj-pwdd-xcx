<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseController;
use app\model\Withdraw;
use app\model\WithdrawConfig;
use app\model\SystemConfig;
use think\facade\Log;
use think\Request;

/**
 * 资金与提现控制器
 */
class Finance extends BaseController
{
    /**
     * 提现列表
     */
    public function withdrawList(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $status   = $request->param('status', '');
        $userId   = $request->paramInt('user_id', 0);
        $method   = $request->paramInt('method', 0);
        $startDate = $request->param('start_date', '');
        $endDate   = $request->param('end_date', '');

        $query = Withdraw::order('id', 'desc');

        if ($status !== '') {
            $query->where('status', (int)$status);
        }

        if ($userId > 0) {
            $query->where('user_id', $userId);
        }

        if ($method > 0) {
            $query->where('method', $method);
        }

        if (!empty($startDate)) {
            $query->where('create_time', '>=', $startDate . ' 00:00:00');
        }
        if (!empty($endDate)) {
            $query->where('create_time', '<=', $endDate . ' 23:59:59');
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        $this->operationLog('admin_finance_withdraw_list', '查看提现列表');

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 提现审核（通过/拒绝）
     */
    public function withdrawAudit(Request $request)
    {
        $id     = $request->paramInt('id', 0);
        $action = $request->param('action', ''); // approve/reject
        $remark = $request->param('remark', '');

        if ($id <= 0) {
            return $this->error('提现ID无效');
        }

        $withdraw = Withdraw::find($id);
        if (!$withdraw) {
            return $this->error('提现记录不存在', 404);
        }

        if ($withdraw->getData('status') != Withdraw::STATUS_PENDING) {
            return $this->error('该提现申请已处理');
        }

        if ($action === 'approve') {
            $withdraw->status = Withdraw::STATUS_SUCCESS;
            $withdraw->remark = $remark ?: '管理员审核通过';
            $withdraw->processed_time = date('Y-m-d H:i:s');
            $withdraw->save();

            $this->operationLog('admin_finance_withdraw_approve', "提现审核通过，ID:{$id}，金额:{$withdraw->amount}");
            return $this->success(null, '提现审核通过');
        } elseif ($action === 'reject') {
            if (empty($remark)) {
                return $this->error('拒绝原因不能为空');
            }

            $withdraw->status = Withdraw::STATUS_FAIL;
            $withdraw->remark = $remark;
            $withdraw->processed_time = date('Y-m-d H:i:s');
            $withdraw->save();

            // 退款到用户余额
            $user = \app\model\User::find($withdraw->getData('user_id'));
            if ($user) {
                $user->balance = bc_add($user->getData('balance'), $withdraw->getData('amount'));
                $user->save();
            }

            $this->operationLog('admin_finance_withdraw_reject', "提现审核拒绝，ID:{$id}，原因: {$remark}");
            return $this->success(null, '提现审核已拒绝');
        } else {
            return $this->error('无效操作，可选: approve/reject');
        }
    }

    /**
     * 提现配置（间隔/冻结期/手续费）
     */
    public function withdrawConfig(Request $request)
    {
        $minAmount    = $request->param('min_amount', '');
        $maxAmount    = $request->param('max_amount', '');
        $feeRate      = $request->param('fee_rate', '');
        $dailyLimit   = $request->param('daily_limit', '');
        $dailyAmount  = $request->param('daily_amount', '');
        $intervalDays = $request->paramInt('interval_days', 0);
        $freezeDays   = $request->paramInt('freeze_days', 0);

        $config = WithdrawConfig::order('id', 'desc')->find();
        if (!$config) {
            $config = new WithdrawConfig();
        }

        $changes = [];

        if ($minAmount !== '') {
            $config->min_amount = $minAmount;
            $changes[] = "最低提现金额: {$minAmount}";
        }
        if ($maxAmount !== '') {
            $config->max_amount = $maxAmount;
            $changes[] = "最高提现金额: {$maxAmount}";
        }
        if ($feeRate !== '') {
            $config->fee_rate = $feeRate;
            $changes[] = "手续费率: {$feeRate}";
        }
        if ($dailyLimit !== '') {
            $config->daily_limit = (int)$dailyLimit;
            $changes[] = "每日次数限制: {$dailyLimit}";
        }
        if ($dailyAmount !== '') {
            $config->daily_amount = $dailyAmount;
            $changes[] = "每日金额限制: {$dailyAmount}";
        }
        if ($intervalDays > 0) {
            // 间隔天数存储在 system_config 中
            SystemConfig::where('key', 'withdraw_interval_days')->update(['value' => (string)$intervalDays]);
            $changes[] = "提现间隔: {$intervalDays}天";
        }
        if ($freezeDays > 0) {
            SystemConfig::where('key', 'withdraw_freeze_days')->update(['value' => (string)$freezeDays]);
            $changes[] = "冻结期: {$freezeDays}天";
        }

        $config->status = WithdrawConfig::STATUS_ENABLED;
        $config->save();

        $this->operationLog('admin_finance_withdraw_config', '更新提现配置: ' . implode(', ', $changes));

        return $this->success($config->toArray(), '提现配置更新成功');
    }

    /**
     * 银行卡三要素校验（姓名+身份证+卡号）
     */
    public function bankVerify(Request $request)
    {
        $realName = $request->param('real_name', '');
        $idCard   = $request->param('id_card', '');
        $bankCard = $request->param('bank_card', '');

        if (empty($realName) || empty($idCard) || empty($bankCard)) {
            return $this->error('姓名、身份证号、银行卡号不能为空');
        }

        // 身份证格式校验
        if (!preg_match('/^[1-9]\d{5}(19|20)\d{2}(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])\d{3}[\dXx]$/', $idCard)) {
            return $this->error('身份证号格式不正确');
        }

        // 银行卡号格式校验（Luhn算法）
        if (!$this->luhnCheck($bankCard)) {
            return $this->error('银行卡号格式不正确');
        }

        // 调用三方银行卡校验接口（TODO: 接入实际三要素验证服务）
        $verifyResult = $this->callBankVerifyApi($realName, $idCard, $bankCard);

        $this->operationLog('admin_finance_bank_verify', "银行卡三要素校验: {$realName}");

        return $this->success([
            'real_name'  => mask_sensitive($realName, 'name'),
            'id_card'    => mask_sensitive($idCard, 'id_card'),
            'bank_card'  => mask_sensitive($bankCard, 'bank_card'),
            'result'     => $verifyResult,
        ]);
    }

    /**
     * 平台抽成配置
     */
    public function platformFee(Request $request)
    {
        $feeRate    = $request->param('fee_rate', '');
        $minFee     = $request->param('min_fee', '');
        $maxFee     = $request->param('max_fee', '');
        $freeAmount = $request->param('free_amount', '');

        $changes = [];

        if ($feeRate !== '') {
            $feeRate = max(0, min((float)$feeRate, 100));
            SystemConfig::where('key', 'platform_fee_rate')->update(['value' => (string)$feeRate]);
            $changes[] = "平台抽成比例: {$feeRate}%";
        }

        if ($minFee !== '') {
            SystemConfig::where('key', 'platform_min_fee')->update(['value' => (string)$minFee]);
            $changes[] = "最低抽成: {$minFee}元";
        }

        if ($maxFee !== '') {
            SystemConfig::where('key', 'platform_max_fee')->update(['value' => (string)$maxFee]);
            $changes[] = "最高抽成: {$maxFee}元";
        }

        if ($freeAmount !== '') {
            SystemConfig::where('key', 'platform_free_amount')->update(['value' => (string)$freeAmount]);
            $changes[] = "免抽成额度: {$freeAmount}元";
        }

        $this->operationLog('admin_finance_platform_fee', '更新平台抽成配置: ' . implode(', ', $changes));

        // 获取当前配置
        $config = [
            'fee_rate'    => SystemConfig::getValue('platform_fee_rate', '0'),
            'min_fee'     => SystemConfig::getValue('platform_min_fee', '0'),
            'max_fee'     => SystemConfig::getValue('platform_max_fee', '0'),
            'free_amount' => SystemConfig::getValue('platform_free_amount', '0'),
        ];

        return $this->success($config, '平台抽成配置更新成功');
    }

    /**
     * Luhn 算法校验银行卡号
     * @param string $cardNumber
     * @return bool
     */
    private function luhnCheck(string $cardNumber): bool
    {
        $cardNumber = preg_replace('/\D/', '', $cardNumber);
        if (strlen($cardNumber) < 13 || strlen($cardNumber) > 19) {
            return false;
        }

        $sum = 0;
        $len = strlen($cardNumber);
        for ($i = 0; $i < $len; $i++) {
            $digit = (int)$cardNumber[$len - 1 - $i];
            if ($i % 2 == 1) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
        }

        return $sum % 10 === 0;
    }

    /**
     * 调用银行卡三要素验证API
     * @param string $realName
     * @param string $idCard
     * @param string $bankCard
     * @return array
     */
    private function callBankVerifyApi(string $realName, string $idCard, string $bankCard): array
    {
        // TODO: 接入实际三方验证服务（如银联、阿里云等）
        // 这里返回模拟结果
        Log::info("银行卡三要素验证 - 姓名: {$realName}, 身份证: {$idCard}, 卡号: {$bankCard}");

        return [
            'status' => 'pending',
            'message'=> '验证请求已提交，等待结果返回',
        ];
    }
}