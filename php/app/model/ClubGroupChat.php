<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 俱乐部群聊模型
 * @property int    $id
 * @property string $group_no              群编号
 * @property string $group_name            群名称
 * @property string $group_avatar          群头像
 * @property int    $group_type            群类型 1=闲聊 2=福利 3=售后
 * @property string $group_type_label      群类型标签
 * @property int    $creator_id            创建者ID
 * @property string $announcement          群公告
 * @property int    $member_count          成员数量
 * @property int    $max_member_count      最大成员数
 * @property int    $is_muted_all          是否全员禁言
 * @property int    $platform_account_id   平台官方账号ID
 * @property int    $status                状态 1=正常 0=解散
 * @property string $dissolve_reason       解散原因
 * @property string $dissolve_time         解散时间
 * @property int    $dissolve_operator_id  解散操作人ID
 * @property string $create_time
 * @property string $update_time
 */
class ClubGroupChat extends Model
{
    protected $name = 'club_group_chat';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const TYPE_CHAT       = 1;
    const TYPE_WELFARE    = 2;
    const TYPE_AFTER_SALE = 3;

    const STATUS_NORMAL    = 1;
    const STATUS_DISSOLVED = 0;

    /**
     * 关联创建者
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id', 'id');
    }

    /**
     * 关联平台官方账号
     */
    public function platformAccount()
    {
        return $this->belongsTo(PlatformOfficialAccount::class, 'platform_account_id', 'id');
    }

    /**
     * 关联群成员
     */
    public function members()
    {
        return $this->hasMany(GroupChatMember::class, 'group_id', 'id');
    }

    /**
     * 查询正常状态
     */
    public function scopeActive($query)
    {
        $query->where('status', self::STATUS_NORMAL);
    }

    /**
     * 按创建者查询
     */
    public function scopeByCreator($query, int $userId)
    {
        $query->where('creator_id', $userId);
    }

    /**
     * 按群类型查询
     */
    public function scopeByType($query, int $type)
    {
        $query->where('group_type', $type);
    }
}