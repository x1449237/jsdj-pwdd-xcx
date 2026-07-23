<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 飞单风控规则模型
 * @property int    $id
 * @property string $rule_type  规则类型: wechat/qq/phone/offline_transfer/bank_card
 * @property string $rule_name  规则名称
 * @property string $pattern    匹配规则（正则表达式）
 * @property string $level      风险等级: warning/mute/ban
 * @property int    $status     状态 1启用 0禁用
 * @property int    $sort       排序
 * @property string $create_time
 * @property string $update_time
 */
class ChatAntiFraudRule extends Model
{
    protected $name = 'chat_anti_fraud_rule';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const LEVEL_WARNING = 'warning';
    const LEVEL_MUTE    = 'mute';
    const LEVEL_BAN     = 'ban';

    const STATUS_DISABLED = 0;
    const STATUS_ENABLED  = 1;

    const TYPE_WECHAT          = 'wechat';
    const TYPE_QQ              = 'qq';
    const TYPE_PHONE           = 'phone';
    const TYPE_OFFLINE_TRANSFER = 'offline_transfer';
    const TYPE_BANK_CARD       = 'bank_card';

    public function scopeEnabled($query)
    {
        $query->where('status', self::STATUS_ENABLED);
    }

    public function scopeByType($query, string $type)
    {
        $query->where('rule_type', $type);
    }

    public function scopeByLevel($query, string $level)
    {
        $query->where('level', $level);
    }
}
