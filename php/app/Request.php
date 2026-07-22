<?php
declare(strict_types=1);

namespace app;

/**
 * 自定义请求类
 * 扩展 ThinkPHP 内置请求，增加常用方法
 */
class Request extends \think\Request
{
    /**
     * 当前用户ID（由 Auth 中间件设置）
     * @var int|null
     */
    protected $userId = null;

    /**
     * 当前管理员ID（由 Auth 中间件设置）
     * @var int|null
     */
    protected $adminId = null;

    /**
     * 用户类型：user / admin
     * @var string|null
     */
    protected $userType = null;

    /**
     * 请求 trace_id
     * @var string
     */
    protected $traceId = '';

    /**
     * 获取 trace_id
     * @return string
     */
    public function traceId(): string
    {
        if (empty($this->traceId)) {
            $this->traceId = trace_id();
        }
        return $this->traceId;
    }

    /**
     * 设置 trace_id
     * @param string $traceId
     */
    public function setTraceId(string $traceId): void
    {
        $this->traceId = $traceId;
    }

    /**
     * 获取当前用户ID
     * @return int|null
     */
    public function userId(): ?int
    {
        return $this->userId;
    }

    /**
     * 设置当前用户ID
     * @param int $userId
     */
    public function setUserId(int $userId): void
    {
        $this->userId = $userId;
    }

    /**
     * 获取当前管理员ID
     * @return int|null
     */
    public function adminId(): ?int
    {
        return $this->adminId;
    }

    /**
     * 设置当前管理员ID
     * @param int $adminId
     */
    public function setAdminId(int $adminId): void
    {
        $this->adminId = $adminId;
    }

    /**
     * 获取用户类型
     * @return string|null
     */
    public function userType(): ?string
    {
        return $this->userType;
    }

    /**
     * 设置用户类型
     * @param string $type
     */
    public function setUserType(string $type): void
    {
        $this->userType = $type;
    }

    /**
     * 获取分页参数
     * @return array [page, limit]
     */
    public function pageParams(): array
    {
        $page  = (int) $this->param('page', 1);
        $limit = (int) $this->param('limit', 15);

        $page  = max($page, 1);
        $limit = min(max($limit, 1), 100);

        return [$page, $limit];
    }

    /**
     * 获取排序参数
     * @return array
     */
    public function orderParams(): array
    {
        $field = $this->param('order_field', 'id');
        $order = strtoupper($this->param('order_type', 'DESC'));

        // 白名单校验
        $allowedFields = ['id', 'create_time', 'update_time', 'sort'];
        if (!in_array($field, $allowedFields)) {
            $field = 'id';
        }
        if (!in_array($order, ['ASC', 'DESC'])) {
            $order = 'DESC';
        }

        return [$field, $order];
    }

    /**
     * 获取请求来源 IP
     * @return string
     */
    public function realIp(): string
    {
        if ($this->server('HTTP_X_FORWARDED_FOR')) {
            $ips = explode(',', $this->server('HTTP_X_FORWARDED_FOR'));
            return trim($ips[0]);
        }
        if ($this->server('HTTP_X_REAL_IP')) {
            return $this->server('HTTP_X_REAL_IP');
        }
        return $this->ip();
    }

    /**
     * 判断是否为 API 请求
     * @return bool
     */
    public function isApi(): bool
    {
        return strpos($this->url(), 'api/') !== false;
    }

    /**
     * 获取安全的整型参数
     * @param string $name
     * @param int $default
     * @return int
     */
    public function paramInt(string $name, int $default = 0): int
    {
        return (int) $this->param($name, $default);
    }

    /**
     * 获取安全的浮点型参数
     * @param string $name
     * @param float $default
     * @return float
     */
    public function paramFloat(string $name, float $default = 0.0): float
    {
        return (float) $this->param($name, $default);
    }
}