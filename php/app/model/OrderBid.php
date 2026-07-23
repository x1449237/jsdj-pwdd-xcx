<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class OrderBid extends Model
{
    protected $name = 'order_bid';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const STATUS_BIDDING  = 0;
    const STATUS_WINNER   = 1;
    const STATUS_LOSER    = 2;
    const STATUS_CANCELED = 3;

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }

    public function player()
    {
        return $this->belongsTo(User::class, 'player_user_id', 'id');
    }

    public function scopeByOrder($query, int $orderId)
    {
        $query->where('order_id', $orderId);
    }

    public function scopeByPlayer($query, int $playerId)
    {
        $query->where('player_user_id', $playerId);
    }

    public function scopeByStatus($query, int $status)
    {
        $query->where('status', $status);
    }

    public function getBidPriceAttr($value): string
    {
        return fen_to_yuan((int)$value);
    }

    public function setBidPriceAttr($value): int
    {
        return yuan_to_fen((string)$value);
    }
}
