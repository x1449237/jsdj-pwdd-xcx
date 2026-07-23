<?php
declare(strict_types=1);

namespace app\service;

use app\model\ChatFileMessage;
use think\facade\Log;

/**
 * 文件消息服务
 * 负责文件上传下载、大小校验、类型限制
 */
class ChatFileService
{
    /**
     * 私聊/售后文件大小限制（字节）- 10M
     */
    private const MAX_SIZE_PRIVATE = 10485760;

    /**
     * 群聊文件大小限制（字节）- 5M
     */
    private const MAX_SIZE_GROUP = 5242880;

    /**
     * 允许的图片类型
     */
    private const ALLOWED_IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    /**
     * 允许的文档类型
     */
    private const ALLOWED_DOCUMENT_EXT = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'zip', 'rar', '7z'];

    /**
     * 上传文件
     * @param int    $sessionId   会话ID
     * @param int    $sessionType 会话类型
     * @param int    $senderId    发送者ID
     * @param int    $messageId   关联消息ID
     * @param string $fileUrl     文件URL
     * @param string $fileName    文件名
     * @param int    $fileSize    文件大小(字节)
     * @param string $fileType    文件类型: image/document/screenshot
     * @return array
     * @throws \RuntimeException
     */
    public function uploadFile(
        int $sessionId,
        int $sessionType,
        int $senderId,
        int $messageId,
        string $fileUrl,
        string $fileName,
        int $fileSize,
        string $fileType
    ): array {
        $maxSize = $sessionType === ChatFileMessage::SESSION_TYPE_GROUP
            ? self::MAX_SIZE_GROUP
            : self::MAX_SIZE_PRIVATE;

        if ($fileSize > $maxSize) {
            $maxSizeMb = round($maxSize / 1048576, 1);
            throw new \RuntimeException("文件大小不能超过{$maxSizeMb}M");
        }

        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if ($fileType === ChatFileMessage::TYPE_IMAGE || $fileType === ChatFileMessage::TYPE_SCREENSHOT) {
            if (!in_array($fileExt, self::ALLOWED_IMAGE_EXT)) {
                throw new \RuntimeException('不支持的图片格式');
            }
        } elseif ($fileType === ChatFileMessage::TYPE_DOCUMENT) {
            if (!in_array($fileExt, self::ALLOWED_DOCUMENT_EXT)) {
                throw new \RuntimeException('不支持的文档格式');
            }
        }

        $fileMessage = ChatFileMessage::create([
            'session_id'   => $sessionId,
            'session_type' => $sessionType,
            'sender_id'    => $senderId,
            'message_id'   => $messageId,
            'file_name'    => $fileName,
            'file_size'    => $fileSize,
            'file_url'     => $fileUrl,
            'file_type'    => $fileType,
            'file_ext'     => $fileExt,
        ]);

        write_action_log('chat_file_upload', "上传文件: session_id={$sessionId}, sender_id={$senderId}, file_type={$fileType}");

        return $fileMessage->toArray();
    }

    /**
     * 获取会话文件列表
     * @param int $sessionId
     * @param int $sessionType
     * @param int $page
     * @param int $limit
     * @param string|null $fileType
     * @return array
     */
    public function getSessionFiles(int $sessionId, int $sessionType, int $page, int $limit, ?string $fileType = null): array
    {
        $query = ChatFileMessage::where('session_id', $sessionId)
            ->where('session_type', $sessionType);

        if ($fileType) {
            $query->where('file_type', $fileType);
        }

        $total = $query->count();
        $list  = $query->order('id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        return ['list' => $list, 'total' => $total];
    }

    /**
     * 获取文件详情
     * @param int $fileId
     * @return array|null
     */
    public function getFileInfo(int $fileId): ?array
    {
        $file = ChatFileMessage::find($fileId);
        return $file ? $file->toArray() : null;
    }

    /**
     * 格式化文件大小
     * @param int $bytes
     * @return string
     */
    public function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * 获取文件类型图标
     * @param string $fileExt
     * @return string
     */
    public function getFileIcon(string $fileExt): string
    {
        $ext = strtolower($fileExt);

        $iconMap = [
            'pdf'  => '📄',
            'doc'  => '📝',
            'docx' => '📝',
            'xls'  => '📊',
            'xlsx' => '📊',
            'ppt'  => '📽️',
            'pptx' => '📽️',
            'txt'  => '📃',
            'zip'  => '📦',
            'rar'  => '📦',
            '7z'   => '📦',
            'jpg'  => '🖼️',
            'jpeg' => '🖼️',
            'png'  => '🖼️',
            'gif'  => '🖼️',
            'webp' => '🖼️',
            'bmp'  => '🖼️',
        ];

        return $iconMap[$ext] ?? '📁';
    }

    /**
     * 根据文件扩展名判断文件类型
     * @param string $fileName
     * @return string
     */
    public function detectFileType(string $fileName): string
    {
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if (in_array($ext, self::ALLOWED_IMAGE_EXT)) {
            return ChatFileMessage::TYPE_IMAGE;
        }

        return ChatFileMessage::TYPE_DOCUMENT;
    }

    /**
     * 检查文件大小限制
     * @param int $sessionType
     * @return int 最大文件大小（字节）
     */
    public function getMaxFileSize(int $sessionType): int
    {
        return $sessionType === ChatFileMessage::SESSION_TYPE_GROUP
            ? self::MAX_SIZE_GROUP
            : self::MAX_SIZE_PRIVATE;
    }
}
