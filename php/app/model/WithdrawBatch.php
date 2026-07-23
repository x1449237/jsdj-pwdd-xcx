<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 提现批次模型
 * @property int    $id
 * @property string $batch_no
 * @property int    $total_amount
 * @property int    $total_count
 * @property int    $success_count
 * @property int    $fail_count
 * @property int    $success_amount
 * @property int    $fail_amount
 * @property int    $channel
 * @property int    $status
 * @property int    $operator
 * @property string $operator_name
 * @property string $process_time
 * @property string $complete_time
 * @property string $remark
 * @property string $create_time
 * @property string $update_time
 */
class WithdrawBatch extends Model
{
    protected $name = 'withdraw_batch';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const CHANNEL_WECHAT = 1;
    const CHANNEL_ALIPAY = 2;
    const CHANNEL_BANK_CARD = 3;

    const STATUS_PENDING = 0;
    const STATUS_PROCESSING = 1;
    const STATUS_COMPLETED = 2;
    const STATUS_PARTIAL_FAIL = 3;
    const STATUS_ALL_FAIL = 4;

    /**
     * 金额获取器 - 分转元
     */
    public function getTotalAmountAttr($value): string
    {
        return fen_to_yuan((int)$value);
    }

    public function getSuccessAmountAttr($value): string
    {
        return fen_to_yuan((int)$value);
    }

    public function getFailAmountAttr($value): string
    {
        return fen_to_yuan((int)$value);
    }

    /**
     * 金额修改器 - 元转分
     */
    public function setTotalAmountAttr($value): int
    {
        return yuan_to_fen((string)$value);
    }

    public function setSuccessAmountAttr($value): int
    {
        return yuan_to_fen((string)$value);
    }

    public function setFailAmountAttr($value): int
    {
        return yuan_to_fen((string)$value);
    }

    /**
     * 关联操作人
     */
    public function operatorUser()
    {
        return $this->belongsTo(Admin::class, 'operator', 'id');
    }

    /**
     * 按渠道查询
     */
    public function scopeByChannel($query, int $channel)
    {
        $query->where('channel', $channel);
    }

    /**
     * 按状态查询
     */
    public function scopeByStatus($query, int $status)
    {
        $query->where('status', $status);
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
