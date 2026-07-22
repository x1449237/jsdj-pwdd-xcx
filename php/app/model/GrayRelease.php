<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 灰度发布模型
 * @property int    $id
 * @property string $feature_key   功能标识
 * @property string $feature_name  功能名称
 * @property int    $percentage    灰度比例 0-100
 * @property string $white_list    白名单用户 JSON
 * @property string $black_list    黑名单用户 JSON
 * @property int    $status        0-关闭 1-灰度中 2-全量
 * @property string $create_time
 * @property string $update_time
 */
class GrayRelease extends Model
{
    protected $name = 'gray_release';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    // 状态
    const STATUS_CLOSED = 0;
    const STATUS_GRAY   = 1;
    const STATUS_FULL   = 2;

    /**
     * 白名单获取器 - JSON 解码
     */
    public function getWhiteListAttr($value): array
    {
        return json_decode($value, true) ?: [];
    }

    public function setWhiteListAttr($value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 黑名单获取器
     */
    public function getBlackListAttr($value): array
    {
        return json_decode($value, true) ?: [];
    }

    public function setBlackListAttr($value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 按功能标识查询
     */
    public function scopeByFeatureKey($query, string $key)
    {
        $query->where('feature_key', $key);
    }

    /**
     * 检查用户是否在灰度范围内
     */
    public function isUserInGray(int $userId): bool
    {
        // 黑名单始终不生效
        if (in_array($userId, $this->black_list)) {
            return false;
        }
        // 白名单始终生效
        if (in_array($userId, $this->white_list)) {
            return true;
        }
        // 全量
        if ($this->getData('status') == self::STATUS_FULL) {
            return true;
        }
        // 灰度中按比例
        if ($this->getData('status') == self::STATUS_GRAY) {
            $percentage = $this->getData('percentage');
            return ($userId % 100) < $percentage;
        }
        return false;
    }
}