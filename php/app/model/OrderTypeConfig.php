<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class OrderTypeConfig extends Model
{
    protected $name = 'order_type_config';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const TYPE_INSTANT     = 'instant';
    const TYPE_APPOINTMENT = 'appointment';
    const TYPE_TEAM        = 'team';
    const TYPE_TEACHING    = 'teaching';

    const STATUS_ENABLED  = 1;
    const STATUS_DISABLED = 0;

    public function scopeEnabled($query)
    {
        $query->where('status', self::STATUS_ENABLED);
    }

    public function scopeSorted($query)
    {
        $query->order('sort', 'asc');
    }
}
