<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * WebSocket 连接模型
 * @property int    $id
 * @property int    $user_id
 * @property string $connection_id  WebSocket连接ID
 * @property string $client_id      Workerman client_id
 * @property string $device_type    设备类型
 * @property string $ip
 * @property int    $status         0-断开 1-连接中
 * @property string $connect_time
 * @property string $disconnect_time
 * @property string $create_time
 * @property string $update_time
 */
class WsConnection extends Model
{
    protected $name = 'ws_connection';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const STATUS_DISCONNECTED = 0;
    const STATUS_CONNECTED    = 1;

    /**
     * 关联用户
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * 查询连接中
     */
    public function scopeConnected($query)
    {
        $query->where('status', self::STATUS_CONNECTED);
    }

    /**
     * 按用户查询
     */
    public function scopeByUser($query, int $userId)
    {
        $query->where('user_id', $userId);
    }

    /**
     * 按连接ID查询
     */
    public function scopeByConnectionId($query, string $connectionId)
    {
        $query->where('connection_id', $connectionId);
    }
}