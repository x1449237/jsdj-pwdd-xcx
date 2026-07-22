<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 实名认证记录模型
 * @property int    $id
 * @property int    $user_id
 * @property string $real_name
 * @property string $id_card
 * @property int    $verify_type   1-身份证 2-人脸 3-银行卡
 * @property int    $status        0-失败 1-成功 2-审核中
 * @property string $verify_result 验证结果 JSON
 * @property string $verify_time
 * @property string $create_time
 * @property string $update_time
 */
class RealVerifyLog extends Model
{
    protected $name = 'real_verify_log';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    protected $hidden = ['id_card'];

    // 验证类型
    const TYPE_ID_CARD  = 1;
    const TYPE_FACE     = 2;
    const TYPE_BANK_CARD = 3;

    // 状态
    const STATUS_FAIL     = 0;
    const STATUS_SUCCESS  = 1;
    const STATUS_PENDING  = 2;

    /**
     * 真实姓名获取器 - 脱敏
     */
    public function getRealNameAttr($value): string
    {
        return mask_sensitive($value, 'name');
    }

    /**
     * 身份证号获取器 - 脱敏
     */
    public function getIdCardAttr($value): string
    {
        return mask_sensitive($value, 'id_card');
    }

    /**
     * 验证结果获取器 - JSON 解码
     */
    public function getVerifyResultAttr($value): array
    {
        return json_decode($value, true) ?: [];
    }

    /**
     * 验证结果修改器 - JSON 编码
     */
    public function setVerifyResultAttr($value): string
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
     * 查询成功的记录
     */
    public function scopeSuccess($query)
    {
        $query->where('status', self::STATUS_SUCCESS);
    }

    /**
     * 按验证类型查询
     */
    public function scopeByType($query, int $type)
    {
        $query->where('verify_type', $type);
    }

    /**
     * 按时间倒序
     */
    public function scopeLatest($query)
    {
        $query->order('create_time', 'desc');
    }
}