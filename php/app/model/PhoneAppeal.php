<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 手机号申诉模型
 * @property int    $id
 * @property int    $user_id
 * @property string $old_phone
 * @property string $new_phone
 * @property int    $status        0-待审核 1-通过 2-驳回
 * @property string $reason        申诉原因
 * @property string $evidence      证明材料 JSON
 * @property string $review_remark 审核备注
 * @property int    $reviewer_id   审核人
 * @property string $review_time
 * @property string $create_time
 * @property string $update_time
 */
class PhoneAppeal extends Model
{
    protected $name = 'phone_appeal';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    // 状态
    const STATUS_PENDING = 0;
    const STATUS_PASSED  = 1;
    const STATUS_REJECT  = 2;

    /**
     * 手机号获取器 - 脱敏
     */
    public function getOldPhoneAttr($value): string
    {
        return mask_sensitive($value, 'phone');
    }

    public function getNewPhoneAttr($value): string
    {
        return mask_sensitive($value, 'phone');
    }

    /**
     * 证明材料获取器 - JSON 解码
     */
    public function getEvidenceAttr($value): array
    {
        return json_decode($value, true) ?: [];
    }

    public function setEvidenceAttr($value): string
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
     * 关联审核人
     */
    public function reviewer()
    {
        return $this->belongsTo(Admin::class, 'reviewer_id', 'id');
    }

    /**
     * 关联沟通记录
     */
    public function communications()
    {
        return $this->hasMany(AppealCommunication::class, 'appeal_id', 'id');
    }

    /**
     * 关联催办记录
     */
    public function reminders()
    {
        return $this->hasMany(AppealReminder::class, 'appeal_id', 'id');
    }

    /**
     * 按用户查询
     */
    public function scopeByUser($query, int $userId)
    {
        $query->where('user_id', $userId);
    }

    /**
     * 查询待审核
     */
    public function scopePending($query)
    {
        $query->where('status', self::STATUS_PENDING);
    }

    /**
     * 按时间倒序
     */
    public function scopeLatest($query)
    {
        $query->order('create_time', 'desc');
    }
}