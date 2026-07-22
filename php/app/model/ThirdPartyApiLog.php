<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 第三方 API 日志模型
 * @property int    $id
 * @property string $api_name      API名称
 * @property string $endpoint      请求地址
 * @property string $method        HTTP方法
 * @property string $request_data  请求数据 JSON
 * @property string $response_data 响应数据 JSON
 * @property int    $http_code     HTTP状态码
 * @property int    $exec_time     执行耗时（毫秒）
 * @property int    $status        0-失败 1-成功
 * @property string $error_message
 * @property string $create_time
 */
class ThirdPartyApiLog extends Model
{
    protected $name = 'third_party_api_log';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';
    protected $updateTime = false;

    const STATUS_FAIL    = 0;
    const STATUS_SUCCESS = 1;

    protected $hidden = ['request_data', 'response_data'];

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
     * 响应数据获取器 - JSON 解码
     */
    public function getResponseDataAttr($value): array
    {
        return json_decode($value, true) ?: [];
    }

    public function setResponseDataAttr($value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 按 API 名称查询
     */
    public function scopeByApiName($query, string $name)
    {
        $query->where('api_name', $name);
    }

    /**
     * 查询失败记录
     */
    public function scopeFailed($query)
    {
        $query->where('status', self::STATUS_FAIL);
    }

    /**
     * 按时间范围查询
     */
    public function scopeBetween($query, string $start, string $end)
    {
        $query->whereBetween('create_time', [$start, $end]);
    }

    /**
     * 按时间倒序
     */
    public function scopeLatest($query)
    {
        $query->order('create_time', 'desc');
    }
}