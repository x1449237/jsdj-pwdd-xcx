<?php
declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use app\service\PlayerDispatchService;
use app\model\CronJobLog;
use think\facade\Log;

/**
 * 打手活跃探针
 * 每5分钟执行，下发Ping-Pong，连续3次无响应标记非活跃
 */
class PlayerProbe extends Command
{
    protected function configure(): void
    {
        $this->setName('player:probe')
            ->setDescription('打手活跃探针，下发Ping-Pong检测活跃状态');
    }

    protected function execute(Input $input, Output $output): int
    {
        $startTime = microtime(true);
        $output->writeln("[" . date('Y-m-d H:i:s') . "] 开始执行打手活跃探针...");

        try {
            $dispatchService = new PlayerDispatchService();
            $inactivePlayers = $dispatchService->probeActive();

            $elapsed = round(microtime(true) - $startTime, 3);
            $inactiveCount = count($inactivePlayers);

            if ($inactiveCount > 0) {
                $output->writeln("<warning>标记非活跃打手: " . implode(', ', $inactivePlayers) . "</warning>");
            }

            $output->writeln("[" . date('Y-m-d H:i:s') . "] 打手活跃探针完成，非活跃 {$inactiveCount} 人，耗时 {$elapsed}s");

            // 记录定时任务日志
            CronJobLog::create([
                'job_name'    => 'player:probe',
                'status'      => 1,
                'result'      => "非活跃 {$inactiveCount} 人: " . implode(',', $inactivePlayers),
                'elapsed'     => $elapsed,
                'execute_time'=> date('Y-m-d H:i:s'),
            ]);

            Log::info("打手活跃探针完成，非活跃 {$inactiveCount} 人，耗时 {$elapsed}s");
            return 0;
        } catch (\Throwable $e) {
            $output->writeln("<error>打手活跃探针失败: {$e->getMessage()}</error>");

            CronJobLog::create([
                'job_name'    => 'player:probe',
                'status'      => 0,
                'result'      => $e->getMessage(),
                'elapsed'     => round(microtime(true) - $startTime, 3),
                'execute_time'=> date('Y-m-d H:i:s'),
            ]);

            Log::error("打手活跃探针失败: {$e->getMessage()}");
            return 1;
        }
    }
}