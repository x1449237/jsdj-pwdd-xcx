<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 打手探针日志模型
 * @property int    $id
 * @property int    $player_id
 * @property string $probe_type    探针类型 ping/http/websocket
 * @property string $target        目标地址
 * @property int    $status        0-失败 1-成功
 * @property int    $response_time 响应时间（毫秒）
 * @property string $detail        详情 JSON
 * @property string $create_time
 */
class PlayerProbeLog extends Model
{
    protected $name = 'player_probe_log';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';
    protected $updateTime = false;

    // 探针类型
    const TYPE_PING  = 'ping';
    const TYPE_HTTP  = 'http';
    const TYPE_WS    = 'websocket';

    const STATUS_FAIL    = 0;
    const STATUS_SUCCESS = 1;

    /**
     * 详情获取器 - JSON 解码
     */
    public function getDetailAttr($value): array
    {
        return json_decode($value, true) ?: [];
    }

    public function setDetailAttr($value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 关联打手
     */
    public function player()
    {
        return $this->belongsTo(User::class, 'player_id', 'id');
    }

    /**
     * 按打手查询
     */
    public function scopeByPlayer($query, int $playerId)
    {
        $query->where('player_id', $playerId);
    }

    /**
     * 查询失败记录
     */
    public function scopeFailed($query)
    {
        $query->where('status', self::STATUS_FAIL);
    }

    /**
     * 按时间倒序
     */
    public function scopeLatest($query)
    {
        $query->order('create_time', 'desc');
    }
}