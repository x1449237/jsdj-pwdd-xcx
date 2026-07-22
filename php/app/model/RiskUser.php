<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * AI 风险用户模型
 * @property int    $id
 * @property int    $user_id
 * @property string $risk_level     风险等级 low/medium/high
 * @property string $risk_type      风险类型
 * @property string $risk_detail    风险详情 JSON
 * @property int    $status         0-未处理 1-已处理
 * @property string $handle_result  处理结果
 * @property string $create_time
 * @property string $update_time
 */
class RiskUser extends Model
{
    protected $name = 'risk_user';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    // 风险等级
    const LEVEL_LOW    = 'low';
    const LEVEL_MEDIUM = 'medium';
    const LEVEL_HIGH   = 'high';

    // 状态
    const STATUS_UNPROCESSED = 0;
    const STATUS_PROCESSED   = 1;

    /**
     * 风险详情获取器 - JSON 解码
     */
    public function getRiskDetailAttr($value): array
    {
        return json_decode($value, true) ?: [];
    }

    public function setRiskDetailAttr($value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 关联用户
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * 按风险等级查询
     */
    public function scopeByLevel($query, string $level)
    {
        $query->where('risk_level', $level);
    }

    /**
     * 查询未处理
     */
    public function scopeUnprocessed($query)
    {
        $query->where('status', self::STATUS_UNPROCESSED);
    }

    /**
     * 按时间倒序
     */
    public function scopeLatest($query)
    {
        $query->order('create_time', 'desc');
    }
}