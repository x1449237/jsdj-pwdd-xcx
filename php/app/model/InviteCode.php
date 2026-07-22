<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 邀请码模型
 * @property int    $id
 * @property string $code
 * @property int    $creator_id   创建者ID
 * @property int    $use_count    使用次数
 * @property int    $max_use      最大使用次数
 * @property int    $status       0-禁用 1-正常
 * @property string $expire_time
 * @property string $create_time
 * @property string $update_time
 */
class InviteCode extends Model
{
    protected $name = 'invite_code';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    // 状态
    const STATUS_DISABLED = 0;
    const STATUS_ENABLED  = 1;

    /**
     * 关联创建者
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id', 'id');
    }

    /**
     * 关联绑定记录
     */
    public function bindLogs()
    {
        return $this->hasMany(InviteBindLog::class, 'invite_code_id', 'id');
    }

    /**
     * 查询正常状态
     */
    public function scopeEnabled($query)
    {
        $query->where('status', self::STATUS_ENABLED);
    }

    /**
     * 按邀请码查询
     */
    public function scopeByCode($query, string $code)
    {
        $query->where('code', $code);
    }

    /**
     * 查询未过期
     */
    public function scopeNotExpired($query)
    {
        $query->where('expire_time', '>', date('Y-m-d H:i:s'))
            ->whereOr('expire_time', null);
    }

    /**
     * 检查是否可用
     */
    public function isAvailable(): bool
    {
        if ($this->getData('status') != self::STATUS_ENABLED) {
            return false;
        }
        $expireTime = $this->getData('expire_time');
        if ($expireTime && strtotime($expireTime) < time()) {
            return false;
        }
        $maxUse = $this->getData('max_use');
        if ($maxUse > 0 && $this->getData('use_count') >= $maxUse) {
            return false;
        }
        return true;
    }
}