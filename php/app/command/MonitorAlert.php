<?php
declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use app\model\MonitorAlert;
use app\model\CronJobLog;
use think\facade\Log;

/**
 * 监控告警
 * WebSocket连接数 > 80%告警
 * 磁盘使用率 > 75%告警
 * Workerman版本校验
 */
class MonitorAlert extends Command
{
    /**
     * WebSocket 连接数告警阈值
     */
    private const WS_CONNECTION_THRESHOLD = 0.80;

    /**
     * 磁盘使用率告警阈值
     */
    private const DISK_USAGE_THRESHOLD = 0.75;

    /**
     * 最大连接数
     */
    private const MAX_CONNECTIONS = 10000;

    protected function configure(): void
    {
        $this->setName('monitor:alert')
            ->setDescription('监控告警：WebSocket连接数、磁盘使用率、Workerman版本校验');
    }

    protected function execute(Input $input, Output $output): int
    {
        $startTime = microtime(true);
        $output->writeln("[" . date('Y-m-d H:i:s') . "] 开始执行监控告警检查...");

        try {
            $alerts = [];

            // 1. WebSocket 连接数检查
            $wsAlert = $this->checkWsConnections();
            if ($wsAlert) {
                $alerts[] = $wsAlert;
            }

            // 2. 磁盘使用率检查
            $diskAlert = $this->checkDiskUsage();
            if ($diskAlert) {
                $alerts[] = $diskAlert;
            }

            // 3. Workerman 版本校验
            $versionAlert = $this->checkWorkermanVersion();
            if ($versionAlert) {
                $alerts[] = $versionAlert;
            }

            $elapsed = round(microtime(true) - $startTime, 3);

            if (!empty($alerts)) {
                $output->writeln("<warning>发现 " . count($alerts) . " 条告警:</warning>");
                foreach ($alerts as $alert) {
                    $output->writeln("  - {$alert['type']}: {$alert['message']}");
                }
            } else {
                $output->writeln("系统运行正常，无告警");
            }

            $output->writeln("[" . date('Y-m-d H:i:s') . "] 监控告警检查完成，告警 " . count($alerts) . " 条，耗时 {$elapsed}s");

            CronJobLog::create([
                'job_name'    => 'monitor:alert',
                'status'      => 1,
                'result'      => "告警 " . count($alerts) . " 条: " . json_encode($alerts, JSON_UNESCAPED_UNICODE),
                'elapsed'     => $elapsed,
                'execute_time'=> date('Y-m-d H:i:s'),
            ]);

            Log::info("监控告警检查完成，告警 " . count($alerts) . " 条");
            return 0;
        } catch (\Throwable $e) {
            $output->writeln("<error>监控告警检查失败: {$e->getMessage()}</error>");

            CronJobLog::create([
                'job_name'    => 'monitor:alert',
                'status'      => 0,
                'result'      => $e->getMessage(),
                'elapsed'     => round(microtime(true) - $startTime, 3),
                'execute_time'=> date('Y-m-d H:i:s'),
            ]);

            Log::error("监控告警检查失败: {$e->getMessage()}");
            return 1;
        }
    }

    /**
     * 检查 WebSocket 连接数
     * @return array|null
     */
    private function checkWsConnections(): ?array
    {
        try {
            $redis = get_redis();
            $connectionCount = $redis->sCard('ws:online:users');

            $usageRate = $connectionCount / self::MAX_CONNECTIONS;

            if ($usageRate >= self::WS_CONNECTION_THRESHOLD) {
                $message = "WebSocket连接数 {$connectionCount}/" . self::MAX_CONNECTIONS . " (" . round($usageRate * 100, 1) . "%)，超过80%阈值";

                // 记录告警
                MonitorAlert::create([
                    'alert_type'  => 'ws_connections',
                    'alert_level' => $usageRate >= 0.95 ? 'critical' : 'warning',
                    'message'     => $message,
                    'metric'      => $connectionCount,
                    'threshold'   => self::MAX_CONNECTIONS * self::WS_CONNECTION_THRESHOLD,
                ]);

                Log::warning($message);

                return [
                    'type'    => 'ws_connections',
                    'level'   => $usageRate >= 0.95 ? 'critical' : 'warning',
                    'message' => $message,
                ];
            }

            return null;
        } catch (\Throwable $e) {
            Log::error("WebSocket连接数检查失败: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * 检查磁盘使用率
     * @return array|null
     */
    private function checkDiskUsage(): ?array
    {
        try {
            $diskPath = runtime_path();
            $free = disk_free_space($diskPath);
            $total = disk_total_space($diskPath);

            if ($free === false || $total === false) {
                return null;
            }

            $usageRate = 1 - ($free / $total);

            if ($usageRate >= self::DISK_USAGE_THRESHOLD) {
                $freeGB = round($free / 1024 / 1024 / 1024, 2);
                $totalGB = round($total / 1024 / 1024 / 1024, 2);
                $message = "磁盘使用率 " . round($usageRate * 100, 1) . "% (剩余 {$freeGB}GB / {$totalGB}GB)，超过75%阈值";

                // 记录告警
                MonitorAlert::create([
                    'alert_type'  => 'disk_usage',
                    'alert_level' => $usageRate >= 0.90 ? 'critical' : 'warning',
                    'message'     => $message,
                    'metric'      => round($usageRate * 100, 1),
                    'threshold'   => self::DISK_USAGE_THRESHOLD * 100,
                ]);

                Log::warning($message);

                return [
                    'type'    => 'disk_usage',
                    'level'   => $usageRate >= 0.90 ? 'critical' : 'warning',
                    'message' => $message,
                ];
            }

            return null;
        } catch (\Throwable $e) {
            Log::error("磁盘使用率检查失败: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * 检查 Workerman 版本
     * @return array|null
     */
    private function checkWorkermanVersion(): ?array
    {
        try {
            $installedVersion = \Workerman\Worker::VERSION ?? '0.0.0';

            // 检查是否安装了 composer.lock 中的版本
            $composerLock = file_get_contents(root_path('composer.lock'));
            if ($composerLock) {
                $lockData = json_decode($composerLock, true);
                $expectedVersion = '';

                foreach ($lockData['packages'] ?? [] as $package) {
                    if ($package['name'] === 'workerman/workerman') {
                        $expectedVersion = ltrim($package['version'] ?? '', 'v');
                        break;
                    }
                }

                if ($expectedVersion && version_compare($installedVersion, $expectedVersion, '<')) {
                    $message = "Workerman版本不匹配: 安装={$installedVersion}, 期望={$expectedVersion}";

                    MonitorAlert::create([
                        'alert_type'  => 'workerman_version',
                        'alert_level' => 'warning',
                        'message'     => $message,
                        'metric'      => $installedVersion,
                        'threshold'   => $expectedVersion,
                    ]);

                    Log::warning($message);

                    return [
                        'type'    => 'workerman_version',
                        'level'   => 'warning',
                        'message' => $message,
                    ];
                }
            }

            return null;
        } catch (\Throwable $e) {
            Log::error("Workerman版本检查失败: {$e->getMessage()}");
            return null;
        }
    }
}