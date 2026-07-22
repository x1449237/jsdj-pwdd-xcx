<?php
declare(strict_types=1);

namespace app\middleware;

use think\facade\Cache;
use think\facade\Log;

/**
 * API 版本控制中间件
 * 支持灰度发布策略
 */
class ApiVersion
{
    /**
     * 当前最新版本
     * @var string
     */
    protected $latestVersion = 'v1';

    /**
     * 灰度发布配置
     * @var array
     */
    protected $grayRelease = [
        'enabled'     => false,
        'version'     => 'v2',
        'percentage'  => 10,      // 灰度比例 10%
        'whitelist'   => [],      // 白名单用户ID
        'user_agent'  => [],      // 指定 User-Agent 匹配
    ];

    /**
     * 处理请求
     * @param \think\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle(\think\Request $request, \Closure $next)
    {
        // 从 URL 中提取版本号
        $version = $this->extractVersion($request);

        if (empty($version)) {
            return json([
                'code'     => 400,
                'msg'      => '缺少 API 版本号',
                'data'     => null,
                'trace_id' => trace_id(),
            ])->code(400);
        }

        // 验证版本号格式
        if (!$this->validateVersion($version)) {
            return json([
                'code'     => 400,
                'msg'      => 'API 版本号格式不正确，请使用 v1, v2 等格式',
                'data'     => null,
                'trace_id' => trace_id(),
            ])->code(400);
        }

        // 检查版本是否已废弃
        if ($this->isDeprecated($version)) {
            return json([
                'code'     => 410,
                'msg'      => '该 API 版本已废弃，请升级到最新版本 ' . $this->latestVersion,
                'data'     => [
                    'latest_version' => $this->latestVersion,
                ],
                'trace_id' => trace_id(),
            ])->code(410);
        }

        // 灰度发布逻辑
        if ($this->grayRelease['enabled'] && $this->shouldUseGrayVersion($request, $version)) {
            $version = $this->grayRelease['version'];
            Log::info("Gray release: user uses version {$version}, IP: " . $request->realIp());
        }

        // 设置当前版本到请求
        $request->setUserType($version);

        // 设置版本信息到响应头
        $response = $next($request);

        $response->header([
            'X-API-Version'         => $version,
            'X-Latest-API-Version'  => $this->latestVersion,
            'X-API-Deprecated'      => $this->isDeprecated($version) ? 'true' : 'false',
        ]);

        return $response;
    }

    /**
     * 从请求中提取版本号
     * @param \think\Request $request
     * @return string|null
     */
    protected function extractVersion(\think\Request $request): ?string
    {
        // 1. 从 URL 路径提取: /api/v1/xxx
        $path = $request->pathinfo();
        if (preg_match('#^api/(v\d+)/#', $path, $matches)) {
            return $matches[1];
        }

        // 2. 从 Header 提取
        $headerVersion = $request->header('X-API-Version', '');
        if (!empty($headerVersion)) {
            return $headerVersion;
        }

        // 3. 从 Query 参数提取
        $queryVersion = $request->param('version', '');
        if (!empty($queryVersion)) {
            return $queryVersion;
        }

        // 默认返回 v1
        return 'v1';
    }

    /**
     * 验证版本号格式
     * @param string $version
     * @return bool
     */
    protected function validateVersion(string $version): bool
    {
        return (bool) preg_match('/^v\d+$/', $version);
    }

    /**
     * 检查版本是否已废弃
     * @param string $version
     * @return bool
     */
    protected function isDeprecated(string $version): bool
    {
        $deprecatedVersions = [
            // 'v0' => true,  // 示例：v0 已废弃
        ];

        return isset($deprecatedVersions[$version]);
    }

    /**
     * 判断是否应该使用灰度版本
     * @param \think\Request $request
     * @param string $currentVersion
     * @return bool
     */
    protected function shouldUseGrayVersion(\think\Request $request, string $currentVersion): bool
    {
        // 如果当前版本已经是灰度版本，不需要处理
        if ($currentVersion === $this->grayRelease['version']) {
            return false;
        }

        // 白名单用户优先
        $userId = $request->userId() ?? 0;
        if (in_array($userId, $this->grayRelease['whitelist'])) {
            return true;
        }

        // User-Agent 匹配
        if (!empty($this->grayRelease['user_agent'])) {
            $ua = $request->header('user-agent', '');
            foreach ($this->grayRelease['user_agent'] as $pattern) {
                if (stripos($ua, $pattern) !== false) {
                    return true;
                }
            }
        }

        // 百分比灰度
        $hash = crc32($request->realIp() . $userId);
        $percent = abs($hash % 100);

        return $percent < $this->grayRelease['percentage'];
    }
}