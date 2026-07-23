<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseController;
use app\service\ComplianceService;
use think\facade\Log;
use think\Request;

class Compliance extends BaseController
{
    public function antiBoostingRuleList(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $level = $request->param('level', '');

        $service = new ComplianceService();
        $result = $service->getAntiBoostingRuleList($level, $page, $limit);

        $this->operationLog('admin_compliance_anti_boosting_rule_list', '查看代练违禁规则列表');

        return $this->page($result['list'], $result['total'], $page, $limit);
    }

    public function createAntiBoostingRule(Request $request)
    {
        $keyword = $request->param('keyword', '');
        $level   = $request->param('level', 'warn');

        $error = $this->validateRequired([
            'keyword' => $keyword,
            'level'   => $level,
        ], ['keyword', 'level']);
        if ($error) {
            return $this->error($error);
        }

        try {
            $service = new ComplianceService();
            $rule = $service->createAntiBoostingRule($keyword, $level);

            $this->operationLog('admin_compliance_create_anti_boosting_rule', "创建代练违禁规则: {$keyword}");

            return $this->success($rule, '创建成功');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('创建代练违禁规则异常: ' . $e->getMessage());
            return $this->error('创建失败');
        }
    }

    public function updateAntiBoostingRule(Request $request)
    {
        $id      = $request->paramInt('id', 0);
        $keyword = $request->param('keyword', '');
        $level   = $request->param('level', '');
        $status  = $request->param('status', '');

        if ($id <= 0) {
            return $this->error('规则ID无效');
        }

        $data = [];
        if ($keyword !== '') {
            $data['keyword'] = $keyword;
        }
        if ($level !== '') {
            $data['level'] = $level;
        }
        if ($status !== '') {
            $data['status'] = (int)$status;
        }

        try {
            $service = new ComplianceService();
            $service->updateAntiBoostingRule($id, $data);

            $this->operationLog('admin_compliance_update_anti_boosting_rule', "更新代练违禁规则: ID: {$id}");

            return $this->success(null, '更新成功');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('更新代练违禁规则异常: ' . $e->getMessage());
            return $this->error('更新失败');
        }
    }

    public function deleteAntiBoostingRule(Request $request)
    {
        $id = $request->paramInt('id', 0);

        if ($id <= 0) {
            return $this->error('规则ID无效');
        }

        try {
            $service = new ComplianceService();
            $service->deleteAntiBoostingRule($id);

            $this->operationLog('admin_compliance_delete_anti_boosting_rule', "删除代练违禁规则: ID: {$id}");

            return $this->success(null, '删除成功');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('删除代练违禁规则异常: ' . $e->getMessage());
            return $this->error('删除失败');
        }
    }

    public function antiBoostingLogList(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $source  = $request->param('source', '');
        $level   = $request->param('level', '');
        $userId  = $request->paramInt('user_id', 0);
        $handled = $request->param('handled', '');

        $service = new ComplianceService();
        $result = $service->getAntiBoostingLogList([
            'source'  => $source,
            'level'   => $level,
            'user_id' => $userId,
            'handled' => $handled,
        ], $page, $limit);

        $this->operationLog('admin_compliance_anti_boosting_log_list', '查看代练拦截日志列表');

        return $this->page($result['list'], $result['total'], $page, $limit);
    }

    public function handleAntiBoostingLog(Request $request)
    {
        $id = $request->paramInt('id', 0);

        if ($id <= 0) {
            return $this->error('日志ID无效');
        }

        try {
            $service = new ComplianceService();
            $service->handleAntiBoostingLog($id);

            $this->operationLog('admin_compliance_handle_anti_boosting_log', "处理代练拦截日志: ID: {$id}");

            return $this->success(null, '处理成功');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('处理代练拦截日志异常: ' . $e->getMessage());
            return $this->error('处理失败');
        }
    }

    public function expandSensitiveWords(Request $request)
    {
        try {
            $service = new ComplianceService();
            $service->expandSensitiveWords();

            $this->operationLog('admin_compliance_expand_sensitive_words', '扩充代练违禁词库');

            return $this->success(null, '扩充成功');
        } catch (\Throwable $e) {
            Log::error('扩充违禁词库异常: ' . $e->getMessage());
            return $this->error('扩充失败');
        }
    }

    public function agreementVersionList(Request $request)
    {
        $role          = $request->param('role', '');
        $agreementType = $request->param('agreement_type', '');

        $service = new ComplianceService();
        $list = $service->getAllAgreementVersions($role, $agreementType);

        $this->operationLog('admin_compliance_agreement_version_list', '查看协议版本列表');

        return $this->success($list);
    }

    public function createAgreementVersion(Request $request)
    {
        $role          = $request->param('role', '');
        $agreementType = $request->param('agreement_type', '');
        $content       = $request->param('content', '');

        $error = $this->validateRequired([
            'role'            => $role,
            'agreement_type'  => $agreementType,
            'content'         => $content,
        ], ['role', 'agreement_type', 'content']);
        if ($error) {
            return $this->error($error);
        }

        try {
            $service = new ComplianceService();
            $version = $service->createAgreementVersion($role, $agreementType, $content);

            $this->operationLog('admin_compliance_create_agreement_version', "创建协议版本: {$role}/{$agreementType}/v{$version['version']}");

            return $this->success($version, '创建成功');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('创建协议版本异常: ' . $e->getMessage());
            return $this->error('创建失败');
        }
    }

    public function updateAgreementVersion(Request $request)
    {
        $id      = $request->paramInt('id', 0);
        $content = $request->param('content', '');

        if ($id <= 0) {
            return $this->error('版本ID无效');
        }

        if ($content === '') {
            return $this->error('内容不能为空');
        }

        try {
            $service = new ComplianceService();
            $service->updateAgreementVersion($id, $content);

            $this->operationLog('admin_compliance_update_agreement_version', "更新协议版本: ID: {$id}");

            return $this->success(null, '更新成功');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('更新协议版本异常: ' . $e->getMessage());
            return $this->error('更新失败');
        }
    }

    public function publishAgreementVersion(Request $request)
    {
        $id = $request->paramInt('id', 0);

        if ($id <= 0) {
            return $this->error('版本ID无效');
        }

        try {
            $service = new ComplianceService();
            $service->publishAgreementVersion($id);

            $this->operationLog('admin_compliance_publish_agreement_version', "发布协议版本: ID: {$id}");

            return $this->success(null, '发布成功');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('发布协议版本异常: ' . $e->getMessage());
            return $this->error('发布失败');
        }
    }

    public function agreementSignLogList(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $userId = $request->paramInt('user_id', 0);
        $role   = $request->param('role', '');
        $type   = $request->param('agreement_type', '');

        $service = new ComplianceService();
        $list = $service->getSignLogList($userId, $role, $type);

        $this->operationLog('admin_compliance_agreement_sign_log_list', '查看协议签署记录列表');

        return $this->success($list);
    }

    public function testCheckContent(Request $request)
    {
        $content  = $request->param('content', '');
        $source   = $request->param('source', 'order');

        if (empty($content)) {
            return $this->error('测试内容不能为空');
        }

        $service = new ComplianceService();
        $result = $service->checkContent($content, $source, 0, 0);

        return $this->success($result);
    }
}
