<?php
declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use app\service\OrderService;
use app\model\CronJobLog;
use think\facade\Log;

/**
 * 订单超时检查
 * 每1分钟执行，检查超时订单并自动扭转状态
 */
class OrderTimeout extends Command
{
    protected function configure(): void
    {
        $this->setName('order:timeout')
            ->setDescription('检查超时订单并自动扭转状态');
    }

    protected function execute(Input $input, Output $output): int
    {
        $startTime = microtime(true);
        $output->writeln("[" . date('Y-m-d H:i:s') . "] 开始执行订单超时检查...");

        try {
            $orderService = new OrderService();
            $count = $orderService->checkTimeout();

            $elapsed = round(microtime(true) - $startTime, 3);
            $output->writeln("[" . date('Y-m-d H:i:s') . "] 订单超时检查完成，处理 {$count} 笔，耗时 {$elapsed}s");

            // 记录定时任务日志
            CronJobLog::create([
                'job_name'    => 'order:timeout',
                'status'      => 1,
                'result'      => "处理 {$count} 笔超时订单",
                'elapsed'     => $elapsed,
                'execute_time'=> date('Y-m-d H:i:s'),
            ]);

            Log::info("订单超时检查完成，处理 {$count} 笔，耗时 {$elapsed}s");
            return 0;
        } catch (\Throwable $e) {
            $output->writeln("<error>订单超时检查失败: {$e->getMessage()}</error>");

            CronJobLog::create([
                'job_name'    => 'order:timeout',
                'status'      => 0,
                'result'      => $e->getMessage(),
                'elapsed'     => round(microtime(true) - $startTime, 3),
                'execute_time'=> date('Y-m-d H:i:s'),
            ]);

            Log::error("订单超时检查失败: {$e->getMessage()}");
            return 1;
        }
    }
}