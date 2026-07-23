<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class OrderEvidence extends Model
{
    protected $name = 'order_evidence';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';
    protected $updateTime = false;

    const TYPE_GAMEPLAY_VIDEO    = 'gameplay_video';
    const TYPE_RANK_SCREENSHOT   = 'rank_screenshot';
    const TYPE_OTHER             = 'other';

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploader_id', 'id');
    }

    public function scopeByOrder($query, int $orderId)
    {
        $query->where('order_id', $orderId);
    }

    public function scopeByType($query, string $type)
    {
        $query->where('type', $type);
    }
}
