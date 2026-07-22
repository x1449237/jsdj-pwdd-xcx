<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 派单记录模型
 * @property int    $id
 * @property int    $order_id
 * @property int    $player_id     被派单打手
 * @property string $dispatch_type 派单方式 auto/manual
 * @property int    $status        0-待接单 1-已接单 2-已拒绝 3-超时
 * @property string $reject_reason
 * @property string $dispatch_time
 * @property string $response_time
 * @property string $create_time
 * @property string $update_time
 */
class DispatchRecord extends Model
{
    protected $name = 'dispatch_record';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    // 派单方式
    const TYPE_AUTO   = 'auto';
    const TYPE_MANUAL = 'manual';

    // 状态
    const STATUS_PENDING  = 0;
    const STATUS_ACCEPTED = 1;
    const STATUS_REJECTED = 2;
    const STATUS_TIMEOUT  = 3;

    /**
     * 关联订单
     */
    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }

    /**
     * 关联打手
     */
    public function player()
    {
        return $this->belongsTo(User::class, 'player_id', 'id');
    }

    /**
     * 按订单查询
     */
    public function scopeByOrder($query, int $orderId)
    {
        $query->where('order_id', $orderId);
    }

    /**
     * 查询待接单
     */
    public function scopePending($query)
    {
        $query->where('status', self::STATUS_PENDING);
    }

    /**
     * 按时间倒序
     */
    public function scopeLatest($query)
    {
        $query->order('create_time', 'desc');
    }
}