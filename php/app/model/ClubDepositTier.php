<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class ClubDepositTier extends Model
{
    protected $name = 'club_deposit_tier';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const STATUS_ENABLED  = 1;
    const STATUS_DISABLED = 0;

    public function scopeByType($query, string $clubType)
    {
        $query->where('club_type', $clubType);
    }

    public function scopeEnabled($query)
    {
        $query->where('status', self::STATUS_ENABLED);
    }

    public static function calcDepositByRevenue(string $clubType, int $revenue): int
    {
        $tiers = self::where('club_type', $clubType)
            ->where('status', self::STATUS_ENABLED)
            ->order('revenue_threshold', 'desc')
            ->select();

        foreach ($tiers as $tier) {
            if ($revenue >= $tier->revenue_threshold) {
                return (int) $tier->deposit_amount;
            }
        }

        return 0;
    }

    public static function getTierByRevenue(string $clubType, int $revenue): ?self
    {
        $tiers = self::where('club_type', $clubType)
            ->where('status', self::STATUS_ENABLED)
            ->order('revenue_threshold', 'desc')
            ->select();

        foreach ($tiers as $tier) {
            if ($revenue >= $tier->revenue_threshold) {
                return $tier;
            }
        }

        return null;
    }
}
