<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class ArbitrationRule extends Model
{
    protected $name = 'arbitration_rule';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const STATUS_DISABLED = 0;
    const STATUS_ENABLED  = 1;

    const RULE_PLAYER_LATE            = 'player_late';
    const RULE_NEGATIVE_SERVICE       = 'negative_service';
    const RULE_PLAYER_UNPROVOKED_REFUND = 'player_unprovoked_refund';
    const RULE_DEMAND_CHANGE          = 'demand_change';
    const RULE_FRAUD                  = 'fraud';

    const FAULT_PLAYER  = 'player';
    const FAULT_BUYER   = 'buyer';
    const FAULT_BOTH    = 'both';

    const PENALTY_REFUND_RATIO   = 'refund_ratio';
    const PENALTY_DEDUCT_CREDIT  = 'deduct_credit';
    const PENALTY_DEDUCT_DEPOSIT = 'deduct_deposit';
    const PENALTY_BAN_ACCOUNT    = 'ban_account';

    public function scopeEnabled($query)
    {
        $query->where('status', self::STATUS_ENABLED);
    }

    public function scopeByRuleType($query, string $type)
    {
        $query->where('rule_type', $type);
    }
}
