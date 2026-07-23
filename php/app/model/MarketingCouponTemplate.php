<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 优惠券模板模型
 * @property int    $id
 * @property string $name
 * @property string $type
 * @property string $discount_type
 * @property float  $discount_value
 * @property int    $min_amount
 * @property int    $total_count
 * @property int    $used_count
 * @property int    $receive_count
 * @property int    $per_user_limit
 * @property string $valid_type
 * @property int    $valid_days
 * @property string $start_time
 * @property string $end_time
 * @property string $apply_scope
 * @property array  $scope_ids
 * @property string $description
 * @property int    $status
 * @property int    $creator_id
 * @property string $create_time
 * @property string $update_time
 */
class MarketingCouponTemplate extends Model
{
    protected $name = 'marketing_coupon_template';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const STATUS_DISABLED = 0;
    const STATUS_ENABLED = 1;
    const STATUS_EXPIRED = 2;
    const STATUS_CLOSED = 3;

    const TYPE_DISCOUNT = 'discount';
    const TYPE_COUPON = 'coupon';
    const TYPE_FREE_SHIPPING = 'free_shipping';

    public function getScopeIdsAttr($value): array
    {
        return json_decode($value, true) ?: [];
    }

    public function setScopeIdsAttr($value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    public function creator()
    {
        return $this->belongsTo(Admin::class, 'creator_id', 'id');
    }

    public function scopeByType($query, string $type)
    {
        $query->where('type', $type);
    }

    public function scopeByStatus($query, int $status)
    {
        $query->where('status', $status);
    }

    public function scopeActive($query)
    {
        $query->where('status', self::STATUS_ENABLED);
    }

    public function scopeLatest($query)
    {
        $query->order('create_time', 'desc');
    }
}
