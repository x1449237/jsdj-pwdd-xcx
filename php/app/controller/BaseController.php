<?php
declare(strict_types=1);

namespace app\controller;

use think\facade\Log;
use think\Response;

/**
 * 基础控制器
 * 所有控制器继承此类，提供统一响应方法和通用功能
 */
class BaseController
{
    /**
     * 成功响应
     * @param mixed  $data
     * @param string $msg
     * @param int    $code
     * @return Response
     */
    protected function success($data = null, string $msg = '成功', int $code = 200): Response
    {
        return json([
            'code'     => $code,
            'msg'      => $msg,
            'data'     => $data,
            'trace_id' => trace_id(),
        ]);
    }

    /**
     * 错误响应
     * @param string $msg
     * @param int    $code
     * @param mixed  $data
     * @param int    $httpCode
     * @return Response
     */
    protected function error(string $msg = '失败', int $code = 400, $data = null, int $httpCode = 200): Response
    {
        return json([
            'code'     => $code,
            'msg'      => $msg,
            'data'     => $data,
            'trace_id' => trace_id(),
        ])->code($httpCode);
    }

    /**
     * JSON 响应（兼容 api_success/api_error 函数）
     * @param mixed  $data
     * @param string $msg
     * @param int    $code
     * @return Response
     */
    protected function json($data = null, string $msg = '成功', int $code = 200): Response
    {
        return $this->success($data, $msg, $code);
    }

    /**
     * 分页响应
     * @param mixed $list
     * @param int   $total
     * @param int   $page
     * @param int   $limit
     * @return Response
     */
    protected function page($list, int $total, int $page = 1, int $limit = 15): Response
    {
        return api_page($list, $total, $page, $limit);
    }

    /**
     * 获取当前管理员ID
     * @return int
     */
    protected function adminId(): int
    {
        return request()->adminId() ?? 0;
    }

    /**
     * 获取当前管理员信息
     * @return array|null
     */
    protected function adminInfo(): ?array
    {
        $adminId = $this->adminId();
        if ($adminId <= 0) {
            return null;
        }

        $admin = \app\model\Admin::find($adminId);
        if (!$admin) {
            return null;
        }

        return $admin->hidden(['password'])->toArray();
    }

    /**
     * 获取分页参数
     * @return array [page, limit]
     */
    protected function pageParams(): array
    {
        return request()->pageParams();
    }

    /**
     * 记录操作日志
     * @param string $action
     * @param string $content
     * @param array  $extra
     */
    protected function operationLog(string $action, string $content, array $extra = []): void
    {
        try {
            $data = array_merge([
                'admin_id'    => $this->adminId(),
                'action'      => $action,
                'content'     => $content,
                'ip'          => get_client_ip(),
                'url'         => request()->url(),
                'method'      => request()->method(),
                'user_agent'  => request()->header('user-agent', ''),
                'request_data'=> json_encode(request()->param(), JSON_UNESCAPED_UNICODE),
                'create_time' => date('Y-m-d H:i:s'),
            ], $extra);

            \app\model\OperationLog::create($data);
        } catch (\Throwable $e) {
            Log::error('操作日志写入失败: ' . $e->getMessage());
        }
    }

    /**
     * 验证必填参数
     * @param array $params
     * @param array $required
     * @return string|null 返回空表示验证通过，否则返回错误信息
     */
    protected function validateRequired(array $params, array $required): ?string
    {
        foreach ($required as $field) {
            if (!isset($params[$field]) || $params[$field] === '' || $params[$field] === null) {
                return "参数 {$field} 不能为空";
            }
        }
        return null;
    }

    /**
     * 密码强度验证
     * 新密码需≥8位，包含大写、小写、数字、特殊字符至少三种
     * @param string $password
     * @return bool
     */
    protected function validatePasswordStrength(string $password): bool
    {
        if (strlen($password) < 8) {
            return false;
        }

        $types = 0;
        if (preg_match('/[A-Z]/', $password)) {
            $types++;
        }
        if (preg_match('/[a-z]/', $password)) {
            $types++;
        }
        if (preg_match('/[0-9]/', $password)) {
            $types++;
        }
        if (preg_match('/[^A-Za-z0-9]/', $password)) {
            $types++;
        }

        return $types >= 3;
    }
}