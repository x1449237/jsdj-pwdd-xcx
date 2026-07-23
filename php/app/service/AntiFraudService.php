<?php
declare(strict_types=1);

namespace app\service;

use app\model\ChatAntiFraudRule;
use app\model\ChatAntiFraudLog;
use app\model\RiskUser;
use think\facade\Db;
use think\facade\Log;

/**
 * 飞单风控服务
 * 负责飞单检测、关键词匹配、分级处理
 */
class AntiFraudService
{
    /**
     * 多次触发禁言的阈值（次数）
     */
    private const MUTE_THRESHOLD = 3;

    /**
     * 多次触发封禁的阈值（次数）
     */
    private const BAN_THRESHOLD = 5;

    /**
     * 禁言时长（秒）
     */
    private const MUTE_DURATION = 86400;

    /**
     * 检测飞单风险
     * @param string $content     消息内容
     * @param int    $sessionId   会话ID
     * @param int    $sessionType 会话类型
     * @param int    $senderId    发送者ID
     * @param int    $messageId   消息ID
     * @return array [is_risky, level, matched_rules, filtered_content]
     */
    public function detectFraud(string $content, int $sessionId, int $sessionType, int $senderId, int $messageId): array
    {
        try {
            $matchedRules = [];
            $highestLevel = '';
            $filteredContent = $content;
            $matchedContents = [];

            $rules = ChatAntiFraudRule::enabled()
                ->order('sort', 'asc')
                ->order('id', 'asc')
                ->select();

            foreach ($rules as $rule) {
                $pattern = $rule->pattern;
                if (empty($pattern)) {
                    continue;
                }

                if (preg_match($pattern, $content, $matches)) {
                    $matchedRules[] = [
                        'id'        => $rule->id,
                        'rule_type' => $rule->rule_type,
                        'rule_name' => $rule->rule_name,
                        'level'     => $rule->level,
                    ];
                    $matchedContents[] = $matches[0] ?? '';

                    if ($this->compareLevel($rule->level, $highestLevel) > 0) {
                        $highestLevel = $rule->level;
                    }

                    $filteredContent = preg_replace($pattern, '***', $filteredContent);
                }
            }

            $isRisky = !empty($matchedRules);

            if ($isRisky) {
                foreach ($matchedRules as $rule) {
                    ChatAntiFraudLog::create([
                        'session_id'      => $sessionId,
                        'session_type'    => $sessionType,
                        'sender_id'       => $senderId,
                        'message_id'      => $messageId,
                        'rule_id'         => $rule['id'],
                        'matched_content' => mb_substr(implode(',', $matchedContents), 0, 255),
                        'level'           => $highestLevel,
                        'handled'         => ChatAntiFraudLog::HANDLED_NO,
                    ]);
                }

                $this->handleRiskUser($senderId, $highestLevel);

                write_action_log('anti_fraud_detect', "检测到飞单风险: sender_id={$senderId}, session_id={$sessionId}, level={$highestLevel}");
            }

            return [
                'is_risky'        => $isRisky,
                'level'           => $highestLevel,
                'matched_rules'   => $matchedRules,
                'matched_content' => $matchedContents,
                'filtered_content' => $filteredContent,
            ];
        } catch (\Throwable $e) {
            Log::error("飞单风控检测失败: {$e->getMessage()}");
            return [
                'is_risky'        => false,
                'level'           => '',
                'matched_rules'   => [],
                'matched_content' => [],
                'filtered_content' => $content,
            ];
        }
    }

    /**
     * 处理风险用户
     * @param int    $userId
     * @param string $level
     * @return bool
     */
    private function handleRiskUser(int $userId, string $level): bool
    {
        try {
            $todayCount = ChatAntiFraudLog::where('sender_id', $userId)
                ->where('create_time', '>=', date('Y-m-d 00:00:00'))
                ->count();

            $riskLevel = 'low';
            $action = 'warning';

            if ($level === ChatAntiFraudRule::LEVEL_BAN || $todayCount >= self::BAN_THRESHOLD) {
                $riskLevel = 'high';
                $action = 'ban';
                $this->banUser($userId);
            } elseif ($level === ChatAntiFraudRule::LEVEL_MUTE || $todayCount >= self::MUTE_THRESHOLD) {
                $riskLevel = 'medium';
                $action = 'mute';
                $this->muteUser($userId);
            }

            $riskUser = RiskUser::where('user_id', $userId)->find();
            if ($riskUser) {
                $riskUser->risk_type   = 'anti_fraud';
                $riskUser->risk_level  = $riskLevel;
                $riskUser->risk_score  = min(100, $riskUser->risk_score + 20);
                $riskUser->freeze_until = $action === 'mute' || $action === 'ban' ? date('Y-m-d H:i:s', time() + self::MUTE_DURATION) : null;
                $riskUser->save();
            } else {
                RiskUser::create([
                    'user_id'      => $userId,
                    'risk_type'    => 'anti_fraud',
                    'risk_level'   => $riskLevel,
                    'risk_score'   => 20,
                    'freeze_until' => $action === 'mute' || $action === 'ban' ? date('Y-m-d H:i:s', time() + self::MUTE_DURATION) : null,
                ]);
            }

            return true;
        } catch (\Throwable $e) {
            Log::error("处理风险用户失败: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * 禁言用户
     * @param int $userId
     * @return bool
     */
    private function muteUser(int $userId): bool
    {
        try {
            $redis = get_redis();
            $key = 'chat:mute:' . $userId;
            $redis->setex($key, self::MUTE_DURATION, '1');

            Log::warning("用户禁言: user_id={$userId}, 时长=" . self::MUTE_DURATION . "s");
            return true;
        } catch (\Throwable $e) {
            Log::error("禁言用户失败: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * 封禁用户
     * @param int $userId
     * @return bool
     */
    private function banUser(int $userId): bool
    {
        try {
            $redis = get_redis();
            $key = 'chat:ban:' . $userId;
            $redis->setex($key, self::MUTE_DURATION * 7, '1');

            Log::warning("用户封禁: user_id={$userId}");
            return true;
        } catch (\Throwable $e) {
            Log::error("封禁用户失败: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * 检查用户是否被禁言
     * @param int $userId
     * @return bool
     */
    public function isMuted(int $userId): bool
    {
        try {
            $redis = get_redis();
            $key = 'chat:mute:' . $userId;
            return $redis->exists($key) > 0;
        } catch (\Throwable $e) {
            Log::error("检查用户禁言状态失败: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * 检查用户是否被封禁
     * @param int $userId
     * @return bool
     */
    public function isBanned(int $userId): bool
    {
        try {
            $redis = get_redis();
            $key = 'chat:ban:' . $userId;
            return $redis->exists($key) > 0;
        } catch (\Throwable $e) {
            Log::error("检查用户封禁状态失败: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * 比较风险等级
     * @param string $level1
     * @param string $level2
     * @return int 正数表示level1更高，负数表示level2更高，0表示相等
     */
    private function compareLevel(string $level1, string $level2): int
    {
        $levels = [
            ChatAntiFraudRule::LEVEL_WARNING => 1,
            ChatAntiFraudRule::LEVEL_MUTE    => 2,
            ChatAntiFraudRule::LEVEL_BAN     => 3,
        ];

        $v1 = $levels[$level1] ?? 0;
        $v2 = $levels[$level2] ?? 0;

        return $v1 - $v2;
    }

    /**
     * 获取拦截日志列表
     * @param array  $params
     * @param int    $page
     * @param int    $limit
     * @return array
     */
    public function getLogList(array $params, int $page, int $limit): array
    {
        $query = ChatAntiFraudLog::with('rule');

        if (!empty($params['sender_id'])) {
            $query->where('sender_id', $params['sender_id']);
        }
        if (!empty($params['session_id'])) {
            $query->where('session_id', $params['session_id']);
        }
        if (!empty($params['level'])) {
            $query->where('level', $params['level']);
        }
        if (isset($params['handled'])) {
            $query->where('handled', $params['handled']);
        }
        if (!empty($params['start_time'])) {
            $query->where('create_time', '>=', $params['start_time']);
        }
        if (!empty($params['end_time'])) {
            $query->where('create_time', '<=', $params['end_time']);
        }

        $total = $query->count();
        $list  = $query->order('id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        return ['list' => $list, 'total' => $total];
    }

    /**
     * 获取规则列表
     * @param array $params
     * @param int   $page
     * @param int   $limit
     * @return array
     */
    public function getRuleList(array $params, int $page, int $limit): array
    {
        $query = ChatAntiFraudRule::order('sort', 'asc')->order('id', 'asc');

        if (!empty($params['rule_type'])) {
            $query->where('rule_type', $params['rule_type']);
        }
        if (isset($params['status'])) {
            $query->where('status', $params['status']);
        }
        if (!empty($params['level'])) {
            $query->where('level', $params['level']);
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        return ['list' => $list, 'total' => $total];
    }

    /**
     * 创建规则
     * @param array $data
     * @return array
     */
    public function createRule(array $data): array
    {
        $rule = ChatAntiFraudRule::create([
            'rule_type' => $data['rule_type'],
            'rule_name' => $data['rule_name'],
            'pattern'   => $data['pattern'],
            'level'     => $data['level'] ?? ChatAntiFraudRule::LEVEL_WARNING,
            'status'    => $data['status'] ?? ChatAntiFraudRule::STATUS_ENABLED,
            'sort'      => $data['sort'] ?? 0,
        ]);

        write_action_log('anti_fraud_rule_create', "创建飞单风控规则: {$data['rule_name']}");

        return $rule->toArray();
    }

    /**
     * 更新规则
     * @param int   $id
     * @param array $data
     * @return bool
     */
    public function updateRule(int $id, array $data): bool
    {
        $rule = ChatAntiFraudRule::find($id);
        if (!$rule) {
            throw new \RuntimeException('规则不存在');
        }

        $updateData = [];
        if (isset($data['rule_type'])) $updateData['rule_type'] = $data['rule_type'];
        if (isset($data['rule_name'])) $updateData['rule_name'] = $data['rule_name'];
        if (isset($data['pattern']))   $updateData['pattern']   = $data['pattern'];
        if (isset($data['level']))     $updateData['level']     = $data['level'];
        if (isset($data['status']))    $updateData['status']    = $data['status'];
        if (isset($data['sort']))      $updateData['sort']      = $data['sort'];

        $rule->save($updateData);

        write_action_log('anti_fraud_rule_update', "更新飞单风控规则: ID={$id}");

        return true;
    }

    /**
     * 删除规则
     * @param int $id
     * @return bool
     */
    public function deleteRule(int $id): bool
    {
        $rule = ChatAntiFraudRule::find($id);
        if (!$rule) {
            throw new \RuntimeException('规则不存在');
        }

        $rule->delete();

        write_action_log('anti_fraud_rule_delete', "删除飞单风控规则: ID={$id}");

        return true;
    }

    /**
     * 处理拦截日志
     * @param int    $logId
     * @param int    $handlerId
     * @param string $result
     * @return bool
     */
    public function handleLog(int $logId, int $handlerId, string $result): bool
    {
        $log = ChatAntiFraudLog::find($logId);
        if (!$log) {
            throw new \RuntimeException('日志不存在');
        }

        $log->handled      = ChatAntiFraudLog::HANDLED_YES;
        $log->handle_result = $result;
        $log->handle_time  = date('Y-m-d H:i:s');
        $log->handler_id   = $handlerId;
        $log->save();

        write_action_log('anti_fraud_log_handle', "处理飞单拦截日志: ID={$logId}, 结果={$result}");

        return true;
    }
}
