<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class ArbitrationEvidence extends Model
{
    protected $name = 'arbitration_evidence';
    protected $autoWriteTimestamp = false;
    protected $dateFormat = 'Y-m-d H:i:s';

    const TYPE_IMAGE = 'image';
    const TYPE_VIDEO = 'video';
    const TYPE_AUDIO = 'audio';
    const TYPE_TEXT  = 'text';

    public function case()
    {
        return $this->belongsTo(ArbitrationCase::class, 'case_id', 'id');
    }
}
