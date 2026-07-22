<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 熔断记录模型
 * @property int    $id
 * @property string $service_name  服务名称
 * @property int    $failure_count 失败次数
 * @property int    $threshold     熔断阈值
 * @property int    $status        0-关闭 1-半开 2-打开
 * @property string $open_time     熔断打开时间
 * @property string $close_time    熔断关闭时间
 * @property string $create_time
 * @property string $update_time
 */
class CircuitBreaker extends Model
{
    protected $name = 'circuit_breaker';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    // 状态
    const STATUS_CLOSED    = 0;
    const STATUS_HALF_OPEN = 1;
    const STATUS_OPEN      = 2;

    /**
     * 按服务名称查询
     */
    public function scopeByService($query, string $service)
    {
        $query->where('service_name', $service);
    }

    /**
     * 查询熔断打开状态
     */
    public function scopeOpen($query)
    {
        $query->where('status', self::STATUS_OPEN);
    }

    /**
     * 检查服务是否熔断
     */
    public static function isCircuitOpen(string $serviceName): bool
    {
        $breaker = self::where('service_name', $serviceName)
            ->where('status', self::STATUS_OPEN)
            ->find();

        return $breaker !== null;
    }
}