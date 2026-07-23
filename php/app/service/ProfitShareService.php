<?php
declare(strict_types=1);

namespace app\service;

use app\model\ProfitShareRule;
use app\model\ProfitShareRecord;
use app\model\ProfitShareRefund;
use app\model\TaxConfig;
use app\model\TaxRecord;
use app\model\Order;
use app\model\User;
use think\facade\Db;
use think\facade\Log;

/**
 * 分账服务层
 * 处理分账规则、分账计算、退款回滚、个税计算等核心逻辑
 */
class ProfitShareService
{
    /**
     * 获取分账规则
     * @param int $serviceTypeId
     * @param int $clubId
     * @return array
     */
    public function getShareRule(int $serviceTypeId = 0, int $clubId = 0): array
    {
        $rule = null;

        if ($clubId > 0) {
            $rule = ProfitShareRule::where('type', ProfitShareRule::TYPE_CLUB)
                ->where('club_id', $clubId)
                ->where('status', ProfitShareRule::STATUS_ENABLED)
                ->find();
        }

        if (!$rule && $serviceTypeId > 0) {
            $rule = ProfitShareRule::where('type', ProfitShareRule::TYPE_SERVICE)
                ->where('service_type_id', $serviceTypeId)
                ->where('status', ProfitShareRule::STATUS_ENABLED)
                ->find();
        }

        if (!$rule) {
            $rule = ProfitShareRule::default()->enabled()->find();
        }

        if (!$rule) {
            return [
                'player_ratio' => 60.00,
                'club_ratio' => 10.00,
                'distributor_ratio' => 5.00,
                'platform_ratio' => 25.00,
            ];
        }

        return $rule->toArray();
    }

    /**
     * 执行订单分账
     * @param Order $order
     * @param array $extra 额外参数 [club_id, distributor_id]
     * @return bool
     * @throws \Exception
     */
    public function doProfitShare(Order $order, array $extra = []): bool
    {
        $orderAmount = (int)$order->getData('paid_amount');
        if ($orderAmount <= 0) {
            return false;
        }

        $serviceTypeId = (int)$order->getData('service_type_id');
        $clubId = $extra['club_id'] ?? 0;
        $distributorId = $extra['distributor_id'] ?? 0;
        $playerId = (int)$order->getData('player_id');

        $rule = $this->getShareRule($serviceTypeId, $clubId);

        Db::startTrans();
        try {
            $records = [];

            $playerAmount = (int)bcmul((string)$orderAmount, (string)($rule['player_ratio'] / 100), 0);
            $clubAmount = (int)bcmul((string)$orderAmount, (string)($rule['club_ratio'] / 100), 0);
            $distributorAmount = (int)bcmul((string)$orderAmount, (string)($rule['distributor_ratio'] / 100), 0);
            $platformAmount = $orderAmount - $playerAmount - $clubAmount - $distributorAmount;

            if ($playerId > 0 && $playerAmount > 0) {
                $records[] = [
                    'order_id' => $order->id,
                    'order_no' => $order->order_sn,
                    'user_id' => $playerId,
                    'role' => ProfitShareRecord::ROLE_PLAYER,
                    'amount' => $playerAmount,
                    'ratio' => $rule['player_ratio'],
                    'status' => ProfitShareRecord::STATUS_PENDING,
                    'create_time' => date('Y-m-d H:i:s'),
                    'update_time' => date('Y-m-d H:i:s'),
                ];
            }

            if ($clubId > 0 && $clubAmount > 0) {
                $records[] = [
                    'order_id' => $order->id,
                    'order_no' => $order->order_sn,
                    'user_id' => $clubId,
                    'role' => ProfitShareRecord::ROLE_CLUB,
                    'amount' => $clubAmount,
                    'ratio' => $rule['club_ratio'],
                    'status' => ProfitShareRecord::STATUS_PENDING,
                    'create_time' => date('Y-m-d H:i:s'),
                    'update_time' => date('Y-m-d H:i:s'),
                ];
            }

            if ($distributorId > 0 && $distributorAmount > 0) {
                $records[] = [
                    'order_id' => $order->id,
                    'order_no' => $order->order_sn,
                    'user_id' => $distributorId,
                    'role' => ProfitShareRecord::ROLE_DISTRIBUTOR,
                    'amount' => $distributorAmount,
                    'ratio' => $rule['distributor_ratio'],
                    'status' => ProfitShareRecord::STATUS_PENDING,
                    'create_time' => date('Y-m-d H:i:s'),
                    'update_time' => date('Y-m-d H:i:s'),
                ];
            }

            if ($platformAmount > 0) {
                $records[] = [
                    'order_id' => $order->id,
                    'order_no' => $order->order_sn,
                    'user_id' => 0,
                    'role' => ProfitShareRecord::ROLE_PLATFORM,
                    'amount' => $platformAmount,
                    'ratio' => $rule['platform_ratio'],
                    'status' => ProfitShareRecord::STATUS_PENDING,
                    'create_time' => date('Y-m-d H:i:s'),
                    'update_time' => date('Y-m-d H:i:s'),
                ];
            }

            if (!empty($records)) {
                Db::name('profit_share_record')->insertAll($records);
            }

            Db::commit();
            Log::info("订单分账成功，订单ID:{$order->id}，金额:{$orderAmount}分");
            return true;
        } catch (\Exception $e) {
            Db::rollback();
            Log::error("订单分账失败，订单ID:{$order->id}，错误:{$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * 结算分账（从待结算转入可提现余额）
     * @param int $recordId
     * @return bool
     * @throws \Exception
     */
    public function settleShare(int $recordId): bool
    {
        $record = ProfitShareRecord::find($recordId);
        if (!$record) {
            throw new \Exception('分账记录不存在');
        }
        if ($record->getData('status') != ProfitShareRecord::STATUS_PENDING) {
            throw new \Exception('分账记录状态不正确');
        }

        Db::startTrans();
        try {
            $userId = (int)$record->getData('user_id');
            $amount = (int)$record->getData('amount');

            if ($userId > 0 && $amount > 0) {
                $user = User::find($userId);
                if ($user) {
                    $user->balance = bc_add($user->getData('balance'), $amount);
                    $user->total_income = bc_add($user->getData('total_income'), $amount);
                    $user->save();
                }
            }

            $record->status = ProfitShareRecord::STATUS_SETTLED;
            $record->share_time = date('Y-m-d H:i:s');
            $record->save();

            Db::commit();
            return true;
        } catch (\Exception $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 退款反向分账
     * @param int $orderId
     * @param int $refundId
     * @param string $refundNo
     * @param int $refundAmount 退款金额（分）
     * @param int $operator 操作人ID
     * @return bool
     * @throws \Exception
     */
    public function refundProfitShare(int $orderId, int $refundId, string $refundNo, int $refundAmount, int $operator = 0): bool
    {
        $records = ProfitShareRecord::where('order_id', $orderId)
            ->whereIn('status', [ProfitShareRecord::STATUS_PENDING, ProfitShareRecord::STATUS_SETTLED])
            ->select();

        if ($records->isEmpty()) {
            return false;
        }

        Db::startTrans();
        try {
            $order = Order::find($orderId);
            $orderAmount = $order ? (int)$order->getData('paid_amount') : 0;

            if ($orderAmount <= 0) {
                throw new \Exception('订单金额异常');
            }

            $refundRatio = $refundAmount / $orderAmount;

            foreach ($records as $record) {
                $originAmount = (int)$record->getData('amount');
                $rollbackAmount = (int)bcmul((string)$originAmount, (string)$refundRatio, 0);

                if ($rollbackAmount <= 0) {
                    continue;
                }

                $refundRecord = new ProfitShareRefund();
                $refundRecord->order_id = $orderId;
                $refundRecord->refund_id = $refundId;
                $refundRecord->refund_no = $refundNo;
                $refundRecord->user_id = $record->getData('user_id');
                $refundRecord->role = $record->getData('role');
                $refundRecord->refund_amount = $rollbackAmount;
                $refundRecord->origin_amount = $originAmount;
                $refundRecord->status = ProfitShareRefund::STATUS_PROCESSING;
                $refundRecord->operator = $operator;
                $refundRecord->save();

                $userId = (int)$record->getData('user_id');
                if ($userId > 0) {
                    $user = User::find($userId);
                    if ($user) {
                        $currentBalance = (int)$user->getData('balance');
                        $newBalance = max(0, $currentBalance - $rollbackAmount);
                        $user->balance = $newBalance;
                        $user->save();
                    }
                }

                $record->status = ProfitShareRecord::STATUS_REFUNDED;
                $record->remark = '退款回滚';
                $record->save();

                $refundRecord->status = ProfitShareRefund::STATUS_COMPLETED;
                $refundRecord->save();
            }

            Db::commit();
            Log::info("退款反向分账成功，订单ID:{$orderId}，退款金额:{$refundAmount}分");
            return true;
        } catch (\Exception $e) {
            Db::rollback();
            Log::error("退款反向分账失败，订单ID:{$orderId}，错误:{$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * 计算个税
     * @param int $amount 计税金额（分）
     * @param int $role 角色
     * @return array [tax_amount, tax_rate, threshold]
     */
    public function calculateTax(int $amount, int $role): array
    {
        $config = TaxConfig::where('role', $role)
            ->where('status', TaxConfig::STATUS_ENABLED)
            ->find();

        if (!$config) {
            return [
                'tax_amount' => 0,
                'tax_rate' => '0.00',
                'threshold' => 0,
            ];
        }

        $threshold = (int)$config->getData('threshold');
        $taxRate = (float)$config->getData('tax_rate');
        $quickDeduction = (int)$config->getData('quick_deduction');

        $taxableAmount = max(0, $amount - $threshold);

        if ($taxableAmount <= 0) {
            return [
                'tax_amount' => 0,
                'tax_rate' => $config->tax_rate,
                'threshold' => $threshold,
            ];
        }

        $taxAmount = (int)bcmul((string)$taxableAmount, (string)($taxRate / 100), 0);
        $taxAmount = max(0, $taxAmount - $quickDeduction);

        return [
            'tax_amount' => $taxAmount,
            'tax_rate' => $config->tax_rate,
            'threshold' => $threshold,
        ];
    }

    /**
     * 创建个税代扣记录
     * @param int $userId
     * @param int $role
     * @param int $amount
     * @param int $withdrawId
     * @return TaxRecord|null
     */
    public function createTaxRecord(int $userId, int $role, int $amount, int $withdrawId = 0): ?TaxRecord
    {
        $taxResult = $this->calculateTax($amount, $role);

        if ($taxResult['tax_amount'] <= 0) {
            return null;
        }

        $month = date('Y-m');

        $record = new TaxRecord();
        $record->user_id = $userId;
        $record->role = $role;
        $record->amount = $amount;
        $record->tax_amount = $taxResult['tax_amount'];
        $record->tax_rate = $taxResult['tax_rate'];
        $record->threshold = $taxResult['threshold'];
        $record->month = $month;
        $record->withdraw_id = $withdrawId;
        $record->status = TaxRecord::STATUS_WITHHOLD;
        $record->create_time = date('Y-m-d H:i:s');
        $record->save();

        return $record;
    }

    /**
     * 批量结算分账
     * @param array $recordIds
     * @param string $batchNo
     * @return array [success_count, fail_count]
     */
    public function batchSettle(array $recordIds, string $batchNo = ''): array
    {
        $successCount = 0;
        $failCount = 0;

        foreach ($recordIds as $recordId) {
            try {
                if ($this->settleShare((int)$recordId)) {
                    if ($batchNo) {
                        ProfitShareRecord::where('id', $recordId)
                            ->update(['settle_batch_no' => $batchNo]);
                    }
                    $successCount++;
                } else {
                    $failCount++;
                }
            } catch (\Exception $e) {
                $failCount++;
                Log::error("批量结算分账失败，记录ID:{$recordId}，错误:{$e->getMessage()}");
            }
        }

        return [
            'success_count' => $successCount,
            'fail_count' => $failCount,
        ];
    }

    /**
     * 获取用户分账统计
     * @param int $userId
     * @param int $role
     * @return array
     */
    public function getUserShareStats(int $userId, int $role): array
    {
        $totalAmount = ProfitShareRecord::where('user_id', $userId)
            ->where('role', $role)
            ->where('status', ProfitShareRecord::STATUS_SETTLED)
            ->sum('amount');

        $pendingAmount = ProfitShareRecord::where('user_id', $userId)
            ->where('role', $role)
            ->where('status', ProfitShareRecord::STATUS_PENDING)
            ->sum('amount');

        $monthAmount = ProfitShareRecord::where('user_id', $userId)
            ->where('role', $role)
            ->where('status', ProfitShareRecord::STATUS_SETTLED)
            ->whereTime('create_time', 'month')
            ->sum('amount');

        return [
            'total_amount' => (int)$totalAmount,
            'pending_amount' => (int)$pendingAmount,
            'month_amount' => (int)$monthAmount,
        ];
    }

    /**
     * 生成批次号
     * @param string $prefix
     * @return string
     */
    public function generateBatchNo(string $prefix = 'WITHDRAW'): string
    {
        return $prefix . date('YmdHis') . mt_rand(1000, 9999);
    }
}
