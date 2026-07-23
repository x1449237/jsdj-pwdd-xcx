<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class OrderAppointment extends Model
{
    protected $name = 'order_appointment';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

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

    public function scopeConfirmed($query)
    {
        $query->where('is_confirmed', 1);
    }
}
