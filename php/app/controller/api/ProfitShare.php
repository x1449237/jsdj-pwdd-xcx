<?php
declare(strict_types=1);

namespace app\controller\api;

use app\controller\BaseController;
use app\model\ProfitShareRecord;
use app\model\TaxRecord;
use app\model\MerchantAccount;
use app\service\ProfitShareService;
use think\Request;

/**
 * 用户端分账与税务控制器
 */
class ProfitShare extends BaseController
{
    protected $profitShareService;
    protected $userId;

    public function __construct()
    {
        $this->profitShareService = new ProfitShareService();
        $this->userId = request()->userId() ?? 0;
    }

    /**
     * 我的分账统计
     */
    public function myStats(Request $request)
    {
        $role = $request->paramInt('role', 0);
        $userId = $this->userId;

        if ($userId <= 0) {
            return $this->error('请先登录', 401);
        }

        $stats = $this->profitShareService->getUserShareStats($userId, $role);

        return $this->success([
            'total_amount' => fen_to_yuan($stats['total_amount']),
            'pending_amount' => fen_to_yuan($stats['pending_amount']),
            'month_amount' => fen_to_yuan($stats['month_amount']),
        ]);
    }

    /**
     * 分账明细列表
     */
    public function shareList(Request $request)
    {
        $page = $request->paramInt('page', 1);
        $limit = $request->paramInt('limit', 20);
        $role = $request->paramInt('role', 0);
        $status = $request->param('status', '');
        $month = $request->param('month', '');

        $userId = $this->userId;
        if ($userId <= 0) {
            return $this->error('请先登录', 401);
        }

        $query = ProfitShareRecord::where('user_id', $userId)->order('id', 'desc');

        if ($role > 0) {
            $query->where('role', $role);
        }
        if ($status !== '') {
            $query->where('status', (int)$status);
        }
        if (!empty($month)) {
            $query->whereTime('create_time', 'month', $month);
        }

        $total = $query->count();
        $list = $query->page($page, $limit)->select()->toArray();

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 分账记录详情
     */
    public function shareDetail(Request $request)
    {
        $id = $request->paramInt('id', 0);
        $userId = $this->userId;

        if ($userId <= 0) {
            return $this->error('请先登录', 401);
        }
        if ($id <= 0) {
            return $this->error('记录ID无效');
        }

        $record = ProfitShareRecord::where('id', $id)
            ->where('user_id', $userId)
            ->with(['order'])
            ->find();

        if (!$record) {
            return $this->error('记录不存在', 404);
        }

        return $this->success($record->toArray());
    }

    /**
     * 个税代扣记录列表
     */
    public function taxList(Request $request)
    {
        $page = $request->paramInt('page', 1);
        $limit = $request->paramInt('limit', 20);
        $role = $request->paramInt('role', 0);
        $month = $request->param('month', '');

        $userId = $this->userId;
        if ($userId <= 0) {
            return $this->error('请先登录', 401);
        }

        $query = TaxRecord::where('user_id', $userId)->order('id', 'desc');

        if ($role > 0) {
            $query->where('role', $role);
        }
        if (!empty($month)) {
            $query->where('month', $month);
        }

        $total = $query->count();
        $list = $query->page($page, $limit)->select()->toArray();

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 个税统计
     */
    public function taxStats(Request $request)
    {
        $role = $request->paramInt('role', 0);
        $month = $request->param('month', '');
        $year = $request->param('year', '');

        $userId = $this->userId;
        if ($userId <= 0) {
            return $this->error('请先登录', 401);
        }

        $query = TaxRecord::where('user_id', $userId);
        if ($role > 0) {
            $query->where('role', $role);
        }
        $totalTax = (clone $query)->sum('tax_amount');
        $totalAmount = (clone $query)->sum('amount');

        $monthTax = 0;
        $monthAmount = 0;
        if (!empty($month)) {
            $monthQuery = clone $query;
            $monthQuery->where('month', $month);
            $monthTax = (int)$monthQuery->sum('tax_amount');
            $monthAmount = (int)$monthQuery->sum('amount');
        }

        $yearTax = 0;
        $yearAmount = 0;
        if (!empty($year)) {
            $yearQuery = clone $query;
            $yearQuery->where('month', 'like', "{$year}%");
            $yearTax = (int)$yearQuery->sum('tax_amount');
            $yearAmount = (int)$yearQuery->sum('amount');
        }

        return $this->success([
            'total_tax' => fen_to_yuan((int)$totalTax),
            'total_amount' => fen_to_yuan((int)$totalAmount),
            'month_tax' => fen_to_yuan($monthTax),
            'month_amount' => fen_to_yuan($monthAmount),
            'year_tax' => fen_to_yuan($yearTax),
            'year_amount' => fen_to_yuan($yearAmount),
        ]);
    }

    /**
     * 我的收款账户列表
     */
    public function accountList(Request $request)
    {
        $role = $request->paramInt('role', 0);
        $accountType = $request->paramInt('account_type', 0);

        $userId = $this->userId;
        if ($userId <= 0) {
            return $this->error('请先登录', 401);
        }

        $query = MerchantAccount::where('user_id', $userId)
            ->where('status', 1)
            ->order('is_verified', 'desc')
            ->order('id', 'desc');

        if ($role > 0) {
            $query->where('role', $role);
        }
        if ($accountType > 0) {
            $query->where('account_type', $accountType);
        }

        $list = $query->select()->toArray();

        return $this->success($list);
    }

    /**
     * 添加收款账户
     */
    public function accountCreate(Request $request)
    {
        $role = $request->paramInt('role', 0);
        $accountType = $request->paramInt('account_type', 0);
        $accountNo = $request->param('account_no', '');
        $accountName = $request->param('account_name', '');
        $bankName = $request->param('bank_name', '');
        $bankBranch = $request->param('bank_branch', '');

        $userId = $this->userId;
        if ($userId <= 0) {
            return $this->error('请先登录', 401);
        }
        if ($role <= 0 || $accountType <= 0) {
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

        return $this->success($account->hidden(['account_no'])->toArray(), '添加成功');
    }

    /**
     * 删除收款账户
     */
    public function accountDelete(Request $request)
    {
        $id = $request->paramInt('id', 0);
        $userId = $this->userId;

        if ($userId <= 0) {
            return $this->error('请先登录', 401);
        }
        if ($id <= 0) {
            return $this->error('账户ID无效');
        }

        $account = MerchantAccount::where('id', $id)
            ->where('user_id', $userId)
            ->find();

        if (!$account) {
            return $this->error('账户不存在', 404);
        }

        $account->status = 0;
        $account->save();

        return $this->success(null, '删除成功');
    }

    /**
     * 个税计算器
     */
    public function taxCalculator(Request $request)
    {
        $amount = $request->param('amount', '0');
        $role = $request->paramInt('role', 1);

        $amountFen = yuan_to_fen($amount);
        if ($amountFen <= 0) {
            return $this->error('请输入有效金额');
        }

        $result = $this->profitShareService->calculateTax($amountFen, $role);

        return $this->success([
            'amount' => $amount,
            'tax_amount' => fen_to_yuan($result['tax_amount']),
            'tax_rate' => $result['tax_rate'],
            'threshold' => fen_to_yuan($result['threshold']),
            'actual_amount' => fen_to_yuan($amountFen - $result['tax_amount']),
        ]);
    }
}
