<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class OrderRefundRule extends Model
{
    protected $name = 'order_refund_rule';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const STATUS_ENABLED  = 1;
    const STATUS_DISABLED = 0;

    public function scopeEnabled($query)
    {
        $query->where('status', self::STATUS_ENABLED);
    }

    public function scopeSortedByMinutes($query)
    {
        $query->order('minutes_threshold', 'asc');
    }
}
