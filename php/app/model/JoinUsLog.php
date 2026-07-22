<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 加入我们记录模型
 * @property int    $id
 * @property int    $user_id
 * @property string $real_name
 * @property string $phone
 * @property int    $age
 * @property string $game_experience 游戏经验
 * @property string $reason          申请理由
 * @property int    $status          0-待审核 1-通过 2-驳回
 * @property string $review_remark
 * @property int    $reviewer_id
 * @property string $review_time
 * @property string $create_time
 * @property string $update_time
 */
class JoinUsLog extends Model
{
    protected $name = 'join_us_log';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    // 状态
    const STATUS_PENDING = 0;
    const STATUS_PASSED  = 1;
    const STATUS_REJECT  = 2;

    /**
     * 姓名获取器 - 脱敏
     */
    public function getRealNameAttr($value): string
    {
        return mask_sensitive($value, 'name');
    }

    /**
     * 手机号获取器 - 脱敏
     */
    public function getPhoneAttr($value): string
    {
        return mask_sensitive($value, 'phone');
    }

    /**
     * 关联用户
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * 关联审核人
     */
    public function reviewer()
    {
        return $this->belongsTo(Admin::class, 'reviewer_id', 'id');
    }

    /**
     * 查询待审核
     */
    public function scopePending($query)
    {
        $query->where('status', self::STATUS_PENDING);
    }

    /**
     * 按用户查询
     */
    public function scopeByUser($query, int $userId)
    {
        $query->where('user_id', $userId);
    }

    /**
     * 按时间倒序
     */
    public function scopeLatest($query)
    {
        $query->order('create_time', 'desc');
    }
}