<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 用户充值记录模型
 * @property int    $id
 * @property int    $user_id
 * @property string $amount
 * @property string $bonus_amount
 * @property int    $activity_id
 * @property int    $pay_status      0-待支付 1-已支付 2-已失败
 * @property string $transaction_id
 * @property string $out_trade_no
 * @property string $pay_time
 * @property string $create_time
 * @property string $update_time
 */
class UserRechargeLog extends Model
{
    protected $name = 'user_recharge_log';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const STATUS_PENDING = 0;
    const STATUS_PAID    = 1;
    const STATUS_FAILED  = 2;

    public function scopeByUserId($query, int $userId)
    {
        $query->where('user_id', $userId);
    }

    public function scopePaid($query)
    {
        $query->where('pay_status', self::STATUS_PAID);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function activity()
    {
        return $this->belongsTo(RechargeActivity::class, 'activity_id', 'id');
    }
}
