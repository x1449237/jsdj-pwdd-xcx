<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 消费账单月报模型
 * @property int    $id
 * @property int    $bind_id
 * @property string $month
 * @property int    $total_amount
 * @property int    $order_count
 * @property string $report_data_json
 * @property string $create_time
 */
class ParentConsumeReport extends Model
{
    protected $name = 'parent_consume_report';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';
    protected $updateTime = false;

    protected $json = ['report_data_json'];
    protected $jsonAssoc = true;

    public function bind()
    {
        return $this->belongsTo(ParentGuardianBind::class, 'bind_id', 'id');
    }

    public function scopeByBind($query, int $bindId)
    {
        $query->where('bind_id', $bindId);
    }

    public function scopeByMonth($query, string $month)
    {
        $query->where('month', $month);
    }
}
