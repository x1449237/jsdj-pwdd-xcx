<?php
declare(strict_types=1);

namespace app\controller\api;

use app\controller\BaseController;
use app\service\ArbitrationService;
use app\service\ComplianceService;
use think\facade\Log;
use think\Request;

class Arbitration extends BaseController
{
    public function evidenceTplList(Request $request)
    {
        $disputeType = $request->param('dispute_type', '');

        $service = new ArbitrationService();
        $list = $service->getEvidenceTplList($disputeType);

        return $this->success($list);
    }

    public function apply(Request $request)
    {
        $userId      = request()->userId();
        $orderId     = $request->paramInt('order_id', 0);
        $sessionId   = $request->paramInt('session_id', 0);
        $respondentId = $request->paramInt('respondent_id', 0);
        $disputeType = $request->param('dispute_type', '');
        $description = $request->param('description', '');
        $evidence    = $request->param('evidence', '');

        $error = $this->validateRequired([
            'order_id'      => $orderId,
            'respondent_id' => $respondentId,
            'dispute_type'  => $disputeType,
            'description'   => $description,
        ], ['order_id', 'respondent_id', 'dispute_type', 'description']);
        if ($error) {
            return $this->error($error);
        }

        $evidenceArr = [];
        if (!empty($evidence)) {
            $evidenceArr = json_decode($evidence, true) ?: [];
        }

        try {
            $service = new ArbitrationService();
            $case = $service->createCase(
                $orderId,
                $sessionId,
                $userId,
                $respondentId,
                $disputeType,
                $description,
                $evidenceArr
            );

            $this->operationLog('api_arbitration_apply', "申请仲裁: 订单ID: {$orderId}, 案件ID: {$case['id']}");

            return $this->success($case, '仲裁申请提交成功');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('申请仲裁异常: ' . $e->getMessage());
            return $this->error('提交失败');
        }
    }

    public function myList(Request $request)
    {
        $userId = request()->userId();
        [$page, $limit] = $this->pageParams();

        $service = new ArbitrationService();
        $result = $service->getMyCases($userId, $page, $limit);

        return $this->page($result['list'], $result['total'], $page, $limit);
    }

    public function detail(Request $request)
    {
        $userId = request()->userId();
        $caseId = $request->paramInt('case_id', 0);

        if ($caseId <= 0) {
            return $this->error('案件ID无效');
        }

        $service = new ArbitrationService();
        $case = $service->getCaseDetail($caseId);

        if (!$case) {
            return $this->error('仲裁案件不存在', 404);
        }

        if ($case['applicant_id'] != $userId && $case['respondent_id'] != $userId) {
            return $this->error('无权查看该案件', 403);
        }

        return $this->success($case);
    }

    public function uploadEvidence(Request $request)
    {
        $userId      = request()->userId();
        $caseId      = $request->paramInt('case_id', 0);
        $type        = $request->param('type', 'image');
        $fileUrl     = $request->param('file_url', '');
        $description = $request->param('description', '');

        $error = $this->validateRequired([
            'case_id'  => $caseId,
            'file_url' => $fileUrl,
        ], ['case_id', 'file_url']);
        if ($error) {
            return $this->error($error);
        }

        try {
            $service = new ArbitrationService();
            $evidence = $service->uploadEvidence($caseId, $userId, $type, $fileUrl, $description);

            return $this->success($evidence, '上传成功');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('上传举证材料异常: ' . $e->getMessage());
            return $this->error('上传失败');
        }
    }

    public function matchRules(Request $request)
    {
        $disputeType = $request->param('dispute_type', '');

        if (empty($disputeType)) {
            return $this->error('纠纷类型不能为空');
        }

        $service = new ArbitrationService();
        $rules = $service->matchRules($disputeType);

        return $this->success($rules);
    }

    public function agreementDetail(Request $request)
    {
        $role          = $request->param('role', 'buyer');
        $agreementType = $request->param('agreement_type', 'user_service');

        $service = new ComplianceService();
        $version = $service->getAgreementVersion($role, $agreementType);

        return $this->success($version);
    }

    public function signAgreement(Request $request)
    {
        $userId        = request()->userId();
        $role          = $request->param('role', 'buyer');
        $agreementType = $request->param('agreement_type', 'user_service');
        $device        = $request->param('device', '');

        $error = $this->validateRequired([
            'role'            => $role,
            'agreement_type'  => $agreementType,
        ], ['role', 'agreement_type']);
        if ($error) {
            return $this->error($error);
        }

        try {
            $ip = get_client_ip();
            $service = new ComplianceService();
            $signLog = $service->signAgreement($userId, $role, $agreementType, $ip, $device);

            return $this->success($signLog, '签署成功');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('签署协议异常: ' . $e->getMessage());
            return $this->error('签署失败');
        }
    }

    public function checkNeedSign(Request $request)
    {
        $userId        = request()->userId();
        $role          = $request->param('role', 'buyer');
        $agreementType = $request->param('agreement_type', 'user_service');

        $service = new ComplianceService();
        $need = $service->checkNeedSign($userId, $role, $agreementType);

        return $this->success(['need_sign' => $need]);
    }

    public function checkContent(Request $request)
    {
        $userId   = request()->userId();
        $content  = $request->param('content', '');
        $source   = $request->param('source', 'order');
        $sourceId = $request->paramInt('source_id', 0);

        $service = new ComplianceService();
        $result = $service->checkContent($content, $source, $sourceId, $userId);

        return $this->success($result);
    }
}
