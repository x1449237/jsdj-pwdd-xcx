<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 申诉沟通模型
 * @property int    $id
 * @property int    $appeal_id
 * @property int    $sender_id     发送者
 * @property int    $sender_type   1-管理员 2-用户
 * @property string $content
 * @property string $attachments   附件 JSON
 * @property string $create_time
 */
class AppealCommunication extends Model
{
    protected $name = 'appeal_communication';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';
    protected $updateTime = false;

    // 发送者类型
    const SENDER_ADMIN = 1;
    const SENDER_USER  = 2;

    /**
     * 附件获取器 - JSON 解码
     */
    public function getAttachmentsAttr($value): array
    {
        return json_decode($value, true) ?: [];
    }

    public function setAttachmentsAttr($value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 关联申诉
     */
    public function appeal()
    {
        return $this->belongsTo(PhoneAppeal::class, 'appeal_id', 'id');
    }

    /**
     * 按申诉查询
     */
    public function scopeByAppeal($query, int $appealId)
    {
        $query->where('appeal_id', $appealId);
    }

    /**
     * 按时间正序
     */
    public function scopeOldest($query)
    {
        $query->order('create_time', 'asc');
    }
}