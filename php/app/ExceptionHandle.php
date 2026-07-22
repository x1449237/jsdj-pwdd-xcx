<?php
declare(strict_types=1);

namespace app;

use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\exception\Handle;
use think\exception\HttpException;
use think\exception\HttpResponseException;
use think\exception\ValidateException;
use think\Response;
use Throwable;

/**
 * 全局异常处理 - 统一返回 JSON 格式
 */
class ExceptionHandle extends Handle
{
    /**
     * 不需要记录日志的异常类型
     * @var array
     */
    protected $ignoreReport = [
        HttpException::class,
        HttpResponseException::class,
        ModelNotFoundException::class,
        DataNotFoundException::class,
        ValidateException::class,
    ];

    /**
     * 记录异常日志
     * @param Throwable $exception
     */
    public function report(Throwable $exception): void
    {
        // 使用日志通道记录
        if (!$this->isIgnoreReport($exception)) {
            $data = [
                'file'    => $exception->getFile(),
                'line'    => $exception->getLine(),
                'message' => $this->getMessage($exception),
                'code'    => $exception->getCode(),
                'trace'   => $exception->getTraceAsString(),
            ];

            \think\facade\Log::channel('error')->record(
                $exception->getMessage(),
                'error',
                $data
            );
        }
    }

    /**
     * 渲染异常输出
     * @param \think\Request $request
     * @param Throwable $e
     * @return Response
     */
    public function render($request, Throwable $e): Response
    {
        // 生成 trace_id
        $traceId = trace_id();

        // 验证异常
        if ($e instanceof ValidateException) {
            return json([
                'code'     => 422,
                'msg'      => $e->getMessage(),
                'data'     => null,
                'trace_id' => $traceId,
            ])->code(422);
        }

        // HTTP 异常
        if ($e instanceof HttpException) {
            $statusCode = $e->getStatusCode();
            $message = $e->getMessage();

            $codeMap = [
                400 => '请求参数错误',
                401 => '未授权',
                403 => '禁止访问',
                404 => '资源不存在',
                405 => '请求方法不允许',
                429 => '请求过于频繁',
                500 => '服务器内部错误',
                502 => '网关错误',
                503 => '服务不可用',
            ];

            return json([
                'code'     => $statusCode,
                'msg'      => $message ?: ($codeMap[$statusCode] ?? '请求错误'),
                'data'     => null,
                'trace_id' => $traceId,
            ])->code($statusCode);
        }

        // 数据不存在异常
        if ($e instanceof ModelNotFoundException) {
            return json([
                'code'     => 404,
                'msg'      => '数据不存在',
                'data'     => null,
                'trace_id' => $traceId,
            ])->code(404);
        }

        if ($e instanceof DataNotFoundException) {
            return json([
                'code'     => 404,
                'msg'      => '数据未找到',
                'data'     => null,
                'trace_id' => $traceId,
            ])->code(404);
        }

        // 调试模式返回详细错误
        if (app()->isDebug()) {
            return json([
                'code'     => $e->getCode() ?: 500,
                'msg'      => $e->getMessage(),
                'data'     => [
                    'file'  => $e->getFile(),
                    'line'  => $e->getLine(),
                    'trace' => explode("\n", $e->getTraceAsString()),
                ],
                'trace_id' => $traceId,
            ])->code(500);
        }

        // 生产环境屏蔽详细错误
        return json([
            'code'     => 500,
            'msg'      => '服务器内部错误，请稍后重试',
            'data'     => null,
            'trace_id' => $traceId,
        ])->code(500);
    }
}