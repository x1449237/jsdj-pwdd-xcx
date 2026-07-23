<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseController;
use app\service\ArbitrationService;
use think\facade\Log;
use think\Request;

class Arbitration extends BaseController
{
    public function caseList(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $status      = $request->param('status', '');
        $disputeType = $request->param('dispute_type', '');
        $keyword     = $request->param('keyword', '');

        $service = new ArbitrationService();
        $result = $service->getCaseList([
            'status'       => $status,
            'dispute_type' => $disputeType,
            'keyword'      => $keyword,
        ], $page, $limit);

        $this->operationLog('admin_arbitration_case_list', '查看仲裁案件列表');

        return $this->page($result['list'], $result['total'], $page, $limit);
    }

    public function caseDetail(Request $request)
    {
        $caseId = $request->paramInt('case_id', 0);

        if ($caseId <= 0) {
            return $this->error('案件ID无效');
        }

        $service = new ArbitrationService();
        $case = $service->getCaseDetail($caseId);

        if (!$case) {
            return $this->error('仲裁案件不存在', 404);
        }

        $this->operationLog('admin_arbitration_case_detail', "查看仲裁案件详情: ID: {$caseId}");

        return $this->success($case);
    }

    public function processCase(Request $request)
    {
        $adminId = $this->adminId();
        $caseId  = $request->paramInt('case_id', 0);

        if ($caseId <= 0) {
            return $this->error('案件ID无效');
        }

        try {
            $service = new ArbitrationService();
            $service->processCase($caseId, $adminId);

            $this->operationLog('admin_arbitration_process', "受理仲裁案件: ID: {$caseId}");

            return $this->success(null, '受理成功');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('受理仲裁案件异常: ' . $e->getMessage());
            return $this->error('受理失败');
        }
    }

    public function resolveCase(Request $request)
    {
        $adminId   = $this->adminId();
        $caseId    = $request->paramInt('case_id', 0);
        $result    = $request->param('result', '');
        $penalties = $request->param('penalties', '');

        $error = $this->validateRequired([
            'case_id' => $caseId,
            'result'  => $result,
        ], ['case_id', 'result']);
        if ($error) {
            return $this->error($error);
        }

        $penaltyArr = [];
        if (!empty($penalties)) {
            $penaltyArr = json_decode($penalties, true) ?: [];
        }

        try {
            $service = new ArbitrationService();
            $service->resolveCase($caseId, $adminId, $result, $penaltyArr);

            $this->operationLog('admin_arbitration_resolve', "结案仲裁案件: ID: {$caseId}");

            return $this->success(null, '结案成功');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('结案仲裁案件异常: ' . $e->getMessage());
            return $this->error('结案失败');
        }
    }

    public function ruleList(Request $request)
    {
        $ruleType  = $request->param('rule_type', '');
        $faultSide = $request->param('fault_side', '');

        $service = new ArbitrationService();
        $list = $service->getRuleList($ruleType, $faultSide);

        $this->operationLog('admin_arbitration_rule_list', '查看仲裁规则列表');

        return $this->success($list);
    }

    public function createRule(Request $request)
    {
        $ruleType     = $request->param('rule_type', '');
        $faultSide    = $request->param('fault_side', '');
        $penaltyType  = $request->param('penalty_type', '');
        $penaltyValue = $request->param('penalty_value', '');
        $description  = $request->param('description', '');

        $error = $this->validateRequired([
            'rule_type'    => $ruleType,
            'fault_side'   => $faultSide,
            'penalty_type' => $penaltyType,
        ], ['rule_type', 'fault_side', 'penalty_type']);
        if ($error) {
            return $this->error($error);
        }

        try {
            $service = new ArbitrationService();
            $rule = $service->createRule([
                'rule_type'     => $ruleType,
                'fault_side'    => $faultSide,
                'penalty_type'  => $penaltyType,
                'penalty_value' => $penaltyValue,
                'description'   => $description,
                'status'        => 1,
            ]);

            $this->operationLog('admin_arbitration_create_rule', "创建仲裁规则: ID: {$rule['id']}");

            return $this->success($rule, '创建成功');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('创建仲裁规则异常: ' . $e->getMessage());
            return $this->error('创建失败');
        }
    }

    public function updateRule(Request $request)
    {
        $id           = $request->paramInt('id', 0);
        $ruleType     = $request->param('rule_type', '');
        $faultSide    = $request->param('fault_side', '');
        $penaltyType  = $request->param('penalty_type', '');
        $penaltyValue = $request->param('penalty_value', '');
        $description  = $request->param('description', '');
        $status       = $request->param('status', '');

        if ($id <= 0) {
            return $this->error('规则ID无效');
        }

        $data = [];
        if ($ruleType !== '') {
            $data['rule_type'] = $ruleType;
        }
        if ($faultSide !== '') {
            $data['fault_side'] = $faultSide;
        }
        if ($penaltyType !== '') {
            $data['penalty_type'] = $penaltyType;
        }
        if ($penaltyValue !== '') {
            $data['penalty_value'] = $penaltyValue;
        }
        if ($description !== '') {
            $data['description'] = $description;
        }
        if ($status !== '') {
            $data['status'] = (int)$status;
        }

        try {
            $service = new ArbitrationService();
            $service->updateRule($id, $data);

            $this->operationLog('admin_arbitration_update_rule', "更新仲裁规则: ID: {$id}");

            return $this->success(null, '更新成功');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('更新仲裁规则异常: ' . $e->getMessage());
            return $this->error('更新失败');
        }
    }

    public function deleteRule(Request $request)
    {
        $id = $request->paramInt('id', 0);

        if ($id <= 0) {
            return $this->error('规则ID无效');
        }

        try {
            $service = new ArbitrationService();
            $service->deleteRule($id);

            $this->operationLog('admin_arbitration_delete_rule', "删除仲裁规则: ID: {$id}");

            return $this->success(null, '删除成功');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('删除仲裁规则异常: ' . $e->getMessage());
            return $this->error('删除失败');
        }
    }

    public function evidenceTplList(Request $request)
    {
        $disputeType = $request->param('dispute_type', '');

        $service = new ArbitrationService();
        $list = $service->getEvidenceTplList($disputeType);

        $this->operationLog('admin_arbitration_evidence_tpl_list', '查看举证模板列表');

        return $this->success($list);
    }

    public function createEvidenceTpl(Request $request)
    {
        $disputeType      = $request->param('dispute_type', '');
        $title            = $request->param('title', '');
        $description      = $request->param('description', '');
        $requiredItemsJson = $request->param('required_items_json', '');
        $sort             = $request->paramInt('sort', 0);

        $error = $this->validateRequired([
            'dispute_type' => $disputeType,
            'title'        => $title,
        ], ['dispute_type', 'title']);
        if ($error) {
            return $this->error($error);
        }

        $requiredItems = [];
        if (!empty($requiredItemsJson)) {
            $requiredItems = json_decode($requiredItemsJson, true) ?: [];
        }

        try {
            $service = new ArbitrationService();
            $tpl = $service->createEvidenceTpl([
                'dispute_type'        => $disputeType,
                'title'               => $title,
                'description'         => $description,
                'required_items_json' => $requiredItems,
                'sort'                => $sort,
                'status'              => 1,
            ]);

            $this->operationLog('admin_arbitration_create_evidence_tpl', "创建举证模板: ID: {$tpl['id']}");

            return $this->success($tpl, '创建成功');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('创建举证模板异常: ' . $e->getMessage());
            return $this->error('创建失败');
        }
    }

    public function updateEvidenceTpl(Request $request)
    {
        $id               = $request->paramInt('id', 0);
        $disputeType      = $request->param('dispute_type', '');
        $title            = $request->param('title', '');
        $description      = $request->param('description', '');
        $requiredItemsJson = $request->param('required_items_json', '');
        $sort             = $request->param('sort', '');
        $status           = $request->param('status', '');

        if ($id <= 0) {
            return $this->error('模板ID无效');
        }

        $data = [];
        if ($disputeType !== '') {
            $data['dispute_type'] = $disputeType;
        }
        if ($title !== '') {
            $data['title'] = $title;
        }
        if ($description !== '') {
            $data['description'] = $description;
        }
        if ($requiredItemsJson !== '') {
            $data['required_items_json'] = json_decode($requiredItemsJson, true) ?: [];
        }
        if ($sort !== '') {
            $data['sort'] = (int)$sort;
        }
        if ($status !== '') {
            $data['status'] = (int)$status;
        }

        try {
            $service = new ArbitrationService();
            $service->updateEvidenceTpl($id, $data);

            $this->operationLog('admin_arbitration_update_evidence_tpl', "更新举证模板: ID: {$id}");

            return $this->success(null, '更新成功');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('更新举证模板异常: ' . $e->getMessage());
            return $this->error('更新失败');
        }
    }

    public function deleteEvidenceTpl(Request $request)
    {
        $id = $request->paramInt('id', 0);

        if ($id <= 0) {
            return $this->error('模板ID无效');
        }

        try {
            $service = new ArbitrationService();
            $service->deleteEvidenceTpl($id);

            $this->operationLog('admin_arbitration_delete_evidence_tpl', "删除举证模板: ID: {$id}");

            return $this->success(null, '删除成功');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('删除举证模板异常: ' . $e->getMessage());
            return $this->error('删除失败');
        }
    }
}
