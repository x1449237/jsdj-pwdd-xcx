<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 订单状态日志模型
 * @property int    $id
 * @property int    $order_id
 * @property int    $from_status  变更前状态
 * @property int    $to_status    变更后状态
 * @property string $operator     操作人
 * @property string $remark       备注
 * @property string $create_time
 */
class OrderStatusLog extends Model
{
    protected $name = 'order_status_log';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';
    protected $updateTime = false;

    /**
     * 关联订单
     */
    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }

    /**
     * 按订单查询
     */
    public function scopeByOrder($query, int $orderId)
    {
        $query->where('order_id', $orderId);
    }

    /**
     * 按时间倒序
     */
    public function scopeLatest($query)
    {
        $query->order('create_time', 'desc');
    }
}