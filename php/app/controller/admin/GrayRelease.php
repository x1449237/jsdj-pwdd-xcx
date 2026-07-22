<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseController;
use app\model\GrayRelease as GrayReleaseModel;
use think\facade\Cache;
use think\Request;

/**
 * 灰度发布控制器
 */
class GrayRelease extends BaseController
{
    /**
     * 灰度发布列表
     */
    public function list(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $status = $request->param('status', '');
        $keyword = $request->param('keyword', '');

        $query = \app\model\GrayReleaseModel::order('id', 'desc');

        if ($status !== '') {
            $query->where('status', (int)$status);
        }

        if (!empty($keyword)) {
            $query->where(function ($q) use ($keyword) {
                $q->where('feature_key', 'like', "%{$keyword}%")
                  ->whereOr('feature_name', 'like', "%{$keyword}%");
            });
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        $this->operationLog('admin_gray_release_list', '查看灰度发布列表');

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 创建灰度发布
     */
    public function create(Request $request)
    {
        $featureKey  = $request->param('feature_key', '');
        $featureName = $request->param('feature_name', '');
        $percentage  = $request->paramInt('percentage', 0);
        $whiteList   = $request->param('white_list', '');
        $blackList   = $request->param('black_list', '');

        if (empty($featureKey) || empty($featureName)) {
            return $this->error('功能标识和名称不能为空');
        }

        if ($percentage < 0 || $percentage > 100) {
            return $this->error('灰度比例必须在0-100之间');
        }

        // 检查功能标识唯一性
        $exist = \app\model\GrayReleaseModel::where('feature_key', $featureKey)->find();
        if ($exist) {
            return $this->error('该功能标识已存在');
        }

        $whiteListArr = [];
        if (!empty($whiteList)) {
            $whiteListArr = is_string($whiteList) ? explode(',', $whiteList) : $whiteList;
            $whiteListArr = array_map('intval', $whiteListArr);
        }

        $blackListArr = [];
        if (!empty($blackList)) {
            $blackListArr = is_string($blackList) ? explode(',', $blackList) : $blackList;
            $blackListArr = array_map('intval', $blackListArr);
        }

        $grayRelease = \app\model\GrayReleaseModel::create([
            'feature_key'  => $featureKey,
            'feature_name' => $featureName,
            'percentage'   => $percentage,
            'white_list'   => $whiteListArr,
            'black_list'   => $blackListArr,
            'status'       => GrayReleaseModel::STATUS_GRAY,
        ]);

        // 更新缓存
        $this->refreshGrayCache($featureKey, $grayRelease->toArray());

        $this->operationLog('admin_gray_release_create', "创建灰度发布: {$featureName}，比例: {$percentage}%");

        return $this->success($grayRelease->toArray(), '灰度发布创建成功');
    }

    /**
     * 更新灰度配置
     */
    public function update(Request $request)
    {
        $id          = $request->paramInt('id', 0);
        $featureName = $request->param('feature_name', '');
        $percentage  = $request->param('percentage', '');
        $whiteList   = $request->param('white_list', '');
        $blackList   = $request->param('black_list', '');
        $status      = $request->param('status', '');

        if ($id <= 0) {
            return $this->error('灰度发布ID无效');
        }

        $grayRelease = \app\model\GrayReleaseModel::find($id);
        if (!$grayRelease) {
            return $this->error('灰度发布不存在', 404);
        }

        $changes = [];

        if (!empty($featureName)) {
            $grayRelease->feature_name = $featureName;
            $changes[] = "名称: {$featureName}";
        }

        if ($percentage !== '') {
            $p = (int)$percentage;
            if ($p < 0 || $p > 100) {
                return $this->error('灰度比例必须在0-100之间');
            }
            $grayRelease->percentage = $p;
            $changes[] = "比例: {$p}%";
        }

        if ($whiteList !== '') {
            $whiteListArr = is_string($whiteList) ? explode(',', $whiteList) : $whiteList;
            $grayRelease->white_list = array_map('intval', $whiteListArr);
            $changes[] = "白名单已更新";
        }

        if ($blackList !== '') {
            $blackListArr = is_string($blackList) ? explode(',', $blackList) : $blackList;
            $grayRelease->black_list = array_map('intval', $blackListArr);
            $changes[] = "黑名单已更新";
        }

        if ($status !== '') {
            $validStatuses = [
                GrayReleaseModel::STATUS_CLOSED,
                GrayReleaseModel::STATUS_GRAY,
                GrayReleaseModel::STATUS_FULL,
            ];
            if (in_array((int)$status, $validStatuses)) {
                $grayRelease->status = (int)$status;
                $statusNames = [
                    GrayReleaseModel::STATUS_CLOSED => '关闭',
                    GrayReleaseModel::STATUS_GRAY   => '灰度中',
                    GrayReleaseModel::STATUS_FULL   => '全量',
                ];
                $changes[] = "状态: {$statusNames[(int)$status]}";
            }
        }

        $grayRelease->save();

        // 更新缓存
        $this->refreshGrayCache($grayRelease->getData('feature_key'), $grayRelease->toArray());

        $this->operationLog('admin_gray_release_update', "更新灰度发布 ID:{$id}，" . implode(', ', $changes));

        return $this->success($grayRelease->toArray(), '灰度发布更新成功');
    }

    /**
     * 回滚
     */
    public function rollback(Request $request)
    {
        $id = $request->paramInt('id', 0);

        if ($id <= 0) {
            return $this->error('灰度发布ID无效');
        }

        $grayRelease = \app\model\GrayReleaseModel::find($id);
        if (!$grayRelease) {
            return $this->error('灰度发布不存在', 404);
        }

        $featureKey = $grayRelease->getData('feature_key');

        // 关闭灰度
        $grayRelease->status = GrayReleaseModel::STATUS_CLOSED;
        $grayRelease->percentage = 0;
        $grayRelease->save();

        // 清除缓存
        $this->clearGrayCache($featureKey);

        $this->operationLog('admin_gray_release_rollback', "回滚灰度发布: {$grayRelease->getData('feature_name')}，ID:{$id}");

        return $this->success(null, '灰度发布已回滚');
    }

    /**
     * 查看灰度状态
     */
    public function status(Request $request)
    {
        $featureKey = $request->param('feature_key', '');
        $userId     = $request->paramInt('user_id', 0);

        if (empty($featureKey)) {
            return $this->error('功能标识不能为空');
        }

        $grayRelease = \app\model\GrayReleaseModel::where('feature_key', $featureKey)->find();

        if (!$grayRelease) {
            return $this->error('灰度发布不存在', 404);
        }

        $result = $grayRelease->toArray();

        // 如果提供了用户ID，检查该用户是否在灰度中
        if ($userId > 0) {
            $result['user_in_gray'] = $grayRelease->isUserInGray($userId);
        }

        $this->operationLog('admin_gray_release_status', "查看灰度状态: {$featureKey}");

        return $this->success($result);
    }

    /**
     * 刷新灰度缓存
     * @param string $featureKey
     * @param array  $data
     */
    private function refreshGrayCache(string $featureKey, array $data): void
    {
        try {
            $cacheKey = 'gray_release:' . $featureKey;
            Cache::store('redis')->set($cacheKey, json_encode($data, JSON_UNESCAPED_UNICODE), 3600);
        } catch (\Throwable $e) {
            // 缓存更新失败不影响主流程
        }
    }

    /**
     * 清除灰度缓存
     * @param string $featureKey
     */
    private function clearGrayCache(string $featureKey): void
    {
        try {
            $cacheKey = 'gray_release:' . $featureKey;
            Cache::store('redis')->delete($cacheKey);
        } catch (\Throwable $e) {
            // 忽略
        }
    }
}