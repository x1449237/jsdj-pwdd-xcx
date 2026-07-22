<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 售后消息模型
 * @property int    $id
 * @property int    $session_id
 * @property int    $sender_id
 * @property int    $sender_type  0-用户 1-平台官方账号
 * @property int    $msg_type     0-文字 1-语音 2-图片
 * @property string $content
 * @property int    $status       0-正常 1-隐藏
 * @property string $create_time
 */
class AfterSaleMessage extends Model
{
    protected $name = 'after_sale_message';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';
    protected $updateTime = false;

    const TYPE_TEXT  = 0;
    const TYPE_VOICE = 1;
    const TYPE_IMAGE = 2;

    const STATUS_NORMAL = 0;
    const STATUS_HIDDEN = 1;

    const SENDER_USER     = 0;
    const SENDER_PLATFORM = 1;
}