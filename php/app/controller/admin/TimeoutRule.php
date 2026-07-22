<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseController;
use app\model\TimeoutRule as TimeoutRuleModel;
use think\Request;

/**
 * 超时规则引擎控制器
 */
class TimeoutRule extends BaseController
{
    /**
     * 规则列表
     */
    public function list(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $status    = $request->param('status', '');
        $orderType = $request->paramInt('order_type', 0);
        $action    = $request->paramInt('action', 0);

        $query = \app\model\TimeoutRule::order('id', 'desc');

        if ($status !== '') {
            $query->where('status', (int)$status);
        }

        if ($orderType > 0) {
            $query->where('order_type', $orderType);
        }

        if ($action > 0) {
            $query->where('action', $action);
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        $this->operationLog('admin_timeout_rule_list', '查看超时规则列表');

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 创建自定义规则
     */
    public function create(Request $request)
    {
        $name           = $request->param('name', '');
        $orderType      = $request->paramInt('order_type', 0);
        $timeoutMinutes = $request->paramInt('timeout_minutes', 30);
        $act            = $request->paramInt('action', 3); // 1-取消 2-完成 3-提醒

        if (empty($name)) {
            return $this->error('规则名称不能为空');
        }

        if ($orderType <= 0) {
            return $this->error('订单类型不能为空');
        }

        if ($timeoutMinutes <= 0) {
            return $this->error('超时分钟数必须大于0');
        }

        $validActions = [
            \app\model\TimeoutRule::ACTION_CANCEL,
            \app\model\TimeoutRule::ACTION_COMPLETE,
            \app\model\TimeoutRule::ACTION_NOTIFY,
        ];

        if (!in_array($act, $validActions)) {
            return $this->error('无效的超时动作，可选: 1-自动取消 2-自动完成 3-提醒');
        }

        $rule = \app\model\TimeoutRule::create([
            'name'            => $name,
            'order_type'      => $orderType,
            'timeout_minutes' => $timeoutMinutes,
            'action'          => $act,
            'status'          => \app\model\TimeoutRule::STATUS_ENABLED,
        ]);

        $this->operationLog('admin_timeout_rule_create', "创建超时规则: {$name}，超时{$timeoutMinutes}分钟");

        return $this->success($rule->toArray(), '超时规则创建成功');
    }

    /**
     * 更新规则
     */
    public function update(Request $request)
    {
        $id             = $request->paramInt('id', 0);
        $name           = $request->param('name', '');
        $orderType      = $request->paramInt('order_type', 0);
        $timeoutMinutes = $request->paramInt('timeout_minutes', 0);
        $act            = $request->paramInt('action', 0);

        if ($id <= 0) {
            return $this->error('规则ID无效');
        }

        $rule = \app\model\TimeoutRule::find($id);
        if (!$rule) {
            return $this->error('规则不存在', 404);
        }

        $changes = [];

        if (!empty($name)) {
            $rule->name = $name;
            $changes[] = "名称: {$name}";
        }
        if ($orderType > 0) {
            $rule->order_type = $orderType;
            $changes[] = "订单类型: {$orderType}";
        }
        if ($timeoutMinutes > 0) {
            $rule->timeout_minutes = $timeoutMinutes;
            $changes[] = "超时分钟: {$timeoutMinutes}";
        }
        if ($act > 0) {
            $validActions = [
                \app\model\TimeoutRule::ACTION_CANCEL,
                \app\model\TimeoutRule::ACTION_COMPLETE,
                \app\model\TimeoutRule::ACTION_NOTIFY,
            ];
            if (in_array($act, $validActions)) {
                $rule->action = $act;
                $changes[] = "动作: {$act}";
            }
        }

        $rule->save();

        $this->operationLog('admin_timeout_rule_update', "更新超时规则 ID:{$id}，" . implode(', ', $changes));

        return $this->success($rule->toArray(), '规则更新成功');
    }

    /**
     * 删除规则
     */
    public function delete(Request $request)
    {
        $id = $request->paramInt('id', 0);

        if ($id <= 0) {
            return $this->error('规则ID无效');
        }

        $rule = \app\model\TimeoutRule::find($id);
        if (!$rule) {
            return $this->error('规则不存在', 404);
        }

        $ruleName = $rule->getData('name');
        $rule->delete();

        $this->operationLog('admin_timeout_rule_delete', "删除超时规则: {$ruleName}，ID:{$id}");

        return $this->success(null, '规则已删除');
    }

    /**
     * 启用/禁用规则
     */
    public function toggle(Request $request)
    {
        $id = $request->paramInt('id', 0);

        if ($id <= 0) {
            return $this->error('规则ID无效');
        }

        $rule = \app\model\TimeoutRule::find($id);
        if (!$rule) {
            return $this->error('规则不存在', 404);
        }

        $oldStatus = $rule->getData('status');
        $newStatus = $oldStatus == \app\model\TimeoutRule::STATUS_ENABLED
            ? \app\model\TimeoutRule::STATUS_DISABLED
            : \app\model\TimeoutRule::STATUS_ENABLED;

        $rule->status = $newStatus;
        $rule->save();

        $statusText = $newStatus == \app\model\TimeoutRule::STATUS_ENABLED ? '启用' : '禁用';

        $this->operationLog('admin_timeout_rule_toggle', "{$statusText}超时规则: {$rule->getData('name')}，ID:{$id}");

        return $this->success([
            'id'     => $id,
            'status' => $newStatus,
        ], "规则已{$statusText}");
    }
}