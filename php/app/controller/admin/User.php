<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseController;
use app\model\ExportLog;
use app\model\InviteBindLog;
use app\model\RiskUser;
use app\model\User as UserModel;
use think\facade\Log;
use think\Request;

/**
 * 用户管理控制器
 */
class User extends BaseController
{
    /**
     * 用户列表（分页、搜索、筛选）
     * 筛选：所有/普通/大额验证/风险
     */
    public function list(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $keyword  = $request->param('keyword', '');
        $status   = $request->param('status', '');
        $userType = $request->param('user_type', '');
        $filter   = $request->param('filter', 'all'); // all/normal/large_verify/risk
        $startDate = $request->param('start_date', '');
        $endDate   = $request->param('end_date', '');

        $query = UserModel::order('id', 'desc');

        // 关键词搜索
        if (!empty($keyword)) {
            $query->where(function ($q) use ($keyword) {
                $q->where('nickname', 'like', "%{$keyword}%")
                  ->whereOr('phone', 'like', "%{$keyword}%")
                  ->whereOr('real_name', 'like', "%{$keyword}%");
            });
        }

        // 状态筛选
        if ($status !== '') {
            $query->where('status', (int)$status);
        }

        // 用户类型筛选
        if ($userType !== '') {
            $query->where('user_type', (int)$userType);
        }

        // 筛选条件
        switch ($filter) {
            case 'risk':
                $riskUserIds = RiskUserModel::where('status', RiskUserModel::STATUS_UNPROCESSED)
                    ->column('user_id');
                if (!empty($riskUserIds)) {
                    $query->whereIn('id', $riskUserIds);
                } else {
                    $query->where('id', 0);
                }
                break;
            case 'large_verify':
                // 大额验证用户：有进行中大额订单的用户
                $largeOrderUserIds = \app\model\Order::where('order_amount', '>=', \app\model\Order::setOrderAmountAttr(new \think\Model, '500'))
                    ->where('status', 'in', [\app\model\Order::STATUS_PAID, \app\model\Order::STATUS_PLAYING])
                    ->column('user_id');
                if (!empty($largeOrderUserIds)) {
                    $query->whereIn('id', $largeOrderUserIds);
                } else {
                    $query->where('id', 0);
                }
                break;
        }

        // 时间范围
        if (!empty($startDate)) {
            $query->where('create_time', '>=', $startDate . ' 00:00:00');
        }
        if (!empty($endDate)) {
            $query->where('create_time', '<=', $endDate . ' 23:59:59');
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->hidden(['openid', 'unionid', 'id_card'])->toArray();

        $this->operationLog('admin_user_list', '查看用户列表');

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 用户详情
     */
    public function detail(Request $request)
    {
        $id = $request->paramInt('id', 0);
        if ($id <= 0) {
            return $this->error('用户ID无效');
        }

        $user = UserModel::find($id);
        if (!$user) {
            return $this->error('用户不存在', 404);
        }

        $userData = $user->hidden(['openid', 'unionid'])->toArray();

        // 关联数据
        $userData['order_count']    = $user->orders()->count();
        $userData['withdraw_count'] = $user->withdraws()->count();
        $userData['invite_bind']    = $user->inviteBindLogs()->order('create_time', 'desc')->find();
        $userData['risk_info']      = $user->riskUser()->find();

        $this->operationLog('admin_user_detail', "查看用户详情 ID:{$id}");

        return $this->success($userData);
    }

    /**
     * 封禁用户
     */
    public function ban(Request $request)
    {
        $id     = $request->paramInt('id', 0);
        $reason = $request->param('reason', '');

        if ($id <= 0) {
            return $this->error('用户ID无效');
        }

        $user = UserModel::find($id);
        if (!$user) {
            return $this->error('用户不存在', 404);
        }

        if ($user->getData('status') == UserModel::STATUS_DISABLED) {
            return $this->error('用户已被封禁');
        }

        $user->status = UserModel::STATUS_DISABLED;
        $user->save();

        $this->operationLog('admin_user_ban', "封禁用户 ID:{$id}，原因: {$reason}");

        return $this->success(null, '用户已封禁');
    }

    /**
     * 解封用户
     */
    public function unban(Request $request)
    {
        $id = $request->paramInt('id', 0);

        if ($id <= 0) {
            return $this->error('用户ID无效');
        }

        $user = UserModel::find($id);
        if (!$user) {
            return $this->error('用户不存在', 404);
        }

        if ($user->getData('status') == UserModel::STATUS_ENABLED) {
            return $this->error('用户状态正常，无需解封');
        }

        $user->status = UserModel::STATUS_ENABLED;
        $user->save();

        $this->operationLog('admin_user_unban', "解封用户 ID:{$id}");

        return $this->success(null, '用户已解封');
    }

    /**
     * 导出用户数据（含隐形水印+export_log审计）
     */
    public function export(Request $request)
    {
        $keyword  = $request->param('keyword', '');
        $status   = $request->param('status', '');
        $userType = $request->param('user_type', '');
        $filter   = $request->param('filter', 'all');
        $startDate = $request->param('start_date', '');
        $endDate   = $request->param('end_date', '');

        $query = UserModel::order('id', 'desc');

        if (!empty($keyword)) {
            $query->where(function ($q) use ($keyword) {
                $q->where('nickname', 'like', "%{$keyword}%")
                  ->whereOr('phone', 'like', "%{$keyword}%")
                  ->whereOr('real_name', 'like', "%{$keyword}%");
            });
        }

        if ($status !== '') {
            $query->where('status', (int)$status);
        }

        if ($userType !== '') {
            $query->where('user_type', (int)$userType);
        }

        if ($filter === 'risk') {
            $riskUserIds = RiskUserModel::where('status', RiskUserModel::STATUS_UNPROCESSED)->column('user_id');
            if (!empty($riskUserIds)) {
                $query->whereIn('id', $riskUserIds);
            } else {
                $query->where('id', 0);
            }
        }

        if (!empty($startDate)) {
            $query->where('create_time', '>=', $startDate . ' 00:00:00');
        }
        if (!empty($endDate)) {
            $query->where('create_time', '<=', $endDate . ' 23:59:59');
        }

        $totalCount = $query->count();
        $users = $query->select()->hidden(['openid', 'unionid', 'id_card'])->toArray();

        // 生成带隐形水印的数据
        $watermarkData = $this->addInvisibleWatermark($users, $this->adminId());

        // 生成导出文件
        $fileName = 'users_export_' . date('YmdHis') . '_' . $this->adminId() . '.csv';
        $filePath = runtime_path() . 'export/' . $fileName;

        // 确保目录存在
        $exportDir = dirname($filePath);
        if (!is_dir($exportDir)) {
            mkdir($exportDir, 0755, true);
        }

        // 写入 CSV
        $fp = fopen($filePath, 'w');
        fwrite($fp, "\xEF\xBB\xBF"); // BOM for UTF-8 in Excel

        // 写入表头
        $headers = ['ID', '昵称', '手机号', '真实姓名', '性别', '用户类型', '等级', '余额', '积分', '状态', '注册时间', '最后登录时间'];
        fputcsv($fp, $headers);

        // 写入数据
        foreach ($watermarkData as $row) {
            fputcsv($fp, [
                $row['id'] ?? '',
                $row['nickname'] ?? '',
                $row['phone'] ?? '',
                $row['real_name'] ?? '',
                $row['gender'] ?? '',
                $row['user_type'] ?? '',
                $row['level'] ?? '',
                $row['balance'] ?? '',
                $row['points'] ?? '',
                $row['status'] ?? '',
                $row['create_time'] ?? '',
                $row['last_login_time'] ?? '',
            ]);
        }
        fclose($fp);

        // 记录导出日志
        ExportLog::create([
            'admin_id'    => $this->adminId(),
            'export_type' => 'users',
            'file_name'   => $fileName,
            'file_path'   => $filePath,
            'total_count' => $totalCount,
            'status'      => ExportLog::STATUS_SUCCESS,
            'params'      => json_encode([
                'keyword'  => $keyword,
                'status'   => $status,
                'user_type'=> $userType,
                'filter'   => $filter,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $this->operationLog('admin_user_export', "导出用户数据，共{$totalCount}条");

        return $this->success([
            'file_name'   => $fileName,
            'total_count' => $totalCount,
            'download_url'=> '/admin/export/download?file=' . urlencode($fileName),
        ], '导出成功');
    }

    /**
     * 强制解绑邀请码关系
     */
    public function forceUnbind(Request $request)
    {
        $userId = $request->paramInt('user_id', 0);

        if ($userId <= 0) {
            return $this->error('用户ID无效');
        }

        $user = UserModel::find($userId);
        if (!$user) {
            return $this->error('用户不存在', 404);
        }

        $bindLog = InviteBindLog::where('user_id', $userId)->order('create_time', 'desc')->find();
        if (!$bindLog) {
            return $this->error('该用户没有邀请码绑定关系');
        }

        $bindLog->delete();

        $this->operationLog('admin_force_unbind', "强制解绑用户 ID:{$userId} 的邀请码关系");

        return $this->success(null, '邀请码关系已解绑');
    }

    /**
     * 添加隐形水印
     * 将导出管理员ID和导出时间嵌入到数据中
     * @param array $data
     * @param int   $adminId
     * @return array
     */
    private function addInvisibleWatermark(array $data, int $adminId): array
    {
        $watermark = base64_encode(json_encode([
            'admin_id'    => $adminId,
            'export_time' => date('Y-m-d H:i:s'),
            'ip'          => get_client_ip(),
        ]));

        // 在数据末尾添加不可见的水印行
        $data[] = [
            'id'               => 'WATERMARK',
            'nickname'         => $watermark,
            'phone'            => '',
            'real_name'        => '',
            'gender'           => '',
            'user_type'        => '',
            'level'            => '',
            'balance'          => '',
            'points'           => '',
            'status'           => '',
            'create_time'      => '',
            'last_login_time'  => '',
        ];

        return $data;
    }
}