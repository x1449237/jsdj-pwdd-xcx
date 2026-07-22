<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 提现配置模型
 * @property int    $id
 * @property string $min_amount      最低提现金额
 * @property string $max_amount      最高提现金额
 * @property string $fee_rate        手续费率
 * @property int    $daily_limit     每日次数限制
 * @property string $daily_amount    每日金额限制
 * @property int    $status          0-禁用 1-正常
 * @property string $create_time
 * @property string $update_time
 */
class WithdrawConfig extends Model
{
    protected $name = 'withdraw_config';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const STATUS_DISABLED = 0;
    const STATUS_ENABLED  = 1;

    /**
     * 金额获取器 - 分转元
     */
    public function getMinAmountAttr($value): string
    {
        return fen_to_yuan((int)$value);
    }

    public function getMaxAmountAttr($value): string
    {
        return fen_to_yuan((int)$value);
    }

    public function getDailyAmountAttr($value): string
    {
        return fen_to_yuan((int)$value);
    }

    /**
     * 金额修改器 - 元转分
     */
    public function setMinAmountAttr($value): int
    {
        return yuan_to_fen((string)$value);
    }

    public function setMaxAmountAttr($value): int
    {
        return yuan_to_fen((string)$value);
    }

    public function setDailyAmountAttr($value): int
    {
        return yuan_to_fen((string)$value);
    }

    /**
     * 查询正常状态
     */
    public function scopeEnabled($query)
    {
        $query->where('status', self::STATUS_ENABLED);
    }
}