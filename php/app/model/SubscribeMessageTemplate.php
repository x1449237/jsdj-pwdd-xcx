<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 微信订阅消息模板配置
 * @property int    $id
 * @property string $template_id   微信模板ID
 * @property string $template_name 模板名称
 * @property string $scene         场景标识
 * @property string $scene_name    场景名称
 * @property string $fields        模板参数字段映射(JSON)
 * @property int    $is_enabled    是否启用
 * @property int    $hit_count     命中次数
 * @property int    $sort          排序
 * @property string $create_time
 * @property string $update_time
 */
class SubscribeMessageTemplate extends Model
{
    protected $name = 'subscribe_message_template';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    // 场景标识
    const SCENE_APPEAL_NOTIFY    = 'appeal_notify';
    const SCENE_ORDER_NOTIFY     = 'order_notify';
    const SCENE_CHAT_NOTIFY      = 'chat_notify';
    const SCENE_PLATFORM_INTERVENE = 'platform_intervene';
    const SCENE_AFTER_SALE_REMIND = 'after_sale_remind';

    /**
     * 场景映射
     */
    public static function getSceneMap(): array
    {
        return [
            self::SCENE_APPEAL_NOTIFY     => '申诉通知',
            self::SCENE_ORDER_NOTIFY      => '订单通知',
            self::SCENE_CHAT_NOTIFY       => '聊天通知',
            self::SCENE_PLATFORM_INTERVENE => '平台介入',
            self::SCENE_AFTER_SALE_REMIND => '售后提醒',
        ];
    }

    public function scopeEnabled($query)
    {
        $query->where('is_enabled', 1);
    }

    public function scopeByScene($query, string $scene)
    {
        $query->where('scene', $scene);
    }
}