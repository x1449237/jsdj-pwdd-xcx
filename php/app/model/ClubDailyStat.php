<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class ClubDailyStat extends Model
{
    protected $name = 'club_daily_stat';
    protected $autoWriteTimestamp = false;

    public function club()
    {
        return $this->belongsTo(UserVBadge::class, 'club_id', 'id');
    }

    public function scopeByClub($query, int $clubId)
    {
        $query->where('club_id', $clubId);
    }

    public function scopeByDateRange($query, string $startDate, string $endDate)
    {
        $query->where('stat_date', '>=', $startDate)
              ->where('stat_date', '<=', $endDate);
    }

    public static function getOrCreate(int $clubId, string $date): self
    {
        $stat = self::where('club_id', $clubId)->where('stat_date', $date)->find();
        if (!$stat) {
            $stat = self::create([
                'club_id'  => $clubId,
                'stat_date' => $date,
            ]);
        }
        return $stat;
    }
}
