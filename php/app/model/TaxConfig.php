<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 个税配置模型
 * @property int    $id
 * @property int    $role
 * @property string $tax_rate
 * @property int    $threshold
 * @property int    $quick_deduction
 * @property int    $status
 * @property string $create_time
 * @property string $update_time
 */
class TaxConfig extends Model
{
    protected $name = 'tax_config';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const ROLE_PLAYER = 1;
    const ROLE_CLUB = 2;
    const ROLE_DISTRIBUTOR = 3;

    const STATUS_DISABLED = 0;
    const STATUS_ENABLED = 1;

    /**
     * 起征点获取器 - 分转元
     */
    public function getThresholdAttr($value): string
    {
        return fen_to_yuan((int)$value);
    }

    /**
     * 速算扣除数获取器 - 分转元
     */
    public function getQuickDeductionAttr($value): string
    {
        return fen_to_yuan((int)$value);
    }

    /**
     * 起征点修改器 - 元转分
     */
    public function setThresholdAttr($value): int
    {
        return yuan_to_fen((string)$value);
    }

    /**
     * 速算扣除数修改器 - 元转分
     */
    public function setQuickDeductionAttr($value): int
    {
        return yuan_to_fen((string)$value);
    }

    /**
     * 按角色查询
     */
    public function scopeByRole($query, int $role)
    {
        $query->where('role', $role);
    }

    /**
     * 按状态查询
     */
    public function scopeByStatus($query, int $status)
    {
        $query->where('status', $status);
    }

    /**
     * 查询启用的
     */
    public function scopeEnabled($query)
    {
        $query->where('status', self::STATUS_ENABLED);
    }
}
