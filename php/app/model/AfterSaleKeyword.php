<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 售后关键词模型
 * @property int    $id
 * @property string $keyword
 * @property int    $status       0-禁用 1-启用
 * @property string $create_time
 * @property string $update_time
 */
class AfterSaleKeyword extends Model
{
    protected $name = 'after_sale_keyword';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const STATUS_DISABLED = 0;
    const STATUS_ENABLED  = 1;
}