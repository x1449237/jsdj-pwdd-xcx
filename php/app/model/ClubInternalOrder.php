<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class ClubInternalOrder extends Model
{
    protected $name = 'club_internal_order';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const STATUS_PENDING   = 0;
    const STATUS_ACCEPTED  = 1;
    const STATUS_PROGRESS  = 2;
    const STATUS_VERIFY    = 3;
    const STATUS_COMPLETED = 4;
    const STATUS_CANCELLED = 5;

    public function club()
    {
        return $this->belongsTo(UserVBadge::class, 'club_id', 'id');
    }

    public function player()
    {
        return $this->belongsTo(User::class, 'player_user_id', 'id');
    }

    public function scopeByClub($query, int $clubId)
    {
        $query->where('club_id', $clubId);
    }

    public function scopeByPlayer($query, int $userId)
    {
        $query->where('player_user_id', $userId);
    }

    public function scopePending($query)
    {
        $query->where('status', self::STATUS_PENDING);
    }

    public function scopeStatus($query, int $status)
    {
        $query->where('status', $status);
    }

    public static function getStatusMap(): array
    {
        return [
            self::STATUS_PENDING   => '待接单',
            self::STATUS_ACCEPTED  => '已接单',
            self::STATUS_PROGRESS  => '进行中',
            self::STATUS_VERIFY    => '待验收',
            self::STATUS_COMPLETED => '已完成',
            self::STATUS_CANCELLED => '已取消',
        ];
    }

    public static function generateOrderNo(int $clubId): string
    {
        return 'CIO' . $clubId . date('YmdHis') . mt_rand(1000, 9999);
    }
}
