<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 语音ASR缓存模型
 * @property int    $id
 * @property int    $message_id   消息ID
 * @property int    $session_id   会话ID
 * @property int    $session_type 会话类型: 1私聊 2群聊 3售后
 * @property string $voice_url    语音文件URL
 * @property string $asr_text     ASR转文字结果
 * @property float  $confidence   置信度
 * @property string $provider     ASR服务商
 * @property string $create_time
 */
class ChatAsrCache extends Model
{
    protected $name = 'chat_asr_cache';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const SESSION_TYPE_PRIVATE   = 1;
    const SESSION_TYPE_GROUP     = 2;
    const SESSION_TYPE_AFTERSALE = 3;

    public function scopeByMessage($query, int $messageId)
    {
        $query->where('message_id', $messageId);
    }

    public function scopeBySession($query, int $sessionId, int $sessionType)
    {
        $query->where('session_id', $sessionId)->where('session_type', $sessionType);
    }
}
