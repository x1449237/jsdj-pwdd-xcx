<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class ArbitrationEvidenceTpl extends Model
{
    protected $name = 'arbitration_evidence_tpl';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';
    protected $json = ['required_items_json'];
    protected $jsonAssoc = true;

    const STATUS_DISABLED = 0;
    const STATUS_ENABLED  = 1;

    const DISPUTE_PLAYER_LATE       = 'player_late';
    const DISPUTE_NEGATIVE_SERVICE  = 'negative_service';
    const DISPUTE_PLAYER_REFUND     = 'player_refund';
    const DISPUTE_DEMAND_CHANGE     = 'demand_change';
    const DISPUTE_OTHER             = 'other';

    public function scopeEnabled($query)
    {
        $query->where('status', self::STATUS_ENABLED);
    }

    public function scopeByDisputeType($query, string $type)
    {
        $query->where('dispute_type', $type);
    }
}
