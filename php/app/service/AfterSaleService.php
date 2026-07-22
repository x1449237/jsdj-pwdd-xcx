<?php
declare(strict_types=1);

namespace app\service;

use app\model\AfterSaleInterveneLog;
use app\model\AfterSaleKeyword;
use app\model\AfterSaleMessage;
use app\model\AfterSaleSession;
use app\model\PlatformAccount;
use app\model\RiskControlLog;
use app\model\SystemConfig;
use think\facade\Db;
use think\facade\Log;

/**
 * 售后会话服务
 */
class AfterSaleService
{
    /**
     * 创建售后申诉会话
     * @param int    $orderId
     * @param int    $userId
     * @param string $reason
     * @param array  $images
     * @return array
     * @throws \RuntimeException
     */
    public function createSession(int $orderId, int $userId, string $reason, array $images): array
    {
        // 获取平台官方账号
        $platformAccount = PlatformAccount::where('status', PlatformAccount::STATUS_ENABLED)
            ->order('id', 'asc')
            ->find();

        $session = AfterSaleSession::create([
            'order_id'             => $orderId,
            'user_id'              => $userId,
            'platform_account_id'  => $platformAccount ? $platformAccount->id : 0,
            'reason'               => $reason,
            'images'               => $images,
            'status'               => AfterSaleSession::STATUS_OPEN,
            'risk_level'           => AfterSaleSession::RISK_NORMAL,
            'is_intervened'        => 0,
        ]);

        write_action_log('after_sale_create_session', "创建售后会话: 订单ID: {$orderId}, 用户ID: {$userId}, 会话ID: {$session->id}");

        return $session->toArray();
    }

    /**
     * 发送售后消息
     * @param int    $sessionId
     * @param int    $senderId
     * @param int    $senderType
     * @param int    $msgType
     * @param string $content
     * @return array
     * @throws \RuntimeException
     */
    public function sendMessage(int $sessionId, int $senderId, int $senderType, int $msgType, string $content): array
    {
        $session = AfterSaleSession::find($sessionId);
        if (!$session) {
            throw new \RuntimeException('售后会话不存在');
        }

        if ($session->status == AfterSaleSession::STATUS_CLOSED) {
            throw new \RuntimeException('售后会话已关闭');
        }

        $message = AfterSaleMessage::create([
            'session_id'  => $sessionId,
            'sender_id'   => $senderId,
            'sender_type' => $senderType,
            'msg_type'    => $msgType,
            'content'     => $content,
            'status'      => AfterSaleMessage::STATUS_NORMAL,
        ]);

        // 同步检查售后关键词
        if ($msgType == AfterSaleMessage::TYPE_TEXT) {
            $hitKeywords = $this->filterAfterSaleKeywords($content);
            if (!empty($hitKeywords)) {
                $switchOn = $this->checkKeywordSwitch();
                if ($switchOn) {
                    $this->triggerAutoIntervene($sessionId, $hitKeywords, $senderId);
                }
            }
        }

        return $message->toArray();
    }

    /**
     * 检查消息是否命中售后风控关键词
     * @param string $content
     * @return array
     */
    public function filterAfterSaleKeywords(string $content): array
    {
        try {
            $keywords = AfterSaleKeyword::where('status', AfterSaleKeyword::STATUS_ENABLED)
                ->column('keyword');

            $hitList = [];
            foreach ($keywords as $keyword) {
                if (!empty($keyword) && mb_strpos($content, $keyword) !== false) {
                    $hitList[] = $keyword;
                }
            }

            return $hitList;
        } catch (\Throwable $e) {
            Log::error('售后关键词匹配异常: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * 触发关键词自动介入
     * @param int   $sessionId
     * @param array $hitKeywords
     * @param int   $senderId
     * @return bool
     */
    public function triggerAutoIntervene(int $sessionId, array $hitKeywords, int $senderId): bool
    {
        Db::startTrans();
        try {
            // 写风控日志
            RiskControlLog::create([
                'user_id'    => $senderId,
                'event'      => 'after_sale_keyword_hit',
                'risk_level' => 'high',
                'detail'     => [
                    'session_id'   => $sessionId,
                    'hit_keywords' => $hitKeywords,
                ],
                'result'     => RiskControlLog::RESULT_REVIEW,
            ]);

            // 标记会话为高风险
            $session = AfterSaleSession::find($sessionId);
            if ($session) {
                $session->risk_level    = AfterSaleSession::RISK_HIGH;
                $session->is_intervened = 1;
                $session->save();
            }

            // 记录介入日志
            AfterSaleInterveneLog::create([
                'session_id'   => $sessionId,
                'operator_id'  => 0,
                'hit_keywords' => $hitKeywords,
                'action'       => 'auto_intervene',
                'result'       => 'triggered',
            ]);

            Db::commit();

            write_action_log('after_sale_auto_intervene', "售后关键词自动介入: 会话ID: {$sessionId}, 命中关键词: " . implode(',', $hitKeywords));

            return true;
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('自动介入触发失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 人工申请平台介入
     * @param int $sessionId
     * @param int $userId
     * @param int $initiatorType
     * @return bool
     * @throws \RuntimeException
     */
    public function requestManualIntervene(int $sessionId, int $userId, int $initiatorType): bool
    {
        $session = AfterSaleSession::find($sessionId);
        if (!$session) {
            throw new \RuntimeException('售后会话不存在');
        }

        if ($session->is_intervened) {
            throw new \RuntimeException('平台已介入');
        }

        $session->is_intervened = 1;
        $session->save();

        AfterSaleInterveneLog::create([
            'session_id'   => $sessionId,
            'operator_id'  => $userId,
            'hit_keywords' => [],
            'action'       => 'manual_intervene',
            'result'       => 'pending',
        ]);

        write_action_log('after_sale_manual_intervene', "人工申请平台介入: 会话ID: {$sessionId}, 用户ID: {$userId}");

        return true;
    }

    /**
     * 解除平台介入（仅超级管理员）
     * @param int    $sessionId
     * @param int    $operatorId
     * @param string $result
     * @param string $action
     * @return bool
     * @throws \RuntimeException
     */
    public function resolveIntervene(int $sessionId, int $operatorId, string $result, string $action): bool
    {
        $session = AfterSaleSession::find($sessionId);
        if (!$session) {
            throw new \RuntimeException('售后会话不存在');
        }

        if (!$session->is_intervened) {
            throw new \RuntimeException('该会话未被介入');
        }

        $session->is_intervened = 0;
        $session->risk_level    = AfterSaleSession::RISK_NORMAL;
        $session->save();

        AfterSaleInterveneLog::create([
            'session_id'   => $sessionId,
            'operator_id'  => $operatorId,
            'hit_keywords' => [],
            'action'       => $action,
            'result'       => $result,
        ]);

        write_action_log('after_sale_resolve_intervene', "解除平台介入: 会话ID: {$sessionId}, 操作者ID: {$operatorId}, 结果: {$result}");

        return true;
    }

    /**
     * 获取售后消息列表
     * @param int $sessionId
     * @param int $page
     * @param int $limit
     * @return array
     */
    public function getMessages(int $sessionId, int $page, int $limit): array
    {
        $query = AfterSaleMessage::where('session_id', $sessionId)
            ->where('status', AfterSaleMessage::STATUS_NORMAL)
            ->order('create_time', 'asc');

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        return ['list' => $list, 'total' => $total];
    }

    /**
     * 获取我的售后会话列表
     * @param int $userId
     * @return array
     */
    public function getMySessions(int $userId): array
    {
        return AfterSaleSession::where('user_id', $userId)
            ->order('id', 'desc')
            ->select()
            ->toArray();
    }

    /**
     * 获取售后会话详情
     * @param int $sessionId
     * @return array|null
     */
    public function getSessionDetail(int $sessionId): ?array
    {
        $session = AfterSaleSession::find($sessionId);
        return $session ? $session->toArray() : null;
    }

    /**
     * 检查售后关键词总开关是否开启
     * @return bool
     */
    public function checkKeywordSwitch(): bool
    {
        $value = SystemConfig::getValue('after_sale_keyword_switch', '0');
        return $value === '1' || $value === 'true' || $value === true;
    }
}