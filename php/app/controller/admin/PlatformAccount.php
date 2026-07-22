<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseController;
use app\model\PlatformAccount;
use app\service\PlatformService;
use think\facade\Log;
use think\Request;

/**
 * 平台账号管理控制器
 */
class PlatformAccount extends BaseController
{
    /**
     * 平台官方账号列表
     */
    public function list(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $status = $request->param('status', '');

        $query = PlatformAccount::order('id', 'desc');

        if ($status !== '') {
            $query->where('status', (int)$status);
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        $this->operationLog('admin_platform_account_list', '查看平台官方账号列表');

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 创建平台官方账号
     */
    public function create(Request $request)
    {
        $adminId  = $this->adminId();
        $nickname = $request->param('nickname', '');
        $avatar   = $request->param('avatar', '');

        $error = $this->validateRequired([
            'nickname' => $nickname,
        ], ['nickname']);
        if ($error) {
            return $this->error($error);
        }

        try {
            $service = new PlatformService();
            $account = $service->createOfficialAccount($nickname, $avatar, $adminId);

            $this->operationLog('admin_platform_account_create', "创建平台官方账号: {$nickname}");

            return $this->success($account, '平台官方账号创建成功');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('创建平台官方账号异常: ' . $e->getMessage());
            return $this->error('创建失败');
        }
    }

    /**
     * 停用账号
     */
    public function disable(Request $request)
    {
        $accountId = $request->paramInt('id', 0);

        if ($accountId <= 0) {
            return $this->error('账号ID无效');
        }

        try {
            $service = new PlatformService();
            $service->disableAccount($accountId);

            $this->operationLog('admin_platform_account_disable', "停用平台官方账号: ID: {$accountId}");

            return $this->success(null, '账号已停用');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('停用平台官方账号异常: ' . $e->getMessage());
            return $this->error('操作失败');
        }
    }

    /**
     * 更新昵称
     */
    public function updateNickname(Request $request)
    {
        $accountId = $request->paramInt('id', 0);
        $nickname  = $request->param('nickname', '');

        $error = $this->validateRequired([
            'id'       => $accountId,
            'nickname' => $nickname,
        ], ['id', 'nickname']);
        if ($error) {
            return $this->error($error);
        }

        $account = PlatformAccount::find($accountId);
        if (!$account) {
            return $this->error('平台官方账号不存在', 404);
        }

        $account->nickname = $nickname;
        $account->save();

        $this->operationLog('admin_platform_account_nickname', "更新平台官方账号昵称: ID: {$accountId}, 昵称: {$nickname}");

        return $this->success($account->toArray(), '昵称更新成功');
    }
}