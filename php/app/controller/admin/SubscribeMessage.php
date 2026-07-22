<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\model\SubscribeMessageTemplate;
use app\model\SubscribeMessageLog;
use think\facade\Log;
use think\facade\Validate;

/**
 * 订阅消息模板管理
 */
class SubscribeMessage extends BaseController
{
    /**
     * 模板列表
     */
    public function templateList()
    {
        $page  = (int) $this->request->param('page', 1);
        $limit = (int) $this->request->param('limit', 20);
        $scene = $this->request->param('scene', '');

        $query = SubscribeMessageTemplate::order('sort', 'asc');
        if (!empty($scene)) {
            $query->where('scene', $scene);
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        return $this->page($list, $total);
    }

    /**
     * 创建模板
     */
    public function templateCreate()
    {
        $data = $this->request->post();

        $validate = Validate::rule([
            'template_id'   => 'require|max:64',
            'template_name' => 'require|max:64',
            'scene'         => 'require|max:32',
            'scene_name'    => 'require|max:64',
        ]);

        if (!$validate->check($data)) {
            return $this->error($validate->getError());
        }

        // 检查模板ID是否已存在
        $exists = SubscribeMessageTemplate::where('template_id', $data['template_id'])->find();
        if ($exists) {
            return $this->error('模板ID已存在');
        }

        $template = SubscribeMessageTemplate::create([
            'template_id'   => $data['template_id'],
            'template_name' => $data['template_name'],
            'scene'         => $data['scene'],
            'scene_name'    => $data['scene_name'],
            'fields'        => $data['fields'] ?? '{}',
            'is_enabled'    => $data['is_enabled'] ?? 1,
            'sort'          => $data['sort'] ?? 0,
        ]);

        write_action_log('subscribe_template_create', "创建订阅消息模板: {$data['template_name']}");

        return $this->success($template->toArray(), '创建成功');
    }

    /**
     * 更新模板
     */
    public function templateUpdate($id)
    {
        $template = SubscribeMessageTemplate::find($id);
        if (!$template) {
            return $this->error('模板不存在');
        }

        $data = $this->request->put();

        $template->save([
            'template_id'   => $data['template_id'] ?? $template->template_id,
            'template_name' => $data['template_name'] ?? $template->template_name,
            'scene'         => $data['scene'] ?? $template->scene,
            'scene_name'    => $data['scene_name'] ?? $template->scene_name,
            'fields'        => $data['fields'] ?? $template->fields,
            'is_enabled'    => $data['is_enabled'] ?? $template->is_enabled,
            'sort'          => $data['sort'] ?? $template->sort,
        ]);

        write_action_log('subscribe_template_update', "更新订阅消息模板: {$template->template_name}");

        return $this->success($template->toArray(), '更新成功');
    }

    /**
     * 删除模板
     */
    public function templateDelete($id)
    {
        $template = SubscribeMessageTemplate::find($id);
        if (!$template) {
            return $this->error('模板不存在');
        }

        $template->delete();

        write_action_log('subscribe_template_delete', "删除订阅消息模板: {$template->template_name}");

        return $this->success([], '删除成功');
    }

    /**
     * 切换启用状态
     */
    public function templateToggle($id)
    {
        $template = SubscribeMessageTemplate::find($id);
        if (!$template) {
            return $this->error('模板不存在');
        }

        $template->is_enabled = $template->is_enabled ? 0 : 1;
        $template->save();

        $status = $template->is_enabled ? '启用' : '禁用';
        write_action_log('subscribe_template_toggle', "{$status}订阅消息模板: {$template->template_name}");

        return $this->success(['is_enabled' => $template->is_enabled], "已{$status}");
    }

    /**
     * 发送日志列表
     */
    public function logList()
    {
        $page      = (int) $this->request->param('page', 1);
        $limit     = (int) $this->request->param('limit', 20);
        $userId    = $this->request->param('user_id', '');
        $scene     = $this->request->param('scene', '');
        $isSuccess = $this->request->param('is_success', '');

        $query = SubscribeMessageLog::order('create_time', 'desc');

        if (!empty($userId)) {
            $query->where('user_id', (int) $userId);
        }
        if (!empty($scene)) {
            $query->where('scene', $scene);
        }
        if ($isSuccess !== '') {
            $query->where('is_success', (int) $isSuccess);
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        return $this->page($list, $total);
    }
}