<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class ClubBranch extends Model
{
    protected $name = 'club_branch';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const STATUS_ACTIVE = 1;
    const STATUS_CLOSED = 0;

    public function club()
    {
        return $this->belongsTo(UserVBadge::class, 'club_id', 'id');
    }

    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_user_id', 'id');
    }

    public function scopeByClub($query, int $clubId)
    {
        $query->where('club_id', $clubId);
    }

    public function scopeActive($query)
    {
        $query->where('status', self::STATUS_ACTIVE);
    }
}
