<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 活体缓存模型
 * @property int    $id
 * @property int    $user_id
 * @property string $cache_key
 * @property string $expire_time
 * @property string $create_time
 */
class RealnameCache extends Model
{
    protected $name = 'realname_cache';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';
    protected $updateTime = false;

    const KEY_LIVENESS_VERIFY = 'liveness_verify';
    const KEY_REALNAME_AUTH   = 'realname_auth';

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function scopeByUser($query, int $userId)
    {
        $query->where('user_id', $userId);
    }

    public function scopeByKey($query, string $cacheKey)
    {
        $query->where('cache_key', $cacheKey);
    }

    public function scopeValid($query)
    {
        $query->where('expire_time', '>', date('Y-m-d H:i:s'));
    }

    public function isExpired(): bool
    {
        return strtotime($this->getData('expire_time')) < time();
    }
}
