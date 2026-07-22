<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseController;
use app\model\SystemConfig as SystemConfigModel;
use think\facade\Cache;
use think\Request;

/**
 * 系统配置控制器
 */
class SystemConfig extends BaseController
{
    /**
     * 配置列表
     */
    public function list(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $group   = $request->param('group', '');
        $keyword = $request->param('keyword', '');

        $query = \app\model\SystemConfig::order('id', 'asc');

        if (!empty($group)) {
            $query->where('group', $group);
        }

        if (!empty($keyword)) {
            $query->where(function ($q) use ($keyword) {
                $q->where('key', 'like', "%{$keyword}%")
                  ->whereOr('description', 'like', "%{$keyword}%");
            });
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        $this->operationLog('admin_system_config_list', '查看系统配置列表');

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 更新配置（热更新）
     * 抽成/大额阈值/实名开关/AI开关/客服微信号等
     */
    public function update(Request $request)
    {
        $configs = $request->param('configs', '');

        if (empty($configs)) {
            return $this->error('配置数据不能为空');
        }

        if (is_string($configs)) {
            $configs = json_decode($configs, true);
        }

        if (!is_array($configs) || empty($configs)) {
            return $this->error('配置数据格式错误');
        }

        $updated = [];
        $failed  = [];

        foreach ($configs as $item) {
            $key   = $item['key'] ?? '';
            $value = $item['value'] ?? '';
            $type  = $item['type'] ?? 'string';
            $group = $item['group'] ?? 'system';
            $description = $item['description'] ?? '';

            if (empty($key)) {
                continue;
            }

            try {
                $config = \app\model\SystemConfig::where('key', $key)->find();
                if ($config) {
                    $config->value = $value;
                    $config->type = $type;
                    $config->group = $group;
                    if (!empty($description)) {
                        $config->description = $description;
                    }
                    $config->save();
                } else {
                    \app\model\SystemConfig::create([
                        'key'         => $key,
                        'value'       => $value,
                        'type'        => $type,
                        'group'       => $group,
                        'description' => $description,
                    ]);
                }

                $updated[] = $key;

                // 热更新缓存
                $this->refreshCache($key, $value);
            } catch (\Throwable $e) {
                $failed[] = [
                    'key'   => $key,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $this->operationLog('admin_system_config_update', '更新系统配置: ' . implode(', ', $updated));

        return $this->success([
            'updated' => $updated,
            'failed'  => $failed,
            'count'   => count($updated),
        ], '配置更新成功');
    }

    /**
     * 获取单个配置
     */
    public function getConfig(Request $request)
    {
        $key = $request->param('key', '');

        if (empty($key)) {
            return $this->error('配置键不能为空');
        }

        $config = \app\model\SystemConfig::where('key', $key)->find();
        if (!$config) {
            return $this->error('配置不存在', 404);
        }

        return $this->success([
            'key'         => $config->getData('key'),
            'value'       => $config->value,
            'type'        => $config->getData('type'),
            'group'       => $config->getData('group'),
            'description' => $config->getData('description'),
            'update_time' => $config->getData('update_time'),
        ]);
    }

    /**
     * 热更新缓存
     * @param string $key
     * @param mixed  $value
     */
    private function refreshCache(string $key, $value): void
    {
        try {
            $cacheKey = 'system_config:' . $key;
            Cache::store('redis')->set($cacheKey, $value, 3600);
        } catch (\Throwable $e) {
            // 缓存更新失败不影响主流程
        }
    }
}