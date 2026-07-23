<?php
declare(strict_types=1);

namespace app\service;

use app\model\ServiceDeposit;
use app\model\ServiceDepositLog;
use app\model\User;
use think\facade\Db;
use think\facade\Log;

class ServiceDepositService
{
    public function getDeposit(int $playerUserId): ?array
    {
        $deposit = ServiceDeposit::byPlayer($playerUserId)->find();
        return $deposit ? $deposit->toArray() : null;
    }

    public function deposit(int $playerUserId, int $amount, int $relatedId = 0, string $description = ''): array
    {
        if ($amount <= 0) {
            throw new \RuntimeException('充值金额必须大于0');
        }

        Db::startTrans();
        try {
            $deposit = ServiceDeposit::byPlayer($playerUserId)->find();
            if (!$deposit) {
                $deposit = ServiceDeposit::create([
                    'player_user_id' => $playerUserId,
                    'amount'         => 0,
                    'status'         => ServiceDeposit::STATUS_ACTIVE,
                    'freeze_amount'  => 0,
                ]);
            }

            $newBalance = $deposit->amount + $amount;
            $deposit->amount = $newBalance;
            if ($deposit->status === ServiceDeposit::STATUS_WITHDRAWN) {
                $deposit->status = ServiceDeposit::STATUS_ACTIVE;
            }
            $deposit->save();

            $log = ServiceDepositLog::create([
                'player_user_id' => $playerUserId,
                'type'           => ServiceDepositLog::TYPE_DEPOSIT,
                'amount'         => $amount,
                'balance'        => $newBalance,
                'related_id'     => $relatedId,
                'description'    => $description ?: '保证金充值',
            ]);

            Db::commit();
            write_action_log('service_deposit_deposit', "保证金充值: 打手ID: {$playerUserId}, 金额: {$amount}分");
            return $deposit->toArray();
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('保证金充值失败: ' . $e->getMessage());
            throw $e;
        }
    }

    public function deductDeposit(int $playerUserId, int $amount, int $relatedId = 0, string $description = ''): array
    {
        if ($amount <= 0) {
            throw new \RuntimeException('扣除金额必须大于0');
        }

        Db::startTrans();
        try {
            $deposit = ServiceDeposit::byPlayer($playerUserId)->find();
            if (!$deposit || $deposit->amount < $amount) {
                throw new \RuntimeException('保证金余额不足');
            }

            $newBalance = $deposit->amount - $amount;
            $deposit->amount = $newBalance;
            $deposit->save();

            $log = ServiceDepositLog::create([
                'player_user_id' => $playerUserId,
                'type'           => ServiceDepositLog::TYPE_DEDUCT,
                'amount'         => -$amount,
                'balance'        => $newBalance,
                'related_id'     => $relatedId,
                'description'    => $description ?: '保证金扣除',
            ]);

            Db::commit();
            write_action_log('service_deposit_deduct', "保证金扣除: 打手ID: {$playerUserId}, 金额: {$amount}分");
            return $deposit->toArray();
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('保证金扣除失败: ' . $e->getMessage());
            throw $e;
        }
    }

    public function refundDeposit(int $playerUserId, int $amount, int $relatedId = 0, string $description = ''): array
    {
        if ($amount <= 0) {
            throw new \RuntimeException('退还金额必须大于0');
        }

        Db::startTrans();
        try {
            $deposit = ServiceDeposit::byPlayer($playerUserId)->find();
            if (!$deposit || $deposit->amount < $amount) {
                throw new \RuntimeException('保证金余额不足');
            }

            $newBalance = $deposit->amount - $amount;
            $deposit->amount = $newBalance;
            $deposit->save();

            $log = ServiceDepositLog::create([
                'player_user_id' => $playerUserId,
                'type'           => ServiceDepositLog::TYPE_REFUND,
                'amount'         => -$amount,
                'balance'        => $newBalance,
                'related_id'     => $relatedId,
                'description'    => $description ?: '保证金退还',
            ]);

            Db::commit();
            write_action_log('service_deposit_refund', "保证金退还: 打手ID: {$playerUserId}, 金额: {$amount}分");
            return $deposit->toArray();
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('保证金退还失败: ' . $e->getMessage());
            throw $e;
        }
    }

    public function freezeDeposit(int $playerUserId, int $amount, int $relatedId = 0, string $description = ''): array
    {
        if ($amount <= 0) {
            throw new \RuntimeException('冻结金额必须大于0');
        }

        Db::startTrans();
        try {
            $deposit = ServiceDeposit::byPlayer($playerUserId)->find();
            if (!$deposit || $deposit->amount - $deposit->freeze_amount < $amount) {
                throw new \RuntimeException('可冻结保证金不足');
            }

            $newFreezeAmount = $deposit->freeze_amount + $amount;
            $deposit->freeze_amount = $newFreezeAmount;
            if ($deposit->status === ServiceDeposit::STATUS_ACTIVE) {
                $deposit->status = ServiceDeposit::STATUS_FROZEN;
            }
            $deposit->save();

            $log = ServiceDepositLog::create([
                'player_user_id' => $playerUserId,
                'type'           => ServiceDepositLog::TYPE_FREEZE,
                'amount'         => -$amount,
                'balance'        => $deposit->amount,
                'related_id'     => $relatedId,
                'description'    => $description ?: '保证金冻结',
            ]);

            Db::commit();
            write_action_log('service_deposit_freeze', "保证金冻结: 打手ID: {$playerUserId}, 金额: {$amount}分");
            return $deposit->toArray();
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('保证金冻结失败: ' . $e->getMessage());
            throw $e;
        }
    }

    public function unfreezeDeposit(int $playerUserId, int $amount, int $relatedId = 0, string $description = ''): array
    {
        if ($amount <= 0) {
            throw new \RuntimeException('解冻金额必须大于0');
        }

        Db::startTrans();
        try {
            $deposit = ServiceDeposit::byPlayer($playerUserId)->find();
            if (!$deposit || $deposit->freeze_amount < $amount) {
                throw new \RuntimeException('冻结金额不足');
            }

            $newFreezeAmount = $deposit->freeze_amount - $amount;
            $deposit->freeze_amount = $newFreezeAmount;
            if ($newFreezeAmount === 0 && $deposit->status === ServiceDeposit::STATUS_FROZEN) {
                $deposit->status = ServiceDeposit::STATUS_ACTIVE;
            }
            $deposit->save();

            $log = ServiceDepositLog::create([
                'player_user_id' => $playerUserId,
                'type'           => ServiceDepositLog::TYPE_UNFREEZE,
                'amount'         => $amount,
                'balance'        => $deposit->amount,
                'related_id'     => $relatedId,
                'description'    => $description ?: '保证金解冻',
            ]);

            Db::commit();
            write_action_log('service_deposit_unfreeze', "保证金解冻: 打手ID: {$playerUserId}, 金额: {$amount}分");
            return $deposit->toArray();
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('保证金解冻失败: ' . $e->getMessage());
            throw $e;
        }
    }

    public function getDepositLogList(int $playerUserId, int $page = 1, int $limit = 15): array
    {
        $query = ServiceDepositLog::byPlayer($playerUserId)->order('create_time', 'desc');
        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();
        return ['list' => $list, 'total' => $total];
    }

    public function getAllDepositList(array $params, int $page = 1, int $limit = 15): array
    {
        $query = ServiceDeposit::order('create_time', 'desc');

        if (!empty($params['status'])) {
            $query->byStatus($params['status']);
        }
        if (!empty($params['keyword'])) {
            $query->where('player_user_id', 'like', "%{$params['keyword']}%");
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        return ['list' => $list, 'total' => $total];
    }

    public function getAllLogList(array $params, int $page = 1, int $limit = 15): array
    {
        $query = ServiceDepositLog::order('create_time', 'desc');

        if (!empty($params['type'])) {
            $query->byType($params['type']);
        }
        if (!empty($params['player_user_id'])) {
            $query->byPlayer((int)$params['player_user_id']);
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        return ['list' => $list, 'total' => $total];
    }

    public function canAcceptOrder(int $playerUserId, int $minDeposit = 0): bool
    {
        if ($minDeposit <= 0) {
            return true;
        }
        $deposit = ServiceDeposit::byPlayer($playerUserId)->find();
        if (!$deposit) {
            return false;
        }
        $available = $deposit->amount - $deposit->freeze_amount;
        return $available >= $minDeposit;
    }
}
