<?php
declare(strict_types=1);

namespace app\service;

use app\model\ArbitrationCase;
use app\model\ArbitrationEvidence;
use app\model\ArbitrationEvidenceTpl;
use app\model\ArbitrationRule;
use app\model\Order;
use app\model\User;
use think\facade\Db;
use think\facade\Log;

class ArbitrationService
{
    public function getEvidenceTplList(string $disputeType = ''): array
    {
        $query = ArbitrationEvidenceTpl::order('sort', 'asc');
        if (!empty($disputeType)) {
            $query->byDisputeType($disputeType);
        }
        return $query->select()->toArray();
    }

    public function getEvidenceTplByType(string $disputeType): ?array
    {
        $tpl = ArbitrationEvidenceTpl::byDisputeType($disputeType)
            ->enabled()
            ->order('sort', 'asc')
            ->find();
        return $tpl ? $tpl->toArray() : null;
    }

    public function createCase(
        int $orderId,
        int $sessionId,
        int $applicantId,
        int $respondentId,
        string $disputeType,
        string $description,
        array $evidence = []
    ): array {
        $order = Order::find($orderId);
        if (!$order) {
            throw new \RuntimeException('订单不存在');
        }

        Db::startTrans();
        try {
            $case = ArbitrationCase::create([
                'order_id'       => $orderId,
                'session_id'     => $sessionId,
                'applicant_id'   => $applicantId,
                'respondent_id'  => $respondentId,
                'dispute_type'   => $disputeType,
                'description'    => $description,
                'evidence_json'  => $evidence,
                'status'         => ArbitrationCase::STATUS_PENDING,
            ]);

            foreach ($evidence as $item) {
                if (!empty($item['file_url'])) {
                    ArbitrationEvidence::create([
                        'case_id'     => $case->id,
                        'uploader_id' => $applicantId,
                        'type'        => $item['type'] ?? 'image',
                        'file_url'    => $item['file_url'] ?? '',
                        'description' => $item['description'] ?? '',
                    ]);
                }
            }

            Db::commit();
            write_action_log('arbitration_create_case', "创建仲裁案件: 订单ID: {$orderId}, 案件ID: {$case->id}");
            return $case->toArray();
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('创建仲裁案件失败: ' . $e->getMessage());
            throw $e;
        }
    }

    public function uploadEvidence(int $caseId, int $uploaderId, string $type, string $fileUrl, string $description = ''): array
    {
        $case = ArbitrationCase::find($caseId);
        if (!$case) {
            throw new \RuntimeException('仲裁案件不存在');
        }

        if ($case->status === ArbitrationCase::STATUS_RESOLVED) {
            throw new \RuntimeException('案件已结案，无法上传证据');
        }

        $evidence = ArbitrationEvidence::create([
            'case_id'     => $caseId,
            'uploader_id' => $uploaderId,
            'type'        => $type,
            'file_url'    => $fileUrl,
            'description' => $description,
        ]);

        return $evidence->toArray();
    }

    public function getCaseDetail(int $caseId): ?array
    {
        $case = ArbitrationCase::with('evidenceList')->find($caseId);
        return $case ? $case->toArray() : null;
    }

    public function getMyCases(int $userId, int $page = 1, int $limit = 15): array
    {
        $query = ArbitrationCase::where(function ($q) use ($userId) {
            $q->where('applicant_id', $userId)
              ->whereOr('respondent_id', $userId);
        })->order('create_time', 'desc');

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        return ['list' => $list, 'total' => $total];
    }

    public function getCaseList(array $params, int $page = 1, int $limit = 15): array
    {
        $query = ArbitrationCase::order('create_time', 'desc');

        if (!empty($params['status'])) {
            $query->where('status', $params['status']);
        }
        if (!empty($params['dispute_type'])) {
            $query->where('dispute_type', $params['dispute_type']);
        }
        if (!empty($params['keyword'])) {
            $query->where(function ($q) use ($params) {
                $q->where('id', 'like', "%{$params['keyword']}%")
                  ->whereOr('order_id', 'like', "%{$params['keyword']}%");
            });
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        return ['list' => $list, 'total' => $total];
    }

    public function processCase(int $caseId, int $handlerId): void
    {
        $case = ArbitrationCase::find($caseId);
        if (!$case) {
            throw new \RuntimeException('仲裁案件不存在');
        }
        if ($case->status !== ArbitrationCase::STATUS_PENDING) {
            throw new \RuntimeException('案件状态不支持受理');
        }

        $case->status     = ArbitrationCase::STATUS_PROCESSING;
        $case->handler_id = $handlerId;
        $case->save();

        write_action_log('arbitration_process_case', "受理仲裁案件: 案件ID: {$caseId}, 处理人: {$handlerId}");
    }

    public function resolveCase(int $caseId, int $handlerId, string $result, array $penalties = []): void
    {
        Db::startTrans();
        try {
            $case = ArbitrationCase::find($caseId);
            if (!$case) {
                throw new \RuntimeException('仲裁案件不存在');
            }
            if ($case->status === ArbitrationCase::STATUS_RESOLVED) {
                throw new \RuntimeException('案件已结案');
            }

            $case->status      = ArbitrationCase::STATUS_RESOLVED;
            $case->result      = $result;
            $case->handler_id  = $handlerId;
            $case->finish_time = date('Y-m-d H:i:s');
            $case->save();

            foreach ($penalties as $penalty) {
                $this->executePenalty($case, $penalty);
            }

            Db::commit();
            write_action_log('arbitration_resolve_case', "结案仲裁案件: 案件ID: {$caseId}");
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('结案仲裁案件失败: ' . $e->getMessage());
            throw $e;
        }
    }

    protected function executePenalty(ArbitrationCase $case, array $penalty): void
    {
        $penaltyType = $penalty['penalty_type'] ?? '';
        $penaltyValue = $penalty['penalty_value'] ?? '';
        $targetUserId = $penalty['target_user_id'] ?? 0;

        if (!$targetUserId) {
            return;
        }

        switch ($penaltyType) {
            case 'deduct_credit':
                $user = User::find($targetUserId);
                if ($user) {
                    $user->credit_score = max(0, $user->credit_score - (int)$penaltyValue);
                    $user->save();
                }
                break;
            case 'deduct_deposit':
                $depositService = new ServiceDepositService();
                $amount = $penaltyValue === 'all' ? 0 : (int)$penaltyValue;
                $depositService->deductDeposit($targetUserId, $amount, $case->id, '仲裁判罚扣除保证金');
                break;
            case 'ban_account':
                $user = User::find($targetUserId);
                if ($user) {
                    $user->status     = 0;
                    $user->ban_reason = '仲裁判罚封禁';
                    if ($penaltyValue !== 'forever') {
                        $user->ban_until = date('Y-m-d H:i:s', time() + (int)$penaltyValue * 86400);
                    }
                    $user->save();
                }
                break;
        }
    }

    public function getRuleList(string $ruleType = '', string $faultSide = ''): array
    {
        $query = ArbitrationRule::order('id', 'asc');
        if (!empty($ruleType)) {
            $query->byRuleType($ruleType);
        }
        if (!empty($faultSide)) {
            $query->where('fault_side', $faultSide);
        }
        return $query->select()->toArray();
    }

    public function matchRules(string $disputeType): array
    {
        $ruleType = $this->mapDisputeTypeToRuleType($disputeType);
        if (!$ruleType) {
            return [];
        }
        return ArbitrationRule::byRuleType($ruleType)->enabled()->select()->toArray();
    }

    protected function mapDisputeTypeToRuleType(string $disputeType): ?string
    {
        $map = [
            'player_late'      => ArbitrationRule::RULE_PLAYER_LATE,
            'negative_service' => ArbitrationRule::RULE_NEGATIVE_SERVICE,
            'player_refund'    => ArbitrationRule::RULE_PLAYER_UNPROVOKED_REFUND,
            'demand_change'    => ArbitrationRule::RULE_DEMAND_CHANGE,
        ];
        return $map[$disputeType] ?? null;
    }

    public function createRule(array $data): array
    {
        $rule = ArbitrationRule::create($data);
        write_action_log('arbitration_create_rule', "创建仲裁规则: ID: {$rule->id}");
        return $rule->toArray();
    }

    public function updateRule(int $id, array $data): void
    {
        $rule = ArbitrationRule::find($id);
        if (!$rule) {
            throw new \RuntimeException('规则不存在');
        }
        $rule->save($data);
        write_action_log('arbitration_update_rule', "更新仲裁规则: ID: {$id}");
    }

    public function deleteRule(int $id): void
    {
        $rule = ArbitrationRule::find($id);
        if (!$rule) {
            throw new \RuntimeException('规则不存在');
        }
        $rule->delete();
        write_action_log('arbitration_delete_rule', "删除仲裁规则: ID: {$id}");
    }

    public function createEvidenceTpl(array $data): array
    {
        $tpl = ArbitrationEvidenceTpl::create($data);
        write_action_log('arbitration_create_evidence_tpl', "创建举证模板: ID: {$tpl->id}");
        return $tpl->toArray();
    }

    public function updateEvidenceTpl(int $id, array $data): void
    {
        $tpl = ArbitrationEvidenceTpl::find($id);
        if (!$tpl) {
            throw new \RuntimeException('举证模板不存在');
        }
        $tpl->save($data);
        write_action_log('arbitration_update_evidence_tpl', "更新举证模板: ID: {$id}");
    }

    public function deleteEvidenceTpl(int $id): void
    {
        $tpl = ArbitrationEvidenceTpl::find($id);
        if (!$tpl) {
            throw new \RuntimeException('举证模板不存在');
        }
        $tpl->delete();
        write_action_log('arbitration_delete_evidence_tpl', "删除举证模板: ID: {$id}");
    }
}
