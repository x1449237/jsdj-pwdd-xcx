<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseController;
use app\model\AppealCommunication;
use app\model\AppealReminder;
use app\model\PhoneAppeal;
use think\Request;

/**
 * 申诉管理控制器
 */
class Appeal extends BaseController
{
    /**
     * 申诉工单列表
     */
    public function list(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $status    = $request->param('status', '');
        $userId    = $request->paramInt('user_id', 0);
        $startDate = $request->param('start_date', '');
        $endDate   = $request->param('end_date', '');

        $query = PhoneAppeal::order('create_time', 'desc');

        if ($status !== '') {
            $query->where('status', (int)$status);
        }

        if ($userId > 0) {
            $query->where('user_id', $userId);
        }

        if (!empty($startDate)) {
            $query->where('create_time', '>=', $startDate . ' 00:00:00');
        }
        if (!empty($endDate)) {
            $query->where('create_time', '<=', $endDate . ' 23:59:59');
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        // 附加沟通记录数和催办记录
        foreach ($list as &$item) {
            $item['communication_count'] = AppealCommunication::where('appeal_id', $item['id'])->count();
            $reminder = AppealReminder::where('appeal_id', $item['id'])->find();
            $item['reminder_count'] = $reminder ? $reminder->getData('remind_count') : 0;
        }

        $this->operationLog('admin_appeal_list', '查看申诉工单列表');

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 申诉详情（含沟通记录）
     */
    public function detail(Request $request)
    {
        $id = $request->paramInt('id', 0);

        if ($id <= 0) {
            return $this->error('申诉ID无效');
        }

        $appeal = PhoneAppeal::find($id);
        if (!$appeal) {
            return $this->error('申诉不存在', 404);
        }

        $appealData = $appeal->toArray();

        // 沟通记录
        $appealData['communications'] = $appeal->communications()
            ->order('create_time', 'asc')
            ->select()
            ->toArray();

        // 催办记录
        $appealData['reminders'] = $appeal->reminders()
            ->order('create_time', 'desc')
            ->select()
            ->toArray();

        // 关联用户
        $appealData['user'] = $appeal->user()->find();

        $this->operationLog('admin_appeal_detail', "查看申诉详情 ID:{$id}");

        return $this->success($appealData);
    }

    /**
     * 发送沟通消息
     */
    public function communicate(Request $request)
    {
        $appealId    = $request->paramInt('appeal_id', 0);
        $content     = $request->param('content', '');
        $attachments = $request->param('attachments', '');

        if ($appealId <= 0) {
            return $this->error('申诉ID无效');
        }

        if (empty($content)) {
            return $this->error('沟通内容不能为空');
        }

        $appeal = PhoneAppeal::find($appealId);
        if (!$appeal) {
            return $this->error('申诉不存在', 404);
        }

        if ($appeal->getData('status') != PhoneAppeal::STATUS_PENDING) {
            return $this->error('该申诉已处理，不能发送沟通消息');
        }

        $attachArr = [];
        if (!empty($attachments)) {
            if (is_string($attachments)) {
                $attachArr = json_decode($attachments, true) ?: [];
            } else {
                $attachArr = $attachments;
            }
        }

        $communication = AppealCommunication::create([
            'appeal_id'   => $appealId,
            'sender_id'   => $this->adminId(),
            'sender_type' => AppealCommunication::SENDER_ADMIN,
            'content'     => $content,
            'attachments' => $attachArr,
        ]);

        $this->operationLog('admin_appeal_communicate', "发送申诉沟通消息，申诉ID:{$appealId}");

        return $this->success($communication->toArray(), '沟通消息已发送');
    }

    /**
     * 办结申诉
     */
    public function resolve(Request $request)
    {
        $id     = $request->paramInt('id', 0);
        $action = $request->param('action', ''); // pass/reject
        $remark = $request->param('remark', '');

        if ($id <= 0) {
            return $this->error('申诉ID无效');
        }

        $appeal = PhoneAppeal::find($id);
        if (!$appeal) {
            return $this->error('申诉不存在', 404);
        }

        if ($appeal->getData('status') != PhoneAppeal::STATUS_PENDING) {
            return $this->error('该申诉已办结');
        }

        if ($action === 'pass') {
            $appeal->status = PhoneAppeal::STATUS_PASSED;
            $appeal->review_remark = $remark ?: '申诉通过';
            $appeal->reviewer_id = $this->adminId();
            $appeal->review_time = date('Y-m-d H:i:s');
            $appeal->save();

            $this->operationLog('admin_appeal_pass', "申诉通过，ID:{$id}");
            return $this->success(null, '申诉已通过');
        } elseif ($action === 'reject') {
            if (empty($remark)) {
                return $this->error('驳回原因不能为空');
            }

            $appeal->status = PhoneAppeal::STATUS_REJECT;
            $appeal->review_remark = $remark;
            $appeal->reviewer_id = $this->adminId();
            $appeal->review_time = date('Y-m-d H:i:s');
            $appeal->save();

            $this->operationLog('admin_appeal_reject', "申诉驳回，ID:{$id}，原因: {$remark}");
            return $this->success(null, '申诉已驳回');
        } else {
            return $this->error('无效操作，可选: pass/reject');
        }
    }

    /**
     * 催办记录
     */
    public function reminders(Request $request)
    {
        $appealId = $request->paramInt('appeal_id', 0);

        if ($appealId <= 0) {
            return $this->error('申诉ID无效');
        }

        $appeal = PhoneAppeal::find($appealId);
        if (!$appeal) {
            return $this->error('申诉不存在', 404);
        }

        $reminders = $appeal->reminders()
            ->order('create_time', 'desc')
            ->select()
            ->toArray();

        $this->operationLog('admin_appeal_reminders', "查看催办记录，申诉ID:{$appealId}");

        return $this->success([
            'appeal_id' => $appealId,
            'reminders' => $reminders,
            'total'     => count($reminders),
        ]);
    }
}