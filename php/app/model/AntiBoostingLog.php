<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class AntiBoostingLog extends Model
{
    protected $name = 'anti_boosting_log';
    protected $autoWriteTimestamp = false;
    protected $dateFormat = 'Y-m-d H:i:s';

    const SOURCE_ORDER        = 'order';
    const SOURCE_CHAT         = 'chat';
    const SOURCE_PRIVATE_CHAT = 'private_chat';
    const SOURCE_GROUP_CHAT   = 'group_chat';

    const LEVEL_WARN      = 'warn';
    const LEVEL_INTERCEPT = 'intercept';
    const LEVEL_BAN       = 'ban';

    public function scopeBySource($query, string $source)
    {
        $query->where('source', $source);
    }

    public function scopeByUser($query, int $userId)
    {
        $query->where('user_id', $userId);
    }

    public function scopeByLevel($query, string $level)
    {
        $query->where('level', $level);
    }

    public function scopeHandled($query, bool $handled = true)
    {
        $query->where('handled', $handled ? 1 : 0);
    }
}
