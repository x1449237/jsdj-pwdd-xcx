<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 售后介入日志模型
 * @property int    $id
 * @property int    $session_id
 * @property int    $operator_id
 * @property string $hit_keywords
 * @property string $action
 * @property string $result
 * @property string $create_time
 */
class AfterSaleInterveneLog extends Model
{
    protected $name = 'after_sale_intervene_log';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';
    protected $updateTime = false;

    public function getHitKeywordsAttr($value): array
    {
        return json_decode($value, true) ?: [];
    }

    public function setHitKeywordsAttr($value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }
}