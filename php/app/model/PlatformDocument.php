<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 平台文档模型（协议/政策/合同）
 * 仅超级管理员可操作
 */
class PlatformDocument extends Model
{
    protected $name = 'platform_documents';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    // 文档类型
    const TYPE_AGREEMENT = 'agreement';
    const TYPE_POLICY    = 'policy';
    const TYPE_CONTRACT  = 'contract';

    public static function getTypeMap(): array
    {
        return [
            self::TYPE_AGREEMENT => '协议',
            self::TYPE_POLICY    => '政策',
            self::TYPE_CONTRACT  => '合同',
        ];
    }

    public function admin()
    {
        return $this->belongsTo(Admin::class, 'admin_id', 'id');
    }
}