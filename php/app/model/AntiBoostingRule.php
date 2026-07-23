<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class AntiBoostingRule extends Model
{
    protected $name = 'anti_boosting_rule';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const STATUS_DISABLED = 0;
    const STATUS_ENABLED  = 1;

    const LEVEL_WARN      = 'warn';
    const LEVEL_INTERCEPT = 'intercept';
    const LEVEL_BAN       = 'ban';

    public function scopeEnabled($query)
    {
        $query->where('status', self::STATUS_ENABLED);
    }

    public function scopeByLevel($query, string $level)
    {
        $query->where('level', $level);
    }
}
