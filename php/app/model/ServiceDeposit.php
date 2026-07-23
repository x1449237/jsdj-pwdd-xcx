<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class ServiceDeposit extends Model
{
    protected $name = 'service_deposit';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const STATUS_ACTIVE    = 'active';
    const STATUS_FROZEN    = 'frozen';
    const STATUS_WITHDRAWN = 'withdrawn';

    public function scopeByPlayer($query, int $playerUserId)
    {
        $query->where('player_user_id', $playerUserId);
    }

    public function scopeByStatus($query, string $status)
    {
        $query->where('status', $status);
    }
}
