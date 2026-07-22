<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 敏感词模型
 * @property int    $id
 * @property string $word
 * @property int    $level         1-敏感 2-禁止 3-替换
 * @property string $replacement   替换词
 * @property int    $status        0-禁用 1-正常
 * @property string $create_time
 * @property string $update_time
 */
class SensitiveWord extends Model
{
    protected $name = 'sensitive_word';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    // 级别
    const LEVEL_SENSITIVE  = 1;
    const LEVEL_FORBIDDEN  = 2;
    const LEVEL_REPLACE    = 3;

    const STATUS_DISABLED = 0;
    const STATUS_ENABLED  = 1;

    /**
     * 查询正常状态
     */
    public function scopeEnabled($query)
    {
        $query->where('status', self::STATUS_ENABLED);
    }

    /**
     * 按级别查询
     */
    public function scopeByLevel($query, int $level)
    {
        $query->where('level', $level);
    }
}