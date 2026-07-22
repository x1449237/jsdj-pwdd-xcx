<?php
declare(strict_types=1);

namespace app\middleware;

/**
 * 跨域中间件 (CORS)
 */
class Cors
{
    /**
     * 处理请求
     * @param \think\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle(\think\Request $request, \Closure $next)
    {
        // 允许的域名列表
        $allowOrigin = $this->getAllowOrigin();

        // 处理 OPTIONS 预检请求
        if ($request->method() === 'OPTIONS') {
            return $this->handleOptions($allowOrigin);
        }

        // 正常请求添加 CORS 头
        $response = $next($request);

        return $this->setCorsHeaders($response, $allowOrigin);
    }

    /**
     * 处理 OPTIONS 预检请求
     * @param array $allowOrigin
     * @return \think\Response
     */
    protected function handleOptions(array $allowOrigin): \think\Response
    {
        $origin = request()->header('Origin', '*');

        $response = response('', 204);

        // 允许的源
        if (in_array($origin, $allowOrigin) || in_array('*', $allowOrigin)) {
            $response->header('Access-Control-Allow-Origin', $origin);
        } else {
            $response->header('Access-Control-Allow-Origin', $allowOrigin[0] ?? '*');
        }

        $response->header([
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Allow-Methods'     => 'GET, POST, PUT, DELETE, PATCH, OPTIONS',
            'Access-Control-Allow-Headers'     => 'Authorization, Content-Type, X-Requested-With, X-API-Version, X-New-Token, X-Trace-Id',
            'Access-Control-Max-Age'           => '86400',
            'Access-Control-Expose-Headers'    => 'X-New-Token, X-API-Version, X-Latest-API-Version, X-Trace-Id, Content-Disposition',
        ]);

        return $response;
    }

    /**
     * 设置 CORS 响应头
     * @param \think\Response $response
     * @param array $allowOrigin
     * @return \think\Response
     */
    protected function setCorsHeaders(\think\Response $response, array $allowOrigin): \think\Response
    {
        $origin = request()->header('Origin', '*');

        if (in_array($origin, $allowOrigin) || in_array('*', $allowOrigin)) {
            $response->header('Access-Control-Allow-Origin', $origin);
        } else {
            $response->header('Access-Control-Allow-Origin', $allowOrigin[0] ?? '*');
        }

        $response->header([
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Allow-Methods'     => 'GET, POST, PUT, DELETE, PATCH, OPTIONS',
            'Access-Control-Allow-Headers'     => 'Authorization, Content-Type, X-Requested-With, X-API-Version, X-New-Token, X-Trace-Id',
            'Access-Control-Expose-Headers'    => 'X-New-Token, X-API-Version, X-Latest-API-Version, X-Trace-Id, Content-Disposition',
        ]);

        return $response;
    }

    /**
     * 获取允许的跨域域名
     * @return array
     */
    protected function getAllowOrigin(): array
    {
        // 从环境变量或配置获取
        $domains = env('CORS_ALLOW_ORIGIN', '');

        if (!empty($domains)) {
            return explode(',', $domains);
        }

        // 默认允许的域名
        return [
            '*',
            'http://localhost:8080',
            'http://localhost:3000',
            'https://admin.example.com',
            'https://h5.example.com',
        ];
    }
}