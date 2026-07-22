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
 * 订单自动结算
 * 每1小时执行，检查T+3冻结到期的订单进行结算
 */
class OrderSettle extends Command
{
    protected function configure(): void
    {
        $this->setName('order:settle')
            ->setDescription('T+3冻结到期订单自动结算');
    }

    protected function execute(Input $input, Output $output): int
    {
        $startTime = microtime(true);
        $output->writeln("[" . date('Y-m-d H:i:s') . "] 开始执行订单自动结算...");

        try {
            $orderService = new OrderService();
            $count = $orderService->autoComplete();

            $elapsed = round(microtime(true) - $startTime, 3);
            $output->writeln("[" . date('Y-m-d H:i:s') . "] 订单自动结算完成，处理 {$count} 笔，耗时 {$elapsed}s");

            // 记录定时任务日志
            CronJobLog::create([
                'job_name'    => 'order:settle',
                'status'      => 1,
                'result'      => "处理 {$count} 笔T+3结算订单",
                'elapsed'     => $elapsed,
                'execute_time'=> date('Y-m-d H:i:s'),
            ]);

            Log::info("订单自动结算完成，处理 {$count} 笔，耗时 {$elapsed}s");
            return 0;
        } catch (\Throwable $e) {
            $output->writeln("<error>订单自动结算失败: {$e->getMessage()}</error>");

            CronJobLog::create([
                'job_name'    => 'order:settle',
                'status'      => 0,
                'result'      => $e->getMessage(),
                'elapsed'     => round(microtime(true) - $startTime, 3),
                'execute_time'=> date('Y-m-d H:i:s'),
            ]);

            Log::error("订单自动结算失败: {$e->getMessage()}");
            return 1;
        }
    }
}