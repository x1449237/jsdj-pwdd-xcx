<?php
declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Argument;
use think\console\input\Option;
use app\queue\JobHandler;
use think\facade\Log;

class QueueWorker extends Command
{
    private $running = true;
    private $processedCount = 0;
    private $failedCount = 0;

    protected function configure(): void
    {
        $this->setName('queue:worker')
            ->setDescription('队列Worker进程')
            ->addArgument('queue', Argument::OPTIONAL, '队列名称', 'order_settle')
            ->addOption('sleep', 's', Option::VALUE_OPTIONAL, '空闲时休眠时间（秒）', 3)
            ->addOption('max-jobs', 'm', Option::VALUE_OPTIONAL, '最大处理任务数（0为无限）', 0)
            ->addOption('timeout', 't', Option::VALUE_OPTIONAL, '超时时间（秒）', 0);
    }

    protected function execute(Input $input, Output $output): int
    {
        $queue = $input->getArgument('queue');
        $sleep = (int)$input->getOption('sleep');
        $maxJobs = (int)$input->getOption('max-jobs');
        $timeout = (int)$input->getOption('timeout');

        $output->writeln("[" . date('Y-m-d H:i:s') . "] 队列Worker启动");
        $output->writeln("  队列: {$queue}");
        $output->writeln("  休眠时间: {$sleep}s");
        $output->writeln("  最大任务数: " . ($maxJobs ?: '无限'));
        $output->writeln("  超时时间: " . ($timeout ?: '无限') . "s");
        $output->writeln(str_repeat('=', 50));

        $jobHandler = new JobHandler();
        $startTime = time();

        pcntl_signal(SIGTERM, function () use ($output) {
            $this->running = false;
            $output->writeln("\n[" . date('Y-m-d H:i:s') . "] 收到停止信号，优雅退出...");
        });

        pcntl_signal(SIGINT, function () use ($output) {
            $this->running = false;
            $output->writeln("\n[" . date('Y-m-d H:i:s') . "] 收到中断信号，优雅退出...");
        });

        while ($this->running) {
            pcntl_signal_dispatch();

            if ($timeout > 0 && (time() - $startTime) >= $timeout) {
                $output->writeln("[" . date('Y-m-d H:i:s') . "] 达到超时时间，退出");
                break;
            }

            if ($maxJobs > 0 && $this->processedCount >= $maxJobs) {
                $output->writeln("[" . date('Y-m-d H:i:s') . "] 达到最大任务数，退出");
                break;
            }

            try {
                $result = $jobHandler->process($queue);

                if ($result) {
                    $this->processedCount++;
                    $output->writeln("[" . date('Y-m-d H:i:s') . "] 任务处理成功 (已处理: {$this->processedCount})");
                } else {
                    $queueSize = $jobHandler->getQueueSize($queue);
                    if ($queueSize === 0) {
                        sleep($sleep);
                    }
                }
            } catch (\Throwable $e) {
                $this->failedCount++;
                $output->writeln("<error>[" . date('Y-m-d H:i:s') . "] 任务处理失败: {$e->getMessage()}</error>");
                Log::error("Queue worker error: " . $e->getMessage());
                sleep($sleep);
            }
        }

        $output->writeln(str_repeat('=', 50));
        $output->writeln("[" . date('Y-m-d H:i:s') . "] 队列Worker退出");
        $output->writeln("  成功处理: {$this->processedCount}");
        $output->writeln("  失败数量: {$this->failedCount}");

        return 0;
    }
}
