<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 店铺配置模型
 * @property int    $id
 * @property string $shop_name
 * @property string $logo
 * @property string $contact_phone
 * @property string $contact_email
 * @property string $service_qq
 * @property string $service_wechat
 * @property string $working_hours  工作时间
 * @property string $notice         公告
 * @property string $agreement      协议 JSON
 * @property int    $status         0-维护 1-正常
 * @property string $create_time
 * @property string $update_time
 */
class ShopConfig extends Model
{
    protected $name = 'shop_config';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const STATUS_MAINTENANCE = 0;
    const STATUS_NORMAL      = 1;

    /**
     * 协议获取器 - JSON 解码
     */
    public function getAgreementAttr($value): array
    {
        return json_decode($value, true) ?: [];
    }

    public function setAgreementAttr($value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 查询正常状态
     */
    public function scopeEnabled($query)
    {
        $query->where('status', self::STATUS_NORMAL);
    }
}