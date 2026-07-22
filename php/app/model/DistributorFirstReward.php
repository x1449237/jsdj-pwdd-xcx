<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 分销首单奖励模型
 * @property int    $id
 * @property int    $user_id       受益人
 * @property int    $order_id      关联订单
 * @property string $amount        奖励金额
 * @property int    $status        0-待发放 1-已发放
 * @property string $grant_time    发放时间
 * @property string $create_time
 * @property string $update_time
 */
class DistributorFirstReward extends Model
{
    protected $name = 'distributor_first_reward';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const STATUS_PENDING = 0;
    const STATUS_GRANTED = 1;

    /**
     * 金额获取器 - 分转元
     */
    public function getAmountAttr($value): string
    {
        return fen_to_yuan((int)$value);
    }

    public function setAmountAttr($value): int
    {
        return yuan_to_fen((string)$value);
    }

    /**
     * 关联用户
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * 关联订单
     */
    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }

    /**
     * 查询待发放
     */
    public function scopePending($query)
    {
        $query->where('status', self::STATUS_PENDING);
    }

    /**
     * 按用户查询
     */
    public function scopeByUser($query, int $userId)
    {
        $query->where('user_id', $userId);
    }
}