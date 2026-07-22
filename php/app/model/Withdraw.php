<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 提现记录模型
 * @property int    $id
 * @property int    $user_id
 * @property string $withdraw_sn   提现单号
 * @property string $amount        提现金额
 * @property string $fee           手续费
 * @property string $actual_amount 实际到账金额
 * @property int    $method        提现方式 1-微信 2-支付宝 3-银行卡
 * @property string $account_info  收款账户信息 JSON
 * @property int    $status        0-待审核 1-处理中 2-成功 3-失败 4-已取消
 * @property string $remark
 * @property string $processed_time
 * @property string $create_time
 * @property string $update_time
 */
class Withdraw extends Model
{
    protected $name = 'withdraw';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    protected $hidden = ['account_info'];

    // 提现方式
    const METHOD_WECHAT   = 1;
    const METHOD_ALIPAY   = 2;
    const METHOD_BANK_CARD = 3;

    // 状态
    const STATUS_PENDING   = 0;
    const STATUS_PROCESS   = 1;
    const STATUS_SUCCESS   = 2;
    const STATUS_FAIL      = 3;
    const STATUS_CANCELED  = 4;

    /**
     * 金额获取器 - 分转元
     */
    public function getAmountAttr($value): string
    {
        return fen_to_yuan((int)$value);
    }

    public function getFeeAttr($value): string
    {
        return fen_to_yuan((int)$value);
    }

    public function getActualAmountAttr($value): string
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

    public function setFeeAttr($value): int
    {
        return yuan_to_fen((string)$value);
    }

    public function setActualAmountAttr($value): int
    {
        return yuan_to_fen((string)$value);
    }

    /**
     * 账户信息获取器 - JSON 解码
     */
    public function getAccountInfoAttr($value): array
    {
        return json_decode($value, true) ?: [];
    }

    public function setAccountInfoAttr($value): string
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
     * 按用户查询
     */
    public function scopeByUser($query, int $userId)
    {
        $query->where('user_id', $userId);
    }

    /**
     * 按状态查询
     */
    public function scopeByStatus($query, int $status)
    {
        $query->where('status', $status);
    }

    /**
     * 查询待处理
     */
    public function scopePending($query)
    {
        $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_PROCESS]);
    }

    /**
     * 按时间倒序
     */
    public function scopeLatest($query)
    {
        $query->order('create_time', 'desc');
    }
}