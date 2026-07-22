<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 售后会话模型
 * @property int    $id
 * @property int    $order_id
 * @property int    $user_id
 * @property int    $platform_account_id
 * @property string $reason
 * @property string $images
 * @property int    $status
 * @property int    $risk_level     0-正常 1-低风险 2-高风险
 * @property int    $is_intervened  0-否 1-是
 * @property string $create_time
 * @property string $update_time
 */
class AfterSaleSession extends Model
{
    protected $name = 'after_sale_session';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const STATUS_OPEN   = 0;
    const STATUS_CLOSED = 1;

    const RISK_NORMAL = 0;
    const RISK_LOW    = 1;
    const RISK_HIGH   = 2;

    public function getImagesAttr($value): array
    {
        return json_decode($value, true) ?: [];
    }

    public function setImagesAttr($value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }
}