<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class ClubDynamic extends Model
{
    protected $name = 'club_dynamic';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const TYPE_RECORD  = 'record';
    const TYPE_DYNAMIC = 'dynamic';

    const STATUS_PENDING  = 0;
    const STATUS_APPROVED = 1;
    const STATUS_REJECTED = 2;

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

    public function scopeApproved($query)
    {
        $query->where('status', self::STATUS_APPROVED);
    }

    public function scopePending($query)
    {
        $query->where('status', self::STATUS_PENDING);
    }

    public function scopeRecord($query)
    {
        $query->where('type', self::TYPE_RECORD);
    }

    public function scopeDynamic($query)
    {
        $query->where('type', self::TYPE_DYNAMIC);
    }

    public static function getTypeMap(): array
    {
        return [
            self::TYPE_RECORD  => '战绩',
            self::TYPE_DYNAMIC => '动态',
        ];
    }

    public static function getStatusMap(): array
    {
        return [
            self::STATUS_PENDING  => '待审核',
            self::STATUS_APPROVED => '已通过',
            self::STATUS_REJECTED => '已驳回',
        ];
    }
}
