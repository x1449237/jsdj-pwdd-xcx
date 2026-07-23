<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class PlayerFavorite extends Model
{
    protected $name = 'player_favorite';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';
    protected $updateTime = false;

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function player()
    {
        return $this->belongsTo(User::class, 'player_user_id', 'id');
    }

    public function scopeByUser($query, int $userId)
    {
        $query->where('user_id', $userId);
    }

    public function scopeByPlayer($query, int $playerId)
    {
        $query->where('player_user_id', $playerId);
    }
}
