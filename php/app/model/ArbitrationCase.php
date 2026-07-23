<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class ArbitrationCase extends Model
{
    protected $name = 'arbitration_case';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';
    protected $json = ['evidence_json'];
    protected $jsonAssoc = true;

    const STATUS_PENDING    = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_RESOLVED   = 'resolved';

    public function scopeByStatus($query, string $status)
    {
        $query->where('status', $status);
    }

    public function scopeByApplicant($query, int $userId)
    {
        $query->where('applicant_id', $userId);
    }

    public function scopeByRespondent($query, int $userId)
    {
        $query->where('respondent_id', $userId);
    }

    public function evidenceList()
    {
        return $this->hasMany(ArbitrationEvidence::class, 'case_id', 'id');
    }
}
