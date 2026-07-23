<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class AgreementRoleVersion extends Model
{
    protected $name = 'agreement_role_version';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const ROLE_PLAYER      = 'player';
    const ROLE_BUYER       = 'buyer';
    const ROLE_DISTRIBUTOR = 'distributor';
    const ROLE_CLUB        = 'club';

    const TYPE_USER_SERVICE = 'user_service';
    const TYPE_PRIVACY      = 'privacy';
    const TYPE_CLUB_ENTRY   = 'club_entry';
    const TYPE_PLAYER_ENTRY = 'player_entry';

    public function scopeByRole($query, string $role)
    {
        $query->where('role', $role);
    }

    public function scopeByType($query, string $type)
    {
        $query->where('agreement_type', $type);
    }

    public function scopeActive($query)
    {
        $query->where('is_active', 1);
    }
}
