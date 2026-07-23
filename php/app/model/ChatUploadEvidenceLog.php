<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 售后举证上传记录模型
 * @property int    $id
 * @property int    $session_id  售后会话ID
 * @property int    $user_id     上传用户ID
 * @property string $file_url    文件URL
 * @property string $file_name   文件名
 * @property int    $file_size   文件大小(字节)
 * @property string $file_type   文件类型: image/video/document
 * @property string $description 举证说明
 * @property string $create_time
 */
class ChatUploadEvidenceLog extends Model
{
    protected $name = 'chat_upload_evidence_log';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const TYPE_IMAGE    = 'image';
    const TYPE_VIDEO    = 'video';
    const TYPE_DOCUMENT = 'document';

    public function scopeBySession($query, int $sessionId)
    {
        $query->where('session_id', $sessionId);
    }

    public function scopeByUser($query, int $userId)
    {
        $query->where('user_id', $userId);
    }
}
