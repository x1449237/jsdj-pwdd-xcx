<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 风控日志模型
 * @property int    $id
 * @property int    $user_id
 * @property string $event         事件类型
 * @property string $risk_level    风险等级
 * @property string $detail        详情 JSON
 * @property int    $result        0-放行 1-拦截 2-人工审核
 * @property string $create_time
 */
class RiskControlLog extends Model
{
    protected $name = 'risk_control_log';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';
    protected $updateTime = false;

    // 结果
    const RESULT_PASS   = 0;
    const RESULT_BLOCK  = 1;
    const RESULT_REVIEW = 2;

    /**
     * 详情获取器 - JSON 解码
     */
    public function getDetailAttr($value): array
    {
        return json_decode($value, true) ?: [];
    }

    public function setDetailAttr($value): string
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
     * 查询被拦截的
     */
    public function scopeBlocked($query)
    {
        $query->where('result', self::RESULT_BLOCK);
    }

    /**
     * 按事件类型查询
     */
    public function scopeByEvent($query, string $event)
    {
        $query->where('event', $event);
    }

    /**
     * 按时间范围查询
     */
    public function scopeBetween($query, string $start, string $end)
    {
        $query->whereBetween('create_time', [$start, $end]);
    }

    /**
     * 按时间倒序
     */
    public function scopeLatest($query)
    {
        $query->order('create_time', 'desc');
    }
}