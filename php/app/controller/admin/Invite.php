<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseController;
use app\model\InviteCode;
use app\model\ExportLog;
use think\Request;

/**
 * 邀请码管理控制器
 */
class Invite extends BaseController
{
    /**
     * 生成邀请码
     * 绑定角色：打手/分销商/派单员/内置管理员
     */
    public function generate(Request $request)
    {
        $count    = $request->paramInt('count', 1);
        $maxUse   = $request->paramInt('max_use', 0); // 0表示不限
        $roleType = $request->param('role_type', 'player'); // player/distributor/dispatcher/admin
        $expireDays = $request->paramInt('expire_days', 30); // 默认30天过期

        $count = min(max($count, 1), 100);

        $allowedRoles = ['player', 'distributor', 'dispatcher', 'admin'];
        if (!in_array($roleType, $allowedRoles)) {
            return $this->error('角色类型无效，可选: player/distributor/dispatcher/admin');
        }

        $expireTime = $expireDays > 0 ? date('Y-m-d H:i:s', time() + $expireDays * 86400) : null;

        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $code = $this->generateUniqueCode();
            $inviteCode = InviteCode::create([
                'code'        => $code,
                'creator_id'  => $this->adminId(),
                'use_count'   => 0,
                'max_use'     => $maxUse,
                'status'      => InviteCode::STATUS_ENABLED,
                'role_type'   => $roleType,
                'expire_time' => $expireTime,
            ]);
            $codes[] = $inviteCode->toArray();
        }

        $this->operationLog('admin_invite_generate', "生成{$count}个邀请码，角色: {$roleType}");

        return $this->success([
            'count' => $count,
            'codes' => $codes,
        ], "成功生成{$count}个邀请码");
    }

    /**
     * 邀请码列表
     */
    public function list(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $keyword  = $request->param('keyword', '');
        $status   = $request->param('status', '');
        $roleType = $request->param('role_type', '');

        $query = InviteCode::order('id', 'desc');

        if (!empty($keyword)) {
            $query->where('code', 'like', "%{$keyword}%");
        }

        if ($status !== '') {
            $query->where('status', (int)$status);
        }

        if (!empty($roleType)) {
            $query->where('role_type', $roleType);
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        $this->operationLog('admin_invite_list', '查看邀请码列表');

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 作废邀请码
     */
    public function void(Request $request)
    {
        $id = $request->paramInt('id', 0);

        if ($id <= 0) {
            return $this->error('邀请码ID无效');
        }

        $inviteCode = InviteCode::find($id);
        if (!$inviteCode) {
            return $this->error('邀请码不存在', 404);
        }

        if ($inviteCode->getData('status') == InviteCode::STATUS_DISABLED) {
            return $this->error('邀请码已作废');
        }

        $inviteCode->status = InviteCode::STATUS_DISABLED;
        $inviteCode->save();

        $this->operationLog('admin_invite_void', "作废邀请码: {$inviteCode->code}");

        return $this->success(null, '邀请码已作废');
    }

    /**
     * 导出邀请码
     */
    public function export(Request $request)
    {
        $status   = $request->param('status', '');
        $roleType = $request->param('role_type', '');

        $query = InviteCode::order('id', 'desc');

        if ($status !== '') {
            $query->where('status', (int)$status);
        }

        if (!empty($roleType)) {
            $query->where('role_type', $roleType);
        }

        $totalCount = $query->count();
        $list = $query->select()->toArray();

        // 生成文件
        $fileName = 'invite_codes_' . date('YmdHis') . '.csv';
        $filePath = runtime_path() . 'export/' . $fileName;

        $exportDir = dirname($filePath);
        if (!is_dir($exportDir)) {
            mkdir($exportDir, 0755, true);
        }

        $fp = fopen($filePath, 'w');
        fwrite($fp, "\xEF\xBB\xBF");

        $headers = ['ID', '邀请码', '角色类型', '使用次数', '最大使用次数', '状态', '过期时间', '创建时间'];
        fputcsv($fp, $headers);

        foreach ($list as $row) {
            fputcsv($fp, [
                $row['id'] ?? '',
                $row['code'] ?? '',
                $row['role_type'] ?? '',
                $row['use_count'] ?? '',
                $row['max_use'] ?? '',
                $row['status'] == 1 ? '正常' : '已作废',
                $row['expire_time'] ?? '',
                $row['create_time'] ?? '',
            ]);
        }
        fclose($fp);

        ExportLog::create([
            'admin_id'    => $this->adminId(),
            'export_type' => 'invite_codes',
            'file_name'   => $fileName,
            'file_path'   => $filePath,
            'total_count' => $totalCount,
            'status'      => ExportLog::STATUS_SUCCESS,
            'params'      => json_encode(['status' => $status, 'role_type' => $roleType], JSON_UNESCAPED_UNICODE),
        ]);

        $this->operationLog('admin_invite_export', "导出邀请码，共{$totalCount}条");

        return $this->success([
            'file_name'   => $fileName,
            'total_count' => $totalCount,
        ], '导出成功');
    }

    /**
     * 生成唯一邀请码
     * @return string
     */
    private function generateUniqueCode(): string
    {
        do {
            $code = strtoupper(substr(md5(uniqid((string)random_int(0, 999999), true)), 0, 8));
            $exist = InviteCode::where('code', $code)->find();
        } while ($exist);

        return $code;
    }
}