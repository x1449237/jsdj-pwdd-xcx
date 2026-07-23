<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class ServiceDepositLog extends Model
{
    protected $name = 'service_deposit_log';
    protected $autoWriteTimestamp = false;
    protected $dateFormat = 'Y-m-d H:i:s';

    const TYPE_DEPOSIT   = 'deposit';
    const TYPE_DEDUCT    = 'deduct';
    const TYPE_REFUND    = 'refund';
    const TYPE_FREEZE    = 'freeze';
    const TYPE_UNFREEZE  = 'unfreeze';

    public function scopeByPlayer($query, int $playerUserId)
    {
        $query->where('player_user_id', $playerUserId);
    }

    public function scopeByType($query, string $type)
    {
        $query->where('type', $type);
    }
}
