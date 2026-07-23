<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class ClubAnnouncement extends Model
{
    protected $name = 'club_announcement';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    public function club()
    {
        return $this->belongsTo(UserVBadge::class, 'club_id', 'id');
    }

    public function scopeByClub($query, int $clubId)
    {
        $query->where('club_id', $clubId);
    }

    public function scopeTop($query)
    {
        $query->where('is_top', 1)->order('is_top', 'desc');
    }
}
