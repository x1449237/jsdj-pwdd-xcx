<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class PlayerTag extends Model
{
    protected $name = 'player_tag';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';
    protected $updateTime = false;

    const TAG_TYPE_GAME     = 'game';
    const TAG_TYPE_POSITION = 'position';
    const TAG_TYPE_VOICE    = 'voice';
    const TAG_TYPE_RANK     = 'rank';
    const TAG_TYPE_SKILL    = 'skill';

    public function player()
    {
        return $this->belongsTo(User::class, 'player_user_id', 'id');
    }

    public function scopeByPlayer($query, int $playerId)
    {
        $query->where('player_user_id', $playerId);
    }

    public function scopeByType($query, string $type)
    {
        $query->where('tag_type', $type);
    }
}
