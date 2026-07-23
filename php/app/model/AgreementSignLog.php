<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class AgreementSignLog extends Model
{
    protected $name = 'agreement_sign_log';
    protected $autoWriteTimestamp = false;
    protected $dateFormat = 'Y-m-d H:i:s';

    const ROLE_PLAYER      = 'player';
    const ROLE_BUYER       = 'buyer';
    const ROLE_DISTRIBUTOR = 'distributor';
    const ROLE_CLUB        = 'club';

    public function scopeByUser($query, int $userId)
    {
        $query->where('user_id', $userId);
    }

    public function scopeByRole($query, string $role)
    {
        $query->where('role', $role);
    }

    public function scopeByType($query, string $type)
    {
        $query->where('agreement_type', $type);
    }
}
