<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 分销佣金模型
 * @property int    $id
 * @property int    $user_id       受益人
 * @property int    $order_id      关联订单
 * @property string $amount        佣金金额
 * @property string $rate          佣金比例
 * @property int    $level         分销层级
 * @property int    $status        0-待结算 1-已结算 2-已取消
 * @property string $settle_time   结算时间
 * @property string $create_time
 * @property string $update_time
 */
class DistributorCommission extends Model
{
    protected $name = 'distributor_commission';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    // 状态
    const STATUS_PENDING  = 0;
    const STATUS_SETTLED  = 1;
    const STATUS_CANCELED = 2;

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
     * 按用户查询
     */
    public function scopeByUser($query, int $userId)
    {
        $query->where('user_id', $userId);
    }

    /**
     * 查询待结算
     */
    public function scopePending($query)
    {
        $query->where('status', self::STATUS_PENDING);
    }

    /**
     * 按层级查询
     */
    public function scopeByLevel($query, int $level)
    {
        $query->where('level', $level);
    }

    /**
     * 按时间范围查询
     */
    public function scopeBetween($query, string $start, string $end)
    {
        $query->whereBetween('create_time', [$start, $end]);
    }
}