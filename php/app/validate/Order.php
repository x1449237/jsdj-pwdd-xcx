<?php
declare(strict_types=1);

namespace app\validate;

use think\Validate;

/**
 * 订单验证器
 */
class Order extends Validate
{
    /**
     * 验证规则
     * @var array
     */
    protected $rule = [
        // 创建订单
        'player_service_id' => 'require|integer|gt:0',
        'game_name'         => 'require|max:100',
        'order_amount'      => 'require|float|gt:0',
        'payment_method'    => 'require|in:wechat,alipay,balance',
        'remark'            => 'max:500',

        // 评价
        'order_id'          => 'require|integer|gt:0',
        'rating'            => 'require|integer|between:1,5',
        'content'           => 'max:1000',
        'tags'              => 'array|max:5',
        'is_anonymous'      => 'integer|in:0,1',

        // 打赏
        'amount'            => 'require|float|gt:0',
        'message'           => 'max:200',
    ];

    /**
     * 验证提示信息
     * @var array
     */
    protected $message = [
        'player_service_id.require' => '打手服务ID不能为空',
        'player_service_id.integer' => '打手服务ID必须为整数',
        'player_service_id.gt'      => '打手服务ID必须大于0',
        'game_name.require'         => '游戏名称不能为空',
        'game_name.max'             => '游戏名称最多100个字符',
        'order_amount.require'      => '订单金额不能为空',
        'order_amount.float'        => '订单金额必须为数字',
        'order_amount.gt'           => '订单金额必须大于0',
        'payment_method.require'    => '支付方式不能为空',
        'payment_method.in'         => '支付方式仅支持: wechat, alipay, balance',
        'remark.max'                => '订单备注最多500个字符',

        'order_id.require'          => '订单ID不能为空',
        'order_id.integer'          => '订单ID必须为整数',
        'order_id.gt'               => '订单ID必须大于0',
        'rating.require'            => '评分不能为空',
        'rating.integer'            => '评分必须为整数',
        'rating.between'            => '评分必须在1-5之间',
        'content.max'               => '评价内容最多1000个字符',
        'tags.array'                => '标签格式不正确',
        'tags.max'                  => '标签最多5个',
        'is_anonymous.integer'      => '是否匿名必须为整数',
        'is_anonymous.in'           => '是否匿名只能为0或1',

        'amount.require'            => '打赏金额不能为空',
        'amount.float'              => '打赏金额必须为数字',
        'amount.gt'                 => '打赏金额必须大于0',
        'message.max'               => '打赏留言最多200个字符',
    ];

    /**
     * 验证场景
     * @var array
     */
    protected $scene = [
        // 创建订单场景
        'create' => ['player_service_id', 'game_name', 'order_amount', 'payment_method', 'remark'],

        // 评价场景
        'evaluate' => ['order_id', 'rating', 'content', 'tags', 'is_anonymous'],

        // 打赏场景
        'reward' => ['order_id', 'amount', 'message'],
    ];

    /**
     * 自定义金额范围校验（不能为负数）
     * @param string $value
     * @param string $rule
     * @param array  $data
     * @return string|true
     */
    protected function checkAmountPositive(string $value, string $rule, array $data = [])
    {
        if (!is_numeric($value) || (float) $value <= 0) {
            return '金额必须大于0';
        }

        // 金额上限：999999.99
        if ((float) $value > 999999.99) {
            return '金额不能超过999999.99';
        }

        return true;
    }

    /**
     * 自定义订单金额校验（精确到分）
     * @param string $value
     * @param string $rule
     * @param array  $data
     * @return string|true
     */
    protected function checkAmountPrecision(string $value, string $rule, array $data = [])
    {
        if (!is_numeric($value)) {
            return '订单金额必须为数字';
        }

        // 金额最多两位小数
        if (preg_match('/^\d+\.\d{3,}$/', $value)) {
            return '订单金额最多保留两位小数';
        }

        return true;
    }

    /**
     * 创建订单场景
     * @return Order
     */
    public function sceneCreate(): Order
    {
        return $this->only(['player_service_id', 'game_name', 'order_amount', 'payment_method', 'remark'])
            ->append('order_amount', 'checkAmountPositive')
            ->append('order_amount', 'checkAmountPrecision');
    }

    /**
     * 评价场景
     * @return Order
     */
    public function sceneEvaluate(): Order
    {
        return $this->only(['order_id', 'rating', 'content', 'tags', 'is_anonymous']);
    }

    /**
     * 打赏场景
     * @return Order
     */
    public function sceneReward(): Order
    {
        return $this->only(['order_id', 'amount', 'message'])
            ->append('amount', 'checkAmountPositive');
    }
}