<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseController;
use app\model\AfterSaleKeyword;
use app\service\AfterSaleService;
use app\model\SystemConfig;
use think\facade\Log;
use think\Request;

/**
 * 售后关键词管理控制器
 */
class AfterSaleKeyword extends BaseController
{
    /**
     * 关键词列表
     */
    public function list(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $keyword = $request->param('keyword', '');
        $status  = $request->param('status', '');

        $query = AfterSaleKeyword::order('id', 'desc');

        if (!empty($keyword)) {
            $query->where('keyword', 'like', "%{$keyword}%");
        }
        if ($status !== '') {
            $query->where('status', (int)$status);
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        $this->operationLog('admin_after_sale_keyword_list', '查看售后关键词列表');

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 添加关键词
     */
    public function create(Request $request)
    {
        $keyword = $request->param('keyword', '');

        $error = $this->validateRequired([
            'keyword' => $keyword,
        ], ['keyword']);
        if ($error) {
            return $this->error($error);
        }

        // 检查关键词是否已存在
        $exist = AfterSaleKeyword::where('keyword', $keyword)->find();
        if ($exist) {
            return $this->error('该关键词已存在');
        }

        $record = AfterSaleKeyword::create([
            'keyword' => $keyword,
            'status'  => AfterSaleKeyword::STATUS_ENABLED,
        ]);

        $this->operationLog('admin_after_sale_keyword_create', "添加售后关键词: {$keyword}");

        return $this->success($record->toArray(), '关键词添加成功');
    }

    /**
     * 更新关键词
     */
    public function update(Request $request)
    {
        $id      = $request->paramInt('id', 0);
        $keyword = $request->param('keyword', '');

        $error = $this->validateRequired([
            'id'      => $id,
            'keyword' => $keyword,
        ], ['id', 'keyword']);
        if ($error) {
            return $this->error($error);
        }

        $record = AfterSaleKeyword::find($id);
        if (!$record) {
            return $this->error('关键词不存在', 404);
        }

        $record->keyword = $keyword;
        $record->save();

        $this->operationLog('admin_after_sale_keyword_update', "更新售后关键词: ID: {$id}, 新值: {$keyword}");

        return $this->success($record->toArray(), '关键词更新成功');
    }

    /**
     * 删除关键词
     */
    public function delete(Request $request)
    {
        $id = $request->paramInt('id', 0);

        if ($id <= 0) {
            return $this->error('关键词ID无效');
        }

        $record = AfterSaleKeyword::find($id);
        if (!$record) {
            return $this->error('关键词不存在', 404);
        }

        $record->delete();

        $this->operationLog('admin_after_sale_keyword_delete', "删除售后关键词: ID: {$id}");

        return $this->success(null, '关键词已删除');
    }

    /**
     * 切换启用状态
     */
    public function toggle(Request $request)
    {
        $id = $request->paramInt('id', 0);

        if ($id <= 0) {
            return $this->error('关键词ID无效');
        }

        $record = AfterSaleKeyword::find($id);
        if (!$record) {
            return $this->error('关键词不存在', 404);
        }

        $record->status = $record->status == AfterSaleKeyword::STATUS_ENABLED
            ? AfterSaleKeyword::STATUS_DISABLED
            : AfterSaleKeyword::STATUS_ENABLED;
        $record->save();

        $statusText = $record->status == AfterSaleKeyword::STATUS_ENABLED ? '启用' : '禁用';

        $this->operationLog('admin_after_sale_keyword_toggle', "切换售后关键词状态: ID: {$id}, 状态: {$statusText}");

        return $this->success($record->toArray(), "关键词已{$statusText}");
    }

    /**
     * 批量导入
     */
    public function batchImport(Request $request)
    {
        $keywords = $request->param('keywords', '');

        if (empty($keywords)) {
            return $this->error('关键词不能为空');
        }

        $keywordArr = explode("\n", $keywords);
        $keywordArr = array_map('trim', $keywordArr);
        $keywordArr = array_filter($keywordArr);

        $successCount = 0;
        $skipCount    = 0;

        foreach ($keywordArr as $kw) {
            if (empty($kw)) {
                continue;
            }
            $exist = AfterSaleKeyword::where('keyword', $kw)->find();
            if ($exist) {
                $skipCount++;
                continue;
            }
            AfterSaleKeyword::create([
                'keyword' => $kw,
                'status'  => AfterSaleKeyword::STATUS_ENABLED,
            ]);
            $successCount++;
        }

        $this->operationLog('admin_after_sale_keyword_batch_import', "批量导入售后关键词: 成功: {$successCount}, 跳过: {$skipCount}");

        return $this->success([
            'success_count' => $successCount,
            'skip_count'    => $skipCount,
        ], "导入完成，成功{$successCount}条，跳过{$skipCount}条");
    }

    /**
     * 测试匹配
     */
    public function testMatch(Request $request)
    {
        $content = $request->param('content', '');

        if (empty($content)) {
            return $this->error('测试内容不能为空');
        }

        $service = new AfterSaleService();
        $hitKeywords = $service->filterAfterSaleKeywords($content);

        return $this->success([
            'content'      => $content,
            'hit_keywords' => $hitKeywords,
            'is_hit'       => !empty($hitKeywords),
        ]);
    }

    /**
     * 开关总开关
     */
    public function toggleSwitch(Request $request)
    {
        $switchValue = $request->param('switch', '0');

        $config = SystemConfig::where('key', 'after_sale_keyword_switch')->find();
        if (!$config) {
            SystemConfig::create([
                'key'         => 'after_sale_keyword_switch',
                'value'       => $switchValue,
                'type'        => 'bool',
                'group'       => 'after_sale',
                'description' => '售后关键词自动触发总开关',
            ]);
        } else {
            $config->value = $switchValue;
            $config->save();
        }

        $statusText = $switchValue === '1' ? '开启' : '关闭';

        $this->operationLog('admin_after_sale_keyword_switch', "售后关键词总开关: {$statusText}");

        return $this->success(null, "售后关键词总开关已{$statusText}");
    }
}