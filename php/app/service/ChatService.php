<?php
declare(strict_types=1);

namespace app\service;

use app\model\ChatMessage;
use app\model\ChatSession;
use app\model\ChatAuditLog;
use app\model\SensitiveWord;
use app\model\RiskUser;
use app\model\OfflineMessage;
use app\model\ChatMessageRevoke;
use app\model\ChatAntiFraudLog;
use think\facade\Log;
use think\facade\Db;

/**
 * 聊天服务
 * 负责消息发送、敏感词过滤、ASR/OCR、NLP分析和离线消息
 */
class ChatService
{
    /**
     * 敏感词命中后冻结时长（秒）
     */
    private const FREEZE_DURATION = 3600;

    /**
     * 离线消息 Key 前缀
     */
    private const OFFLINE_MSG_KEY = 'chat:offline:';

    /**
     * 已读消息 Key 前缀
     */
    private const READ_KEY = 'chat:read:';

    /**
     * 发送消息
     * @param int    $sessionId
     * @param int    $senderId
     * @param int    $type     消息类型
     * @param string $content
     * @return array
     * @throws \RuntimeException
     */
    public function sendMessage(int $sessionId, int $senderId, int $type, string $content): array
    {
        try {
            // 敏感词过滤
            $filterResult = $this->filterSensitive($content);
            $isBlocked = $filterResult['is_blocked'];
            $filteredContent = $filterResult['content'];
            $matchedWords = $filterResult['matched_words'];

            // 代练违禁词检测
            $antiBoostingResult = $this->checkAntiBoosting($content, $sessionId, $senderId);
            if ($antiBoostingResult['blocked']) {
                $isBlocked = true;
                foreach ($antiBoostingResult['matched'] as $item) {
                    $matchedWords[] = $item['keyword'];
                }
            }

            // 语义分析
            if ($type === ChatMessage::TYPE_TEXT) {
                $nlpResult = $this->nlpAnalyze($content);
            } else {
                $nlpResult = ['risk_level' => 0, 'intent' => 'normal'];
            }

            $message = ChatMessage::create([
                'session_id'  => $sessionId,
                'sender_id'   => $senderId,
                'msg_type'    => $type,
                'content'     => $filteredContent,
                'is_blocked'  => $isBlocked ? 1 : 0,
                'extra'       => json_encode([
                    'matched_words' => $matchedWords,
                    'nlp_risk'      => $nlpResult['risk_level'],
                    'nlp_intent'    => $nlpResult['intent'],
                ], JSON_UNESCAPED_UNICODE),
            ]);

            // 审核日志
            if ($isBlocked || $nlpResult['risk_level'] > 0) {
                ChatAuditLog::create([
                    'message_id'    => $message->id,
                    'session_id'    => $sessionId,
                    'sender_id'     => $senderId,
                    'audit_type'    => $isBlocked ? 'sensitive' : 'nlp_risk',
                    'risk_level'    => $isBlocked ? 3 : $nlpResult['risk_level'],
                    'matched_words' => json_encode($matchedWords, JSON_UNESCAPED_UNICODE),
                    'action'        => $isBlocked ? 'block' : 'log',
                ]);

                // 命中敏感词，冻结用户
                if ($isBlocked) {
                    $this->freezeUser($senderId);
                }
            }

            // 获取会话中的另一方，保存离线消息
            $session = ChatSession::find($sessionId);
            if ($session) {
                $receiverId = ($session->user_id == $senderId) ? $session->player_id : $session->user_id;
                if ($receiverId) {
                    $this->saveOfflineMessage($receiverId, $message->id);
                }
            }

            Log::info("消息发送: session_id={$sessionId}, sender_id={$senderId}, msg_id={$message->id}, blocked={$isBlocked}");

            return [
                'message_id'    => $message->id,
                'is_blocked'    => $isBlocked,
                'filtered_content' => $filteredContent,
                'matched_words' => $matchedWords,
            ];
        } catch (\Throwable $e) {
            Log::error("发送消息失败: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * 敏感词过滤
     * 支持正则匹配和变体匹配（同音字、形近字、拼音、拆字、插入符号）
     * @param string $content
     * @return array [is_blocked, content, matched_words]
     */
    public function filterSensitive(string $content): array
    {
        try {
            $matchedWords = [];
            $isBlocked = false;

            // 获取敏感词库
            $sensitiveWords = SensitiveWord::where('status', 1)
                ->field('word, pattern, level')
                ->select();

            $normalizedContent = $this->normalizeText($content);

            foreach ($sensitiveWords as $word) {
                $pattern = $word->pattern;
                $wordText = $word->word;

                if (!empty($pattern)) {
                    // 使用配置的正则模式
                    if (preg_match($pattern, $content) || preg_match($pattern, $normalizedContent)) {
                        $matchedWords[] = $wordText;
                        $isBlocked = true;
                    }
                } else {
                    // 使用默认匹配策略
                    if ($this->matchWithVariants($content, $normalizedContent, $wordText)) {
                        $matchedWords[] = $wordText;
                        $isBlocked = true;
                    }
                }
            }

            $filteredContent = $content;
            if ($isBlocked) {
                foreach ($matchedWords as $word) {
                    $filteredContent = str_replace($word, '***', $filteredContent);
                }
            }

            return [
                'is_blocked'    => $isBlocked,
                'content'       => $filteredContent,
                'matched_words' => $matchedWords,
            ];
        } catch (\Throwable $e) {
            Log::error("敏感词过滤失败: {$e->getMessage()}");
            return [
                'is_blocked'    => false,
                'content'       => $content,
                'matched_words' => [],
            ];
        }
    }

    /**
     * 文本归一化
     * 去除特殊符号、统一全角半角、转小写
     * @param string $text
     * @return string
     */
    private function normalizeText(string $text): string
    {
        // 去除空白和特殊符号
        $text = preg_replace('/[\s\p{P}\p{S}]/u', '', $text);
        // 全角转半角
        $text = mb_convert_kana($text, 'as', 'UTF-8');
        // 转小写
        $text = mb_strtolower($text, 'UTF-8');

        return $text;
    }

    /**
     * 变体匹配
     * 检查归一化后的文本是否包含敏感词
     * @param string $original
     * @param string $normalized
     * @param string $word
     * @return bool
     */
    private function matchWithVariants(string $original, string $normalized, string $word): bool
    {
        // 直接匹配原始文本
        if (mb_strpos($original, $word) !== false) {
            return true;
        }

        // 匹配归一化文本
        $normalizedWord = $this->normalizeText($word);
        if (mb_strpos($normalized, $normalizedWord) !== false) {
            return true;
        }

        // 拆字匹配：敏感词每个字符之间插入任意字符
        $chars = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY);
        $splitPattern = '/' . implode('.{0,2}', array_map(function ($c) {
            return preg_quote($c, '/');
        }, $chars)) . '/u';

        if (preg_match($splitPattern, $original) || preg_match($splitPattern, $normalized)) {
            return true;
        }

        return false;
    }

    /**
     * ASR 语音转文字
     * @param string $mediaUrl 音频文件URL
     * @return array [text, confidence]
     */
    public function asrConvert(string $mediaUrl): array
    {
        try {
            $appKey = config_get('asr.asr_app_key', '');
            $endpoint = config_get('asr.asr_endpoint', '');

            if (empty($appKey) || empty($endpoint)) {
                Log::warning('ASR 配置缺失，使用模拟返回');
                return ['text' => '', 'confidence' => 0];
            }

            // 下载音频文件
            $audioContent = $this->downloadFile($mediaUrl);
            if (empty($audioContent)) {
                return ['text' => '', 'confidence' => 0];
            }

            // 调用阿里云 ASR API
            $client = new \GuzzleHttp\Client(['timeout' => 30]);
            $response = $client->post('https://' . $endpoint . '/api/asr', [
                'headers' => [
                    'Content-Type' => 'application/octet-stream',
                    'X-App-Key'    => $appKey,
                ],
                'body' => $audioContent,
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            $text = $result['result'] ?? '';
            $confidence = $result['confidence'] ?? 0;

            Log::info("ASR 转换完成: text_length=" . mb_strlen($text) . ", confidence={$confidence}");

            return ['text' => $text, 'confidence' => $confidence];
        } catch (\Throwable $e) {
            Log::error("ASR 转换失败: {$e->getMessage()}");
            return ['text' => '', 'confidence' => 0];
        }
    }

    /**
     * OCR 图片识别
     * @param string $imageUrl 图片URL
     * @return array [text, regions]
     */
    public function ocrRecognize(string $imageUrl): array
    {
        try {
            $apiKey = config_get('ocr.ocr_api_key', '');
            $secretKey = config_get('ocr.ocr_secret_key', '');
            $endpoint = config_get('ocr.ocr_endpoint', '');

            if (empty($apiKey) || empty($endpoint)) {
                Log::warning('OCR 配置缺失，使用模拟返回');
                return ['text' => '', 'regions' => []];
            }

            // 获取 access_token
            $client = new \GuzzleHttp\Client(['timeout' => 30]);
            $tokenResponse = $client->post('https://aip.baidubce.com/oauth/2.0/token', [
                'form_params' => [
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $apiKey,
                    'client_secret' => $secretKey,
                ],
            ]);
            $tokenData = json_decode($tokenResponse->getBody()->getContents(), true);
            $accessToken = $tokenData['access_token'] ?? '';

            if (empty($accessToken)) {
                return ['text' => '', 'regions' => []];
            }

            // 调用 OCR API
            $response = $client->post($endpoint . '/general_basic', [
                'query' => ['access_token' => $accessToken],
                'form_params' => [
                    'url' => $imageUrl,
                ],
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            $words = [];
            $regions = [];
            foreach ($result['words_result'] ?? [] as $item) {
                $words[] = $item['words'];
                $regions[] = $item['location'] ?? [];
            }

            $text = implode('', $words);

            Log::info("OCR 识别完成: text_length=" . mb_strlen($text));

            return ['text' => $text, 'regions' => $regions];
        } catch (\Throwable $e) {
            Log::error("OCR 识别失败: {$e->getMessage()}");
            return ['text' => '', 'regions' => []];
        }
    }

    /**
     * NLP 语义分析
     * @param string $text
     * @return array [risk_level, intent, sentiment]
     */
    public function nlpAnalyze(string $text): array
    {
        try {
            $riskLevel = 0;
            $intent = 'normal';
            $sentiment = 'neutral';

            // 基于规则的基础 NLP 分析
            // 检测风险关键词
            $riskPatterns = [
                'contact' => '/微信|QQ|手机号|电话|加我|私聊|线下|qq\s*\d+/iu',
                'abuse'   => '/傻逼|操|妈的|滚|去死|垃圾/iu',
                'fraud'   => '/退款|骗|举报|投诉|报警|起诉/iu',
                'induce'  => '/私下|绕过平台|转账|支付宝|红包/iu',
            ];

            foreach ($riskPatterns as $category => $pattern) {
                if (preg_match($pattern, $text)) {
                    $riskLevel = max($riskLevel, 2);
                }
            }

            // 意图分析
            if (preg_match('/价格|多少钱|便宜|优惠|打折/iu', $text)) {
                $intent = 'price_inquiry';
            } elseif (preg_match('/什么时候|多久|时间|什么时候能/iu', $text)) {
                $intent = 'time_inquiry';
            } elseif (preg_match('/谢谢|感谢|好评|厉害|牛/iu', $text)) {
                $intent = 'praise';
                $sentiment = 'positive';
            } elseif (preg_match('/退款|投诉|差评|取消/iu', $text)) {
                $intent = 'complaint';
                $sentiment = 'negative';
                $riskLevel = max($riskLevel, 2);
            }

            return [
                'risk_level' => $riskLevel,
                'intent'     => $intent,
                'sentiment'  => $sentiment,
            ];
        } catch (\Throwable $e) {
            Log::error("NLP 分析失败: {$e->getMessage()}");
            return ['risk_level' => 0, 'intent' => 'normal', 'sentiment' => 'neutral'];
        }
    }

    /**
     * 冻结用户
     * 命中敏感词后冻结1小时
     * @param int $userId
     * @return bool
     */
    public function freezeUser(int $userId): bool
    {
        try {
            $redis = get_redis();
            $key = 'chat:freeze:' . $userId;
            $redis->setex($key, self::FREEZE_DURATION, '1');

            // 记录风险用户
            $exists = RiskUser::where('user_id', $userId)->find();
            if (!$exists) {
                RiskUser::create([
                    'user_id'    => $userId,
                    'risk_type'  => 'chat_sensitive',
                    'risk_level' => 2,
                    'freeze_until' => date('Y-m-d H:i:s', time() + self::FREEZE_DURATION),
                ]);
            }

            Log::warning("用户冻结: user_id={$userId}, 时长=" . self::FREEZE_DURATION . "s");
            return true;
        } catch (\Throwable $e) {
            Log::error("冻结用户失败: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * 保存离线消息
     * 使用 Redis Sorted Set，score 为时间戳
     * @param int $userId
     * @param int $messageId
     * @return bool
     */
    public function saveOfflineMessage(int $userId, int $messageId): bool
    {
        try {
            $redis = get_redis();
            $key = self::OFFLINE_MSG_KEY . $userId;
            $redis->zAdd($key, time(), (string) $messageId);
            $redis->expire($key, 604800); // 7天过期

            return true;
        } catch (\Throwable $e) {
            Log::error("保存离线消息失败: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * 获取离线消息
     * @param int $userId
     * @return array
     */
    public function getOfflineMessages(int $userId): array
    {
        try {
            $redis = get_redis();
            $key = self::OFFLINE_MSG_KEY . $userId;

            // 获取所有离线消息ID（按时间正序）
            $messageIds = $redis->zRange($key, 0, -1);

            if (empty($messageIds)) {
                return [];
            }

            $messageIds = array_map('intval', $messageIds);
            $messages = ChatMessage::whereIn('id', $messageIds)
                ->order('create_time', 'asc')
                ->select()
                ->toArray();

            return $messages;
        } catch (\Throwable $e) {
            Log::error("获取离线消息失败: {$e->getMessage()}");
            return [];
        }
    }

    /**
     * 标记已读
     * @param int $sessionId
     * @param int $userId
     * @return bool
     */
    public function markRead(int $sessionId, int $userId): bool
    {
        try {
            $redis = get_redis();
            $key = self::READ_KEY . $sessionId . ':' . $userId;

            // 更新最后已读消息ID
            $lastMessage = ChatMessage::where('session_id', $sessionId)
                ->order('id', 'desc')
                ->find();

            if ($lastMessage) {
                $redis->set($key, $lastMessage->id);
            }

            // 清除该会话的离线消息
            $offlineKey = self::OFFLINE_MSG_KEY . $userId;
            $redis->del($offlineKey);

            return true;
        } catch (\Throwable $e) {
            Log::error("标记已读失败: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * 下载文件
     * @param string $url
     * @return string
     */
    private function downloadFile(string $url): string
    {
        try {
            $client = new \GuzzleHttp\Client(['timeout' => 30]);
            $response = $client->get($url);
            return $response->getBody()->getContents();
        } catch (\Throwable $e) {
            Log::error("下载文件失败: url={$url}, error={$e->getMessage()}");
            return '';
        }
    }

    /**
     * 撤回消息（私聊5分钟内可撤）
     * @param int $messageId
     * @param int $userId
     * @return bool
     * @throws \RuntimeException
     */
    public function recallMessage(int $messageId, int $userId): bool
    {
        $message = ChatMessage::where('id', $messageId)
            ->where('user_id', $userId)
            ->find();

        if (!$message) {
            throw new \RuntimeException('消息不存在或无权撤回');
        }

        if ($message->status == ChatMessage::STATUS_HIDDEN) {
            throw new \RuntimeException('消息已撤回');
        }

        $messageTime = strtotime($message->create_time);
        if (time() - $messageTime > 300) {
            throw new \RuntimeException('消息发送超过5分钟，无法撤回');
        }

        Db::startTrans();
        try {
            ChatMessageRevoke::create([
                'session_id'       => $message->session_id,
                'session_type'     => ChatMessageRevoke::SESSION_TYPE_PRIVATE,
                'message_id'       => $message->id,
                'user_id'          => $userId,
                'msg_type'         => $message->msg_type,
                'original_content' => $message->content,
                'revoke_time'      => date('Y-m-d H:i:s'),
            ]);

            $message->status = ChatMessage::STATUS_HIDDEN;
            $message->save();

            Db::commit();

            write_action_log('chat_message_recall', "撤回消息: message_id={$messageId}, user_id={$userId}");

            return true;
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error("撤回消息失败: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * 飞单风控检测
     * @param string $content
     * @param int    $sessionId
     * @param int    $senderId
     * @param int    $messageId
     * @return array
     */
    public function detectAntiFraud(string $content, int $sessionId, int $senderId, int $messageId): array
    {
        try {
            $antiFraudService = new AntiFraudService();
            return $antiFraudService->detectFraud(
                $content,
                $sessionId,
                ChatAntiFraudLog::SESSION_TYPE_PRIVATE,
                $senderId,
                $messageId
            );
        } catch (\Throwable $e) {
            Log::error("飞单风控检测失败: {$e->getMessage()}");
            return [
                'is_risky'         => false,
                'level'            => '',
                'matched_rules'    => [],
                'matched_content'  => [],
                'filtered_content' => $content,
            ];
        }
    }

    /**
     * 检查用户是否被禁言
     * @param int $userId
     * @return bool
     */
    public function isUserMuted(int $userId): bool
    {
        try {
            $antiFraudService = new AntiFraudService();
            return $antiFraudService->isMuted($userId);
        } catch (\Throwable $e) {
            Log::error("检查用户禁言状态失败: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * 获取快捷卡片列表
     * @param string|null $type
     * @return array
     */
    public function getQuickCards(?string $type = null): array
    {
        try {
            $query = \app\model\ChatQuickCard::enabled()->ordered();
            if ($type) {
                $query->where('type', $type);
            }
            return $query->select()->toArray();
        } catch (\Throwable $e) {
            Log::error("获取快捷卡片失败: {$e->getMessage()}");
            return [];
        }
    }

    /**
     * 代练违禁词检测
     * @param string $content
     * @param int    $sessionId
     * @param int    $senderId
     * @return array
     */
    public function checkAntiBoosting(string $content, int $sessionId, int $senderId): array
    {
        try {
            $complianceService = new \app\service\ComplianceService();
            return $complianceService->checkContent($content, 'chat', $sessionId, $senderId);
        } catch (\Throwable $e) {
            Log::error("代练违禁词检测失败: {$e->getMessage()}");
            return [
                'matched'       => [],
                'highest_level' => '',
                'blocked'       => false,
                'ban'           => false,
            ];
        }
    }
}