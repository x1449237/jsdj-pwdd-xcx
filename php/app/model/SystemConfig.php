<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 系统配置模型（热更新）
 * @property int    $id
 * @property string $key           配置键
 * @property string $value         配置值
 * @property string $type          类型 string/int/json/bool
 * @property string $group         分组
 * @property string $description   描述
 * @property string $create_time
 * @property string $update_time
 */
class SystemConfig extends Model
{
    protected $name = 'system_config';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    /**
     * 配置值获取器 - 按类型转换
     */
    public function getValueAttr($value, $data)
    {
        $type = $data['type'] ?? 'string';
        switch ($type) {
            case 'int':
                return (int) $value;
            case 'json':
                return json_decode($value, true) ?: [];
            case 'bool':
                return $value === 'true' || $value === '1';
            default:
                return (string) $value;
        }
    }

    /**
     * 配置值修改器
     */
    public function setValueAttr($value, $data)
    {
        $type = $data['type'] ?? 'string';
        if ($type === 'json' && is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        if ($type === 'bool') {
            return $value ? 'true' : 'false';
        }
        return (string) $value;
    }

    /**
     * 按键查询
     */
    public function scopeByKey($query, string $key)
    {
        $query->where('key', $key);
    }

    /**
     * 按分组查询
     */
    public function scopeByGroup($query, string $group)
    {
        $query->where('group', $group);
    }

    /**
     * 获取单个配置值
     */
    public static function getValue(string $key, $default = null)
    {
        $config = self::where('key', $key)->find();
        return $config ? $config->value : $default;
    }
}