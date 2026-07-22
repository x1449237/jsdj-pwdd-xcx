<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 平台官方账号模型
 * @property int    $id
 * @property string $nickname
 * @property string $avatar
 * @property int    $status        0-停用 1-启用
 * @property int    $creator_id
 * @property string $create_time
 * @property string $update_time
 */
class PlatformAccount extends Model
{
    protected $name = 'platform_account';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const STATUS_DISABLED = 0;
    const STATUS_ENABLED  = 1;

    public function scopeEnabled($query)
    {
        $query->where('status', self::STATUS_ENABLED);
    }
}