<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class OrderServiceTimer extends Model
{
    protected $name = 'order_service_timer';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const STATUS_NOT_START = 0;
    const STATUS_RUNNING   = 1;
    const STATUS_PAUSED    = 2;
    const STATUS_ENDED     = 3;

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }

    public function scopeByOrder($query, int $orderId)
    {
        $query->where('order_id', $orderId);
    }
}
