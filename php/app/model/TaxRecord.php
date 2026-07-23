<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 个税代扣记录模型
 * @property int    $id
 * @property int    $user_id
 * @property int    $role
 * @property int    $amount
 * @property int    $tax_amount
 * @property string $tax_rate
 * @property int    $threshold
 * @property string $month
 * @property int    $withdraw_id
 * @property int    $status
 * @property string $certificate_no
 * @property string $create_time
 */
class TaxRecord extends Model
{
    protected $name = 'tax_record';
    protected $autoWriteTimestamp = false;
    protected $createTime = 'create_time';
    protected $dateFormat = 'Y-m-d H:i:s';

    const ROLE_PLAYER = 1;
    const ROLE_CLUB = 2;
    const ROLE_DISTRIBUTOR = 3;

    const STATUS_WITHHOLD = 1;
    const STATUS_DECLARED = 2;
    const STATUS_COMPLETED = 3;

    /**
     * 金额获取器 - 分转元
     */
    public function getAmountAttr($value): string
    {
        return fen_to_yuan((int)$value);
    }

    public function getTaxAmountAttr($value): string
    {
        return fen_to_yuan((int)$value);
    }

    public function getThresholdAttr($value): string
    {
        return fen_to_yuan((int)$value);
    }

    /**
     * 金额修改器 - 元转分
     */
    public function setAmountAttr($value): int
    {
        return yuan_to_fen((string)$value);
    }

    public function setTaxAmountAttr($value): int
    {
        return yuan_to_fen((string)$value);
    }

    public function setThresholdAttr($value): int
    {
        return yuan_to_fen((string)$value);
    }

    /**
     * 关联用户
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * 关联提现
     */
    public function withdraw()
    {
        return $this->belongsTo(Withdraw::class, 'withdraw_id', 'id');
    }

    /**
     * 按用户查询
     */
    public function scopeByUser($query, int $userId)
    {
        $query->where('user_id', $userId);
    }

    /**
     * 按角色查询
     */
    public function scopeByRole($query, int $role)
    {
        $query->where('role', $role);
    }

    /**
     * 按月份查询
     */
    public function scopeByMonth($query, string $month)
    {
        $query->where('month', $month);
    }

    /**
     * 按状态查询
     */
    public function scopeByStatus($query, int $status)
    {
        $query->where('status', $status);
    }

    /**
     * 按时间倒序
     */
    public function scopeLatest($query)
    {
        $query->order('create_time', 'desc');
    }
}
