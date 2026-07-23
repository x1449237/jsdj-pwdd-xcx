<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseController;
use app\model\MarketingCouponTemplate;
use think\Request;

/**
 * 活动运营控制器
 */
class Activity extends BaseController
{
    /**
     * 活动运营总览
     */
    public function index(Request $request)
    {
        $couponCount = MarketingCouponTemplate::count();
        $activeCouponCount = MarketingCouponTemplate::where('status', 1)->count();

        $todayStart = date('Y-m-d 00:00:00');
        $todayEnd = date('Y-m-d 23:59:59');

        $stats = [
            'coupon_total'  => $couponCount,
            'coupon_active' => $activeCouponCount,
        ];

        $this->operationLog('admin_activity', '查看活动运营总览');

        return $this->success($stats);
    }

    /**
     * 优惠券模板列表
     */
    public function couponList(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $type   = $request->param('type', '');
        $status = $request->param('status', '');
        $keyword = $request->param('keyword', '');

        $query = MarketingCouponTemplate::latest();

        if (!empty($type)) {
            $query->byType($type);
        }
        if ($status !== '') {
            $query->byStatus((int)$status);
        }
        if (!empty($keyword)) {
            $query->where('name', 'like', "%{$keyword}%");
        }

        $total = $query->count();
        $list = $query->page($page, $limit)->select()->toArray();

        $this->operationLog('admin_activity_coupon', '查看优惠券模板列表');

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 创建优惠券模板
     */
    public function couponCreate(Request $request)
    {
        $name          = $request->param('name', '');
        $type          = $request->param('type', 'discount');
        $discountType  = $request->param('discount_type', 'fixed');
        $discountValue = $request->param('discount_value', 0);
        $minAmount     = $request->param('min_amount', 0);
        $totalCount    = $request->paramInt('total_count', 0);
        $perUserLimit  = $request->paramInt('per_user_limit', 1);
        $validType     = $request->param('valid_type', 'fixed');
        $validDays     = $request->paramInt('valid_days', 0);
        $startTime     = $request->param('start_time', '');
        $endTime       = $request->param('end_time', '');
        $description   = $request->param('description', '');

        if (empty($name)) {
            return $this->error('优惠券名称不能为空');
        }

        $coupon = MarketingCouponTemplate::create([
            'name'           => $name,
            'type'           => $type,
            'discount_type'  => $discountType,
            'discount_value' => $discountValue,
            'min_amount'     => yuan_to_fen((string)$minAmount),
            'total_count'    => $totalCount,
            'per_user_limit' => $perUserLimit,
            'valid_type'     => $validType,
            'valid_days'     => $validDays,
            'start_time'     => $startTime,
            'end_time'       => $endTime,
            'description'    => $description,
            'status'         => 1,
            'creator_id'     => $this->adminId(),
        ]);

        $this->operationLog('admin_activity_coupon_create', "创建优惠券模板: {$name}");

        return $this->success(['id' => $coupon->id], '创建成功');
    }

    /**
     * 更新优惠券模板
     */
    public function couponUpdate(Request $request)
    {
        $id = $request->paramInt('id', 0);

        if ($id <= 0) {
            return $this->error('优惠券ID无效');
        }

        $coupon = MarketingCouponTemplate::find($id);
        if (!$coupon) {
            return $this->error('优惠券模板不存在');
        }

        $data = [];
        $fields = ['name', 'type', 'discount_type', 'min_amount', 'total_count',
            'per_user_limit', 'valid_type', 'valid_days', 'start_time', 'end_time', 'description'];

        foreach ($fields as $field) {
            if ($request->has($field)) {
                $value = $request->param($field);
                if ($field === 'min_amount') {
                    $value = yuan_to_fen((string)$value);
                }
                if ($field === 'discount_value') {
                    $value = (float)$value;
                }
                $data[$field] = $value;
            }
        }

        if (!empty($data)) {
            $coupon->save($data);
        }

        $this->operationLog('admin_activity_coupon_update', "更新优惠券模板 ID:{$id}");

        return $this->success(null, '更新成功');
    }

    /**
     * 切换优惠券状态
     */
    public function couponToggle(Request $request)
    {
        $id     = $request->paramInt('id', 0);
        $status = $request->paramInt('status', 0);

        if ($id <= 0) {
            return $this->error('优惠券ID无效');
        }

        $coupon = MarketingCouponTemplate::find($id);
        if (!$coupon) {
            return $this->error('优惠券模板不存在');
        }

        $coupon->status = $status;
        $coupon->save();

        $statusText = $status == 1 ? '启用' : '关闭';
        $this->operationLog('admin_activity_coupon_toggle', "{$statusText}优惠券 ID:{$id}");

        return $this->success(null, '操作成功');
    }

    /**
     * 删除优惠券模板
     */
    public function couponDelete(Request $request)
    {
        $id = $request->paramInt('id', 0);

        if ($id <= 0) {
            return $this->error('优惠券ID无效');
        }

        $coupon = MarketingCouponTemplate::find($id);
        if (!$coupon) {
            return $this->error('优惠券模板不存在');
        }

        $coupon->delete();

        $this->operationLog('admin_activity_coupon_delete', "删除优惠券模板 ID:{$id}");

        return $this->success(null, '删除成功');
    }
}
