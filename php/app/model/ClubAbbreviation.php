<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 俱乐部缩写全局封存表（终身不可复用）
 */
class ClubAbbreviation extends Model
{
    protected $name = 'club_abbreviations';
    protected $autoWriteTimestamp = false;
    public $timestamps = false;

    /**
     * 检查缩写是否被占用（含所有历史状态）
     */
    public static function isOccupied(string $abbreviation): bool
    {
        return self::where('abbreviation', $abbreviation)->find() !== null;
    }

    /**
     * 封存缩写
     */
    public static function seal(string $abbreviation, string $clubName, int $clubId, string $status = 'active'): void
    {
        self::create([
            'abbreviation' => $abbreviation,
            'club_name'    => $clubName,
            'club_id'      => $clubId,
            'club_status'  => $status,
        ]);
    }
}