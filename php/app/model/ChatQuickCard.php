<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 快捷服务卡片模型
 * @property int    $id
 * @property string $type        卡片类型: price/package/appointment
 * @property string $title       卡片标题
 * @property string $content     卡片内容
 * @property string $action      点击动作
 * @property array  $params_json 动作参数JSON
 * @property string $icon        卡片图标
 * @property int    $status      状态 1启用 0禁用
 * @property int    $sort        排序
 * @property string $create_time
 * @property string $update_time
 */
class ChatQuickCard extends Model
{
    protected $name = 'chat_quick_card';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';
    protected $json = ['params_json'];
    protected $jsonAssoc = true;

    const TYPE_PRICE       = 'price';
    const TYPE_PACKAGE     = 'package';
    const TYPE_APPOINTMENT = 'appointment';

    const STATUS_DISABLED = 0;
    const STATUS_ENABLED  = 1;

    public function scopeEnabled($query)
    {
        $query->where('status', self::STATUS_ENABLED);
    }

    public function scopeByType($query, string $type)
    {
        $query->where('type', $type);
    }

    public function scopeOrdered($query)
    {
        $query->order('sort', 'asc')->order('id', 'asc');
    }
}
