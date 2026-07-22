<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseController;
use app\model\AfterSaleInterveneLog;
use app\model\AfterSaleMessage;
use app\model\AfterSaleSession;
use app\service\AfterSaleService;
use think\facade\Log;
use think\Request;

/**
 * 售后介入管理控制器
 */
class AfterSaleManage extends BaseController
{
    /**
     * 售后会话列表
     */
    public function list(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $status       = $request->param('status', '');
        $riskLevel    = $request->param('risk_level', '');
        $isIntervened = $request->param('is_intervened', '');
        $keyword      = $request->param('keyword', '');

        $query = AfterSaleSession::order('id', 'desc');

        if ($status !== '') {
            $query->where('status', (int)$status);
        }
        if ($riskLevel !== '') {
            $query->where('risk_level', (int)$riskLevel);
        }
        if ($isIntervened !== '') {
            $query->where('is_intervened', (int)$isIntervened);
        }
        if (!empty($keyword)) {
            $query->where(function ($q) use ($keyword) {
                $q->where('reason', 'like', "%{$keyword}%")
                  ->whereOr('id', 'like', "%{$keyword}%");
            });
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        $this->operationLog('admin_after_sale_manage_list', '查看售后会话列表');

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 售后会话详情
     */
    public function detail(Request $request)
    {
        $sessionId = $request->paramInt('session_id', 0);

        if ($sessionId <= 0) {
            return $this->error('会话ID无效');
        }

        $session = AfterSaleSession::find($sessionId);
        if (!$session) {
            return $this->error('售后会话不存在', 404);
        }

        $data = $session->toArray();
        $data['message_count'] = AfterSaleMessage::where('session_id', $sessionId)->count();

        $this->operationLog('admin_after_sale_manage_detail', "查看售后会话详情: ID: {$sessionId}");

        return $this->success($data);
    }

    /**
     * 售后消息查看
     */
    public function messages(Request $request)
    {
        $sessionId = $request->paramInt('session_id', 0);
        [$page, $limit] = $this->pageParams();

        if ($sessionId <= 0) {
            return $this->error('会话ID无效');
        }

        $query = AfterSaleMessage::where('session_id', $sessionId)
            ->order('create_time', 'asc');

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        $this->operationLog('admin_after_sale_manage_messages', "查看售后消息: 会话ID: {$sessionId}");

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 介入记录
     */
    public function interveneLog(Request $request)
    {
        $sessionId = $request->paramInt('session_id', 0);
        [$page, $limit] = $this->pageParams();

        $query = AfterSaleInterveneLog::order('id', 'desc');

        if ($sessionId > 0) {
            $query->where('session_id', $sessionId);
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        $this->operationLog('admin_after_sale_manage_intervene_log', '查看介入记录');

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 解除介入/处理纠纷
     */
    public function resolve(Request $request)
    {
        $adminId   = $this->adminId();
        $sessionId = $request->paramInt('session_id', 0);
        $result    = $request->param('result', '');
        $action    = $request->param('action', 'resolve');

        $error = $this->validateRequired([
            'session_id' => $sessionId,
            'result'     => $result,
        ], ['session_id', 'result']);
        if ($error) {
            return $this->error($error);
        }

        try {
            $service = new AfterSaleService();
            $service->resolveIntervene($sessionId, $adminId, $result, $action);

            $this->operationLog('admin_after_sale_manage_resolve', "处理纠纷: 会话ID: {$sessionId}, 结果: {$result}");

            return $this->success(null, '处理完成');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('处理纠纷异常: ' . $e->getMessage());
            return $this->error('操作失败');
        }
    }

    /**
     * 导出介入记录
     */
    public function export(Request $request)
    {
        $sessionId  = $request->paramInt('session_id', 0);
        $startTime  = $request->param('start_time', '');
        $endTime    = $request->param('end_time', '');

        $query = AfterSaleInterveneLog::order('id', 'desc');

        if ($sessionId > 0) {
            $query->where('session_id', $sessionId);
        }
        if (!empty($startTime)) {
            $query->where('create_time', '>=', $startTime);
        }
        if (!empty($endTime)) {
            $query->where('create_time', '<=', $endTime);
        }

        $list = $query->select()->toArray();

        $this->operationLog('admin_after_sale_manage_export', '导出介入记录');

        return $this->success([
            'list'  => $list,
            'total' => count($list),
        ], '导出成功');
    }
}