<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 第三方 API 重试队列模型
 * @property int    $id
 * @property string $api_name
 * @property string $endpoint
 * @property string $method
 * @property string $request_data  JSON
 * @property int    $retry_count   已重试次数
 * @property int    $max_retry     最大重试次数
 * @property int    $retry_interval 重试间隔（秒）
 * @property string $next_retry_time
 * @property int    $status        0-待重试 1-重试中 2-成功 3-失败
 * @property string $last_error
 * @property string $create_time
 * @property string $update_time
 */
class ThirdPartyRetryQueue extends Model
{
    protected $name = 'third_party_retry_queue';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    // 状态
    const STATUS_PENDING = 0;
    const STATUS_RETRYING = 1;
    const STATUS_SUCCESS = 2;
    const STATUS_FAIL    = 3;

    /**
     * 请求数据获取器 - JSON 解码
     */
    public function getRequestDataAttr($value): array
    {
        return json_decode($value, true) ?: [];
    }

    public function setRequestDataAttr($value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 查询待重试
     */
    public function scopePending($query)
    {
        $query->where('status', self::STATUS_PENDING)
            ->where('next_retry_time', '<=', date('Y-m-d H:i:s'));
    }

    /**
     * 按 API 名称查询
     */
    public function scopeByApiName($query, string $name)
    {
        $query->where('api_name', $name);
    }

    /**
     * 按时间倒序
     */
    public function scopeLatest($query)
    {
        $query->order('create_time', 'desc');
    }
}