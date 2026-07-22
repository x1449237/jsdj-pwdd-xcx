<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 活体检测频控模型
 * @property int    $id
 * @property int    $user_id
 * @property string $ip
 * @property string $device_id     设备标识
 * @property int    $attempt_count 尝试次数
 * @property int    $status        0-正常 1-限制中
 * @property string $limit_time    限制截止时间
 * @property string $create_time
 * @property string $update_time
 */
class FaceVerifyRateLimit extends Model
{
    protected $name = 'face_verify_rate_limit';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    // 状态
    const STATUS_NORMAL  = 0;
    const STATUS_LIMITED = 1;

    /**
     * 关联用户
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * 按用户查询
     */
    public function scopeByUser($query, int $userId)
    {
        $query->where('user_id', $userId);
    }

    /**
     * 按 IP 查询
     */
    public function scopeByIp($query, string $ip)
    {
        $query->where('ip', $ip);
    }

    /**
     * 按设备查询
     */
    public function scopeByDevice($query, string $deviceId)
    {
        $query->where('device_id', $deviceId);
    }

    /**
     * 查询被限制的
     */
    public function scopeLimited($query)
    {
        $query->where('status', self::STATUS_LIMITED)
            ->where('limit_time', '>', date('Y-m-d H:i:s'));
    }

    /**
     * 检查是否被限制
     */
    public static function isLimited(int $userId, string $ip, string $deviceId = ''): bool
    {
        $query = self::where('status', self::STATUS_LIMITED)
            ->where('limit_time', '>', date('Y-m-d H:i:s'))
            ->where(function ($q) use ($userId, $ip, $deviceId) {
                $q->where('user_id', $userId);
                if (!empty($ip)) {
                    $q->whereOr('ip', $ip);
                }
                if (!empty($deviceId)) {
                    $q->whereOr('device_id', $deviceId);
                }
            });

        return $query->find() !== null;
    }
}