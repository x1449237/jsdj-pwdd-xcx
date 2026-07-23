<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseController;
use app\model\ProfitShareRule;
use app\model\ProfitShareRecord;
use app\model\ProfitShareRefund;
use app\model\TaxConfig;
use app\model\TaxRecord;
use app\model\MerchantAccount;
use app\model\WithdrawBatch;
use app\model\Withdraw;
use app\service\ProfitShareService;
use think\Request;
use think\facade\Db;
use think\facade\Log;

/**
 * 分账与税务管理控制器
 */
class ProfitShare extends BaseController
{
    protected $profitShareService;

    public function __construct()
    {
        $this->profitShareService = new ProfitShareService();
    }

    // ===================== 分账规则 =====================

    /**
     * 分账规则列表
     */
    public function ruleList(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $type = $request->paramInt('type', 0);
        $status = $request->param('status', '');
        $keyword = $request->param('keyword', '');

        $query = ProfitShareRule::order('id', 'desc');

        if ($type > 0) {
            $query->where('type', $type);
        }
        if ($status !== '') {
            $query->where('status', (int)$status);
        }
        if (!empty($keyword)) {
            $query->where('name', 'like', "%{$keyword}%");
        }

        $total = $query->count();
        $list = $query->page($page, $limit)->select()->toArray();

        $this->operationLog('admin_profit_share_rule_list', '查看分账规则列表');

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 分账规则详情
     */
    public function ruleDetail(Request $request)
    {
        $id = $request->paramInt('id', 0);
        if ($id <= 0) {
            return $this->error('规则ID无效');
        }

        $rule = ProfitShareRule::find($id);
        if (!$rule) {
            return $this->error('规则不存在', 404);
        }

        return $this->success($rule->toArray());
    }

    /**
     * 创建分账规则
     */
    public function ruleCreate(Request $request)
    {
        $name = $request->param('name', '');
        $type = $request->paramInt('type', 1);
        $serviceTypeId = $request->paramInt('service_type_id', 0);
        $clubId = $request->paramInt('club_id', 0);
        $playerRatio = $request->param('player_ratio', '0');
        $clubRatio = $request->param('club_ratio', '0');
        $distributorRatio = $request->param('distributor_ratio', '0');
        $platformRatio = $request->param('platform_ratio', '0');
        $isDefault = $request->paramInt('is_default', 0);
        $status = $request->paramInt('status', 1);

        if (empty($name)) {
            return $this->error('规则名称不能为空');
        }

        $totalRatio = (float)$playerRatio + (float)$clubRatio + (float)$distributorRatio + (float)$platformRatio;
        if (abs($totalRatio - 100) > 0.01) {
            return $this->error('四个角色比例之和必须等于100%，当前为: ' . $totalRatio . '%');
        }

        Db::startTrans();
        try {
            if ($isDefault) {
                ProfitShareRule::where('is_default', 1)->update(['is_default' => 0]);
            }

            $rule = new ProfitShareRule();
            $rule->name = $name;
            $rule->type = $type;
            $rule->service_type_id = $serviceTypeId;
            $rule->club_id = $clubId;
            $rule->player_ratio = $playerRatio;
            $rule->club_ratio = $clubRatio;
            $rule->distributor_ratio = $distributorRatio;
            $rule->platform_ratio = $platformRatio;
            $rule->is_default = $isDefault;
            $rule->status = $status;
            $rule->save();

            Db::commit();
            $this->operationLog('admin_profit_share_rule_create', "创建分账规则: {$name}");
            return $this->success($rule->toArray(), '创建成功');
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error('创建失败: ' . $e->getMessage());
        }
    }

    /**
     * 更新分账规则
     */
    public function ruleUpdate(Request $request)
    {
        $id = $request->paramInt('id', 0);
        $name = $request->param('name', '');
        $type = $request->paramInt('type', 0);
        $serviceTypeId = $request->paramInt('service_type_id', 0);
        $clubId = $request->paramInt('club_id', 0);
        $playerRatio = $request->param('player_ratio', '');
        $clubRatio = $request->param('club_ratio', '');
        $distributorRatio = $request->param('distributor_ratio', '');
        $platformRatio = $request->param('platform_ratio', '');
        $isDefault = $request->param('is_default', '');
        $status = $request->param('status', '');

        if ($id <= 0) {
            return $this->error('规则ID无效');
        }

        $rule = ProfitShareRule::find($id);
        if (!$rule) {
            return $this->error('规则不存在', 404);
        }

        $changes = [];

        Db::startTrans();
        try {
            if ($name !== '') {
                $rule->name = $name;
                $changes[] = "名称: {$name}";
            }
            if ($type > 0) {
                $rule->type = $type;
                $changes[] = "类型: {$type}";
            }
            if ($serviceTypeId !== 0) {
                $rule->service_type_id = $serviceTypeId;
            }
            if ($clubId !== 0) {
                $rule->club_id = $clubId;
            }

            $hasRatioChange = false;
            $currentPlayer = (float)$rule->getData('player_ratio');
            $currentClub = (float)$rule->getData('club_ratio');
            $currentDistributor = (float)$rule->getData('distributor_ratio');
            $currentPlatform = (float)$rule->getData('platform_ratio');

            if ($playerRatio !== '') {
                $currentPlayer = (float)$playerRatio;
                $rule->player_ratio = $playerRatio;
                $hasRatioChange = true;
            }
            if ($clubRatio !== '') {
                $currentClub = (float)$clubRatio;
                $rule->club_ratio = $clubRatio;
                $hasRatioChange = true;
            }
            if ($distributorRatio !== '') {
                $currentDistributor = (float)$distributorRatio;
                $rule->distributor_ratio = $distributorRatio;
                $hasRatioChange = true;
            }
            if ($platformRatio !== '') {
                $currentPlatform = (float)$platformRatio;
                $rule->platform_ratio = $platformRatio;
                $hasRatioChange = true;
            }

            if ($hasRatioChange) {
                $totalRatio = $currentPlayer + $currentClub + $currentDistributor + $currentPlatform;
                if (abs($totalRatio - 100) > 0.01) {
                    Db::rollback();
                    return $this->error('四个角色比例之和必须等于100%，当前为: ' . $totalRatio . '%');
                }
                $changes[] = "分账比例更新";
            }

            if ($isDefault !== '') {
                $isDefaultVal = (int)$isDefault;
                if ($isDefaultVal) {
                    ProfitShareRule::where('is_default', 1)->update(['is_default' => 0]);
                }
                $rule->is_default = $isDefaultVal;
                $changes[] = $isDefaultVal ? '设为默认' : '取消默认';
            }

            if ($status !== '') {
                $rule->status = (int)$status;
                $changes[] = ((int)$status == 1 ? '启用' : '禁用');
            }

            $rule->save();
            Db::commit();

            $this->operationLog('admin_profit_share_rule_update', '更新分账规则: ' . implode(', ', $changes));
            return $this->success($rule->toArray(), '更新成功');
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error('更新失败: ' . $e->getMessage());
        }
    }

    /**
     * 删除分账规则
     */
    public function ruleDelete(Request $request)
    {
        $id = $request->paramInt('id', 0);
        if ($id <= 0) {
            return $this->error('规则ID无效');
        }

        $rule = ProfitShareRule::find($id);
        if (!$rule) {
            return $this->error('规则不存在', 404);
        }

        if ($rule->getData('is_default')) {
            return $this->error('默认规则不能删除');
        }

        $rule->delete();

        $this->operationLog('admin_profit_share_rule_delete', "删除分账规则: {$rule->name}");
        return $this->success(null, '删除成功');
    }

    /**
     * 切换分账规则状态
     */
    public function ruleToggle(Request $request)
    {
        $id = $request->paramInt('id', 0);
        if ($id <= 0) {
            return $this->error('规则ID无效');
        }

        $rule = ProfitShareRule::find($id);
        if (!$rule) {
            return $this->error('规则不存在', 404);
        }

        $newStatus = $rule->getData('status') == 1 ? 0 : 1;
        $rule->status = $newStatus;
        $rule->save();

        $statusText = $newStatus == 1 ? '启用' : '禁用';
        $this->operationLog('admin_profit_share_rule_toggle', "{$statusText}分账规则: {$rule->name}");

        return $this->success(['status' => $newStatus], '操作成功');
    }

    // ===================== 分账记录 =====================

    /**
     * 分账记录列表
     */
    public function recordList(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $orderNo = $request->param('order_no', '');
        $userId = $request->paramInt('user_id', 0);
        $role = $request->paramInt('role', 0);
        $status = $request->param('status', '');
        $startDate = $request->param('start_date', '');
        $endDate = $request->param('end_date', '');

        $query = ProfitShareRecord::with(['user', 'order'])->order('id', 'desc');

        if (!empty($orderNo)) {
            $query->where('order_no', 'like', "%{$orderNo}%");
        }
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        if ($role > 0) {
            $query->where('role', $role);
        }
        if ($status !== '') {
            $query->where('status', (int)$status);
        }
        if (!empty($startDate)) {
            $query->where('create_time', '>=', $startDate . ' 00:00:00');
        }
        if (!empty($endDate)) {
            $query->where('create_time', '<=', $endDate . ' 23:59:59');
        }

        $total = $query->count();
        $list = $query->page($page, $limit)->select()->toArray();

        $this->operationLog('admin_profit_share_record_list', '查看分账记录列表');

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 分账记录详情
     */
    public function recordDetail(Request $request)
    {
        $id = $request->paramInt('id', 0);
        if ($id <= 0) {
            return $this->error('记录ID无效');
        }

        $record = ProfitShareRecord::with(['user', 'order'])->find($id);
        if (!$record) {
            return $this->error('记录不存在', 404);
        }

        return $this->success($record->toArray());
    }

    /**
     * 手动结算分账
     */
    public function recordSettle(Request $request)
    {
        $id = $request->paramInt('id', 0);
        if ($id <= 0) {
            return $this->error('记录ID无效');
        }

        try {
            $result = $this->profitShareService->settleShare($id);
            $this->operationLog('admin_profit_share_record_settle', "手动结算分账记录，ID:{$id}");
            return $this->success(null, '结算成功');
        } catch (\Exception $e) {
            return $this->error('结算失败: ' . $e->getMessage());
        }
    }

    /**
     * 批量结算分账
     */
    public function recordBatchSettle(Request $request)
    {
        $ids = $request->param('ids', '');
        if (empty($ids)) {
            return $this->error('请选择要结算的记录');
        }

        $idArray = explode(',', $ids);
        $batchNo = $this->profitShareService->generateBatchNo('SETTLE');

        $result = $this->profitShareService->batchSettle($idArray, $batchNo);

        $this->operationLog('admin_profit_share_record_batch_settle', "批量结算分账，成功:{$result['success_count']}，失败:{$result['fail_count']}");

        return $this->success($result, '批量结算完成');
    }

    // ===================== 退款反向分账 =====================

    /**
     * 退款分账记录列表
     */
    public function refundList(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $orderId = $request->paramInt('order_id', 0);
        $refundId = $request->paramInt('refund_id', 0);
        $userId = $request->paramInt('user_id', 0);
        $status = $request->param('status', '');
        $startDate = $request->param('start_date', '');
        $endDate = $request->param('end_date', '');

        $query = ProfitShareRefund::with(['user', 'order'])->order('id', 'desc');

        if ($orderId > 0) {
            $query->where('order_id', $orderId);
        }
        if ($refundId > 0) {
            $query->where('refund_id', $refundId);
        }
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        if ($status !== '') {
            $query->where('status', (int)$status);
        }
        if (!empty($startDate)) {
            $query->where('create_time', '>=', $startDate . ' 00:00:00');
        }
        if (!empty($endDate)) {
            $query->where('create_time', '<=', $endDate . ' 23:59:59');
        }

        $total = $query->count();
        $list = $query->page($page, $limit)->select()->toArray();

        $this->operationLog('admin_profit_share_refund_list', '查看退款分账记录列表');

        return $this->page($list, $total, $page, $limit);
    }

    // ===================== 个税配置 =====================

    /**
     * 个税配置列表
     */
    public function taxConfigList(Request $request)
    {
        $list = TaxConfig::order('role', 'asc')->select()->toArray();
        $this->operationLog('admin_tax_config_list', '查看个税配置列表');
        return $this->success($list);
    }

    /**
     * 更新个税配置
     */
    public function taxConfigUpdate(Request $request)
    {
        $role = $request->paramInt('role', 0);
        $taxRate = $request->param('tax_rate', '');
        $threshold = $request->param('threshold', '');
        $quickDeduction = $request->param('quick_deduction', '');
        $status = $request->param('status', '');

        if ($role <= 0) {
            return $this->error('角色无效');
        }

        $config = TaxConfig::where('role', $role)->find();
        if (!$config) {
            $config = new TaxConfig();
            $config->role = $role;
        }

        $changes = [];

        if ($taxRate !== '') {
            $config->tax_rate = $taxRate;
            $changes[] = "税率: {$taxRate}%";
        }
        if ($threshold !== '') {
            $config->threshold = $threshold;
            $changes[] = "起征点: {$threshold}元";
        }
        if ($quickDeduction !== '') {
            $config->quick_deduction = $quickDeduction;
            $changes[] = "速算扣除数: {$quickDeduction}元";
        }
        if ($status !== '') {
            $config->status = (int)$status;
            $changes[] = (int)$status == 1 ? '启用' : '禁用';
        }

        $config->save();

        $roleNames = [1 => '打手', 2 => '俱乐部', 3 => '分销商'];
        $roleName = $roleNames[$role] ?? '未知';
        $this->operationLog('admin_tax_config_update', "更新{$roleName}个税配置: " . implode(', ', $changes));

        return $this->success($config->toArray(), '更新成功');
    }

    // ===================== 个税代扣记录 =====================

    /**
     * 个税代扣记录列表
     */
    public function taxRecordList(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $userId = $request->paramInt('user_id', 0);
        $role = $request->paramInt('role', 0);
        $month = $request->param('month', '');
        $status = $request->param('status', '');

        $query = TaxRecord::with(['user'])->order('id', 'desc');

        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        if ($role > 0) {
            $query->where('role', $role);
        }
        if (!empty($month)) {
            $query->where('month', $month);
        }
        if ($status !== '') {
            $query->where('status', (int)$status);
        }

        $total = $query->count();
        $list = $query->page($page, $limit)->select()->toArray();

        $this->operationLog('admin_tax_record_list', '查看个税代扣记录列表');

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 更新完税状态
     */
    public function taxRecordComplete(Request $request)
    {
        $id = $request->paramInt('id', 0);
        $certificateNo = $request->param('certificate_no', '');

        if ($id <= 0) {
            return $this->error('记录ID无效');
        }

        $record = TaxRecord::find($id);
        if (!$record) {
            return $this->error('记录不存在', 404);
        }

        $record->status = TaxRecord::STATUS_COMPLETED;
        if (!empty($certificateNo)) {
            $record->certificate_no = $certificateNo;
        }
        $record->save();

        $this->operationLog('admin_tax_record_complete', "标记个税完税，ID:{$id}");

        return $this->success(null, '操作成功');
    }

    // ===================== 子商户账户管理 =====================

    /**
     * 子商户账户列表
     */
    public function merchantAccountList(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $userId = $request->paramInt('user_id', 0);
        $role = $request->paramInt('role', 0);
        $accountType = $request->paramInt('account_type', 0);
        $status = $request->param('status', '');

        $query = MerchantAccount::with(['user'])->order('id', 'desc');

        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        if ($role > 0) {
            $query->where('role', $role);
        }
        if ($accountType > 0) {
            $query->where('account_type', $accountType);
        }
        if ($status !== '') {
            $query->where('status', (int)$status);
        }

        $total = $query->count();
        $list = $query->page($page, $limit)->select()->toArray();

        $this->operationLog('admin_merchant_account_list', '查看子商户账户列表');

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 添加子商户账户
     */
    public function merchantAccountCreate(Request $request)
    {
        $userId = $request->paramInt('user_id', 0);
        $role = $request->paramInt('role', 0);
        $accountType = $request->paramInt('account_type', 0);
        $accountNo = $request->param('account_no', '');
        $accountName = $request->param('account_name', '');
        $bankName = $request->param('bank_name', '');
        $bankBranch = $request->param('bank_branch', '');

        if ($userId <= 0 || $role <= 0 || $accountType <= 0) {
            return $this->error('参数不完整');
        }
        if (empty($accountNo) || empty($accountName)) {
            return $this->error('账户号和账户名称不能为空');
        }

        $account = new MerchantAccount();
        $account->user_id = $userId;
        $account->role = $role;
        $account->account_type = $accountType;
        $account->account_no = $accountNo;
        $account->account_name = $accountName;
        $account->bank_name = $bankName;
        $account->bank_branch = $bankBranch;
        $account->status = 1;
        $account->save();

        $this->operationLog('admin_merchant_account_create', "添加子商户账户，用户ID:{$userId}");

        return $this->success($account->toArray(), '添加成功');
    }

    /**
     * 验证子商户账户
     */
    public function merchantAccountVerify(Request $request)
    {
        $id = $request->paramInt('id', 0);
        if ($id <= 0) {
            return $this->error('账户ID无效');
        }

        $account = MerchantAccount::find($id);
        if (!$account) {
            return $this->error('账户不存在', 404);
        }

        $account->is_verified = 1;
        $account->verify_time = date('Y-m-d H:i:s');
        $account->save();

        $this->operationLog('admin_merchant_account_verify', "验证子商户账户，ID:{$id}");

        return $this->success(null, '验证成功');
    }

    // ===================== 提现批次 =====================

    /**
     * 提现批次列表
     */
    public function withdrawBatchList(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $batchNo = $request->param('batch_no', '');
        $channel = $request->paramInt('channel', 0);
        $status = $request->param('status', '');
        $startDate = $request->param('start_date', '');
        $endDate = $request->param('end_date', '');

        $query = WithdrawBatch::order('id', 'desc');

        if (!empty($batchNo)) {
            $query->where('batch_no', 'like', "%{$batchNo}%");
        }
        if ($channel > 0) {
            $query->where('channel', $channel);
        }
        if ($status !== '') {
            $query->where('status', (int)$status);
        }
        if (!empty($startDate)) {
            $query->where('create_time', '>=', $startDate . ' 00:00:00');
        }
        if (!empty($endDate)) {
            $query->where('create_time', '<=', $endDate . ' 23:59:59');
        }

        $total = $query->count();
        $list = $query->page($page, $limit)->select()->toArray();

        $this->operationLog('admin_withdraw_batch_list', '查看提现批次列表');

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 提现批次详情
     */
    public function withdrawBatchDetail(Request $request)
    {
        $id = $request->paramInt('id', 0);
        if ($id <= 0) {
            return $this->error('批次ID无效');
        }

        $batch = WithdrawBatch::find($id);
        if (!$batch) {
            return $this->error('批次不存在', 404);
        }

        return $this->success($batch->toArray());
    }

    /**
     * 创建提现批次
     */
    public function withdrawBatchCreate(Request $request)
    {
        $channel = $request->paramInt('channel', 1);
        $remark = $request->param('remark', '');

        $pendingWithdraws = Withdraw::where('status', Withdraw::STATUS_PENDING)
            ->where('method', $channel)
            ->select();

        if ($pendingWithdraws->isEmpty()) {
            return $this->error('没有待处理的提现申请');
        }

        $totalAmount = 0;
        $totalCount = 0;
        $withdrawIds = [];

        foreach ($pendingWithdraws as $withdraw) {
            $totalAmount += (int)$withdraw->getData('amount');
            $totalCount++;
            $withdrawIds[] = $withdraw->id;
        }

        Db::startTrans();
        try {
            $batchNo = $this->profitShareService->generateBatchNo('WITHDRAW');
            $adminInfo = $this->adminInfo();

            $batch = new WithdrawBatch();
            $batch->batch_no = $batchNo;
            $batch->total_amount = $totalAmount;
            $batch->total_count = $totalCount;
            $batch->channel = $channel;
            $batch->status = WithdrawBatch::STATUS_PENDING;
            $batch->operator = $this->adminId();
            $batch->operator_name = $adminInfo['nickname'] ?? '';
            $batch->remark = $remark;
            $batch->save();

            foreach ($pendingWithdraws as $withdraw) {
                $withdraw->status = Withdraw::STATUS_PROCESS;
                $withdraw->save();
            }

            Db::commit();

            $this->operationLog('admin_withdraw_batch_create', "创建提现批次:{$batchNo}，共{$totalCount}笔，金额:{$totalAmount}分");

            return $this->success($batch->toArray(), '批次创建成功');
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error('创建失败: ' . $e->getMessage());
        }
    }

    /**
     * 处理提现批次
     */
    public function withdrawBatchProcess(Request $request)
    {
        $id = $request->paramInt('id', 0);
        if ($id <= 0) {
            return $this->error('批次ID无效');
        }

        $batch = WithdrawBatch::find($id);
        if (!$batch) {
            return $this->error('批次不存在', 404);
        }
        if ($batch->getData('status') != WithdrawBatch::STATUS_PENDING) {
            return $this->error('批次状态不正确');
        }

        $batch->status = WithdrawBatch::STATUS_PROCESSING;
        $batch->process_time = date('Y-m-d H:i:s');
        $batch->save();

        $this->operationLog('admin_withdraw_batch_process', "开始处理提现批次:{$batch->batch_no}");

        return $this->success(null, '处理中');
    }

    /**
     * 完成提现批次
     */
    public function withdrawBatchComplete(Request $request)
    {
        $id = $request->paramInt('id', 0);
        $successCount = $request->paramInt('success_count', 0);
        $failCount = $request->paramInt('fail_count', 0);
        $successAmount = $request->paramInt('success_amount', 0);
        $failAmount = $request->paramInt('fail_amount', 0);

        if ($id <= 0) {
            return $this->error('批次ID无效');
        }

        $batch = WithdrawBatch::find($id);
        if (!$batch) {
            return $this->error('批次不存在', 404);
        }

        Db::startTrans();
        try {
            $batch->success_count = $successCount;
            $batch->fail_count = $failCount;
            $batch->success_amount = $successAmount;
            $batch->fail_amount = $failAmount;
            $batch->complete_time = date('Y-m-d H:i:s');

            if ($failCount == 0) {
                $batch->status = WithdrawBatch::STATUS_COMPLETED;
            } elseif ($successCount == 0) {
                $batch->status = WithdrawBatch::STATUS_ALL_FAIL;
            } else {
                $batch->status = WithdrawBatch::STATUS_PARTIAL_FAIL;
            }

            $batch->save();

            Db::commit();

            $this->operationLog('admin_withdraw_batch_complete', "完成提现批次:{$batch->batch_no}，成功:{$successCount}，失败:{$failCount}");

            return $this->success($batch->toArray(), '批次已完成');
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error('操作失败: ' . $e->getMessage());
        }
    }

    /**
     * 分账统计数据
     */
    public function statistics(Request $request)
    {
        $todayStart = date('Y-m-d 00:00:00');
        $todayEnd = date('Y-m-d 23:59:59');
        $monthStart = date('Y-m-01 00:00:00');

        $todayShareAmount = ProfitShareRecord::whereBetween('create_time', [$todayStart, $todayEnd])
            ->sum('amount');
        $monthShareAmount = ProfitShareRecord::whereBetween('create_time', [$monthStart, $todayEnd])
            ->sum('amount');
        $pendingSettleAmount = ProfitShareRecord::where('status', ProfitShareRecord::STATUS_PENDING)
            ->sum('amount');
        $totalTaxAmount = TaxRecord::sum('tax_amount');

        $data = [
            'today_share_amount' => fen_to_yuan((int)$todayShareAmount),
            'month_share_amount' => fen_to_yuan((int)$monthShareAmount),
            'pending_settle_amount' => fen_to_yuan((int)$pendingSettleAmount),
            'total_tax_amount' => fen_to_yuan((int)$totalTaxAmount),
        ];

        return $this->success($data);
    }
}
