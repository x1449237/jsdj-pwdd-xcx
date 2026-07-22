<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 电子签名模型
 * @property int    $id
 * @property int    $user_id
 * @property string $sign_type      签名类型：guardian-监护人 / agreement-协议
 * @property string $sign_content   签名内容
 * @property string $sign_image     签名图片URL
 * @property string $contract_id    第三方合同ID
 * @property int    $status         0-未签署 1-已签署
 * @property string $sign_time      签署时间
 * @property string $create_time
 * @property string $update_time
 */
class ElectronicSignature extends Model
{
    protected $name = 'electronic_signature';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    // 签名类型
    const TYPE_GUARDIAN  = 'guardian';
    const TYPE_AGREEMENT = 'agreement';

    // 状态
    const STATUS_UNSIGNED = 0;
    const STATUS_SIGNED   = 1;

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
     * 按签名类型查询
     */
    public function scopeByType($query, string $type)
    {
        $query->where('sign_type', $type);
    }

    /**
     * 查询已签署
     */
    public function scopeSigned($query)
    {
        $query->where('status', self::STATUS_SIGNED);
    }
}