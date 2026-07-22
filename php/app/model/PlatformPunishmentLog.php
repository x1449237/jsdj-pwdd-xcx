<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 平台处罚记录模型
 * @property int    $id
 * @property string $target_type          目标类型 user/group
 * @property int    $target_id            目标ID
 * @property string $punishment_type      处罚类型 mute/ban/freeze/expel/group_dissolve
 * @property string $punishment_detail    处罚详情
 * @property string $duration_type        时长类型 temporary/permanent
 * @property string $start_time           开始时间
 * @property string $end_time             结束时间
 * @property int    $operator_account_id  操作平台账号ID
 * @property string $reason               处罚原因
 * @property int    $is_revoked           是否撤销
 * @property string $revoke_time          撤销时间
 * @property int    $revoke_operator_id   撤销操作人ID
 * @property string $create_time
 */
class PlatformPunishmentLog extends Model
{
    protected $name = 'platform_punishment_log';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';
    protected $updateTime = false;

    const TARGET_USER  = 'user';
    const TARGET_GROUP = 'group';

    const TYPE_MUTE     = 'mute';
    const TYPE_BAN      = 'ban';
    const TYPE_FREEZE   = 'freeze';
    const TYPE_EXPEL    = 'expel';
    const TYPE_DISSOLVE = 'group_dissolve';

    /**
     * 关联操作平台账号
     */
    public function operatorAccount()
    {
        return $this->belongsTo(PlatformOfficialAccount::class, 'operator_account_id', 'id');
    }

    /**
     * 按目标类型查询
     */
    public function scopeByTarget($query, string $targetType, int $targetId)
    {
        $query->where('target_type', $targetType)->where('target_id', $targetId);
    }

    /**
     * 按处罚类型查询
     */
    public function scopeByType($query, string $type)
    {
        $query->where('punishment_type', $type);
    }
}