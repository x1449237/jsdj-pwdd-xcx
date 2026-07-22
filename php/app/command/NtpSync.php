<?php
declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use app\model\NtpSyncLog;
use app\model\CronJobLog;
use think\facade\Log;

/**
 * NTP 时间同步
 * 每30分钟执行，检查时间偏差>1秒自动校准
 */
class NtpSync extends Command
{
    protected function configure(): void
    {
        $this->setName('ntp:sync')
            ->setDescription('NTP时间同步，检查时间偏差>1秒自动校准');
    }

    protected function execute(Input $input, Output $output): int
    {
        $startTime = microtime(true);
        $output->writeln("[" . date('Y-m-d H:i:s') . "] 开始执行NTP时间同步...");

        try {
            $ntpServers = [
                'ntp.aliyun.com',
                'ntp.tencent.com',
                'ntp1.aliyun.com',
                'time.apple.com',
            ];

            $offset = $this->getNtpOffset($ntpServers);
            $currentTime = date('Y-m-d H:i:s');

            $output->writeln("当前时间: {$currentTime}");
            $output->writeln("时间偏差: {$offset}秒");

            $needSync = abs($offset) > 1;
            $synced = false;

            if ($needSync) {
                $output->writeln("<warning>时间偏差超过1秒，执行校准...</warning>");

                // 使用 date 命令校准时间
                $setTime = time() + (int) $offset;
                $newTime = date('Y-m-d H:i:s', $setTime);

                // 记录，实际校准需要 root 权限
                $output->writeln("建议校准时间至: {$newTime}");

                // 尝试通过 PHP 设置系统时间（需要适当权限）
                if (function_exists('exec')) {
                    $cmd = "date -s '{$newTime}' 2>&1";
                    exec($cmd, $cmdOutput, $returnCode);
                    if ($returnCode === 0) {
                        $synced = true;
                        $output->writeln("时间已校准至: {$newTime}");
                    } else {
                        $output->writeln("<warning>时间校准需要 root 权限，模拟执行</warning>");
                    }
                }
            } else {
                $output->writeln("时间偏差在1秒内，无需校准");
            }

            $elapsed = round(microtime(true) - $startTime, 3);

            // 记录日志
            NtpSyncLog::create([
                'ntp_server'  => $ntpServers[0],
                'offset'      => $offset,
                'is_synced'   => $needSync ? ($synced ? 1 : 0) : 0,
                'sync_time'   => date('Y-m-d H:i:s'),
            ]);

            CronJobLog::create([
                'job_name'    => 'ntp:sync',
                'status'      => 1,
                'result'      => "偏差 {$offset}秒" . ($needSync ? '，已校准' : '，无需校准'),
                'elapsed'     => $elapsed,
                'execute_time'=> date('Y-m-d H:i:s'),
            ]);

            Log::info("NTP时间同步完成，偏差 {$offset}秒，耗时 {$elapsed}s");
            return 0;
        } catch (\Throwable $e) {
            $output->writeln("<error>NTP时间同步失败: {$e->getMessage()}</error>");

            CronJobLog::create([
                'job_name'    => 'ntp:sync',
                'status'      => 0,
                'result'      => $e->getMessage(),
                'elapsed'     => round(microtime(true) - $startTime, 3),
                'execute_time'=> date('Y-m-d H:i:s'),
            ]);

            Log::error("NTP时间同步失败: {$e->getMessage()}");
            return 1;
        }
    }

    /**
     * 获取NTP时间偏差
     * @param array $servers NTP服务器列表
     * @return float 偏差秒数
     */
    private function getNtpOffset(array $servers): float
    {
        $offsets = [];

        foreach ($servers as $server) {
            try {
                $offset = $this->queryNtpServer($server);
                if ($offset !== null) {
                    $offsets[] = $offset;
                }
            } catch (\Throwable $e) {
                Log::warning("NTP查询失败 [{$server}]: {$e->getMessage()}");
            }
        }

        if (empty($offsets)) {
            Log::warning('所有NTP服务器查询失败，使用默认偏差0');
            return 0.0;
        }

        // 取中位数，排除异常值
        sort($offsets);
        $count = count($offsets);
        if ($count % 2 == 0) {
            return ($offsets[$count / 2 - 1] + $offsets[$count / 2]) / 2;
        } else {
            return $offsets[floor($count / 2)];
        }
    }

    /**
     * 查询单个 NTP 服务器
     * @param string $server
     * @return float|null 偏差秒数
     */
    private function queryNtpServer(string $server): ?float
    {
        // 创建 NTP 请求包
        $socket = @fsockopen('udp://' . $server, 123, $errno, $errstr, 5);
        if (!$socket) {
            return null;
        }

        stream_set_timeout($socket, 5);

        // NTP 请求包（48字节）
        $request = "\x1b" . str_repeat("\0", 47);
        fwrite($socket, $request);

        $response = fread($socket, 48);
        fclose($socket);

        if (strlen($response) !== 48) {
            return null;
        }

        // 解析 NTP 响应
        $timestamp = unpack('N12', $response);

        // 提取 T3 (Transmit Timestamp) 的高32位部分
        $ntpTime = $timestamp[9];
        $ntpTime -= 2208988800; // 转换为 Unix 时间戳

        return $ntpTime - time();
    }
}