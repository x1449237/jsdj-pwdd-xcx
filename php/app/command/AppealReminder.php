<?php
declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use app\model\AppealReminder;
use app\model\ReminderEscalation;
use app\model\CronJobLog;
use app\service\WebSocketService;
use app\service\WeChatService;
use think\facade\Log;

/**
 * 申诉催办
 * 三级降级推送：订阅消息 → 后台红点 → 48小时催办
 */
class AppealReminder extends Command
{
    /**
     * 催办阶段
     */
    private const STAGE_SUBSCRIBE  = 1; // 订阅消息
    private const STAGE_RED_DOT    = 2; // 后台红点
    private const STAGE_ESCALATION = 3; // 48小时催办

    protected function configure(): void
    {
        $this->setName('appeal:reminder')
            ->setDescription('申诉催办：三级降级推送');
    }

    protected function execute(Input $input, Output $output): int
    {
        $startTime = microtime(true);
        $output->writeln("[" . date('Y-m-d H:i:s') . "] 开始执行申诉催办...");

        try {
            $processedCount = 0;

            // 1. 处理订阅消息阶段
            $processedCount += $this->processSubscribeStage($output);

            // 2. 处理后台红点阶段
            $processedCount += $this->processRedDotStage($output);

            // 3. 处理48小时催办阶段
            $processedCount += $this->processEscalationStage($output);

            $elapsed = round(microtime(true) - $startTime, 3);
            $output->writeln("[" . date('Y-m-d H:i:s') . "] 申诉催办完成，处理 {$processedCount} 条，耗时 {$elapsed}s");

            CronJobLog::create([
                'job_name'    => 'appeal:reminder',
                'status'      => 1,
                'result'      => "处理 {$processedCount} 条催办",
                'elapsed'     => $elapsed,
                'execute_time'=> date('Y-m-d H:i:s'),
            ]);

            Log::info("申诉催办完成，处理 {$processedCount} 条");
            return 0;
        } catch (\Throwable $e) {
            $output->writeln("<error>申诉催办失败: {$e->getMessage()}</error>");

            CronJobLog::create([
                'job_name'    => 'appeal:reminder',
                'status'      => 0,
                'result'      => $e->getMessage(),
                'elapsed'     => round(microtime(true) - $startTime, 3),
                'execute_time'=> date('Y-m-d H:i:s'),
            ]);

            Log::error("申诉催办失败: {$e->getMessage()}");
            return 1;
        }
    }

    /**
     * 处理订阅消息阶段
     * 申诉提交后立即发送订阅消息通知
     * @param Output $output
     * @return int
     */
    private function processSubscribeStage(Output $output): int
    {
        $count = 0;

        try {
            $reminders = AppealReminder::where('stage', self::STAGE_SUBSCRIBE)
                ->where('status', 0)
                ->where('send_time', '<=', date('Y-m-d H:i:s'))
                ->limit(100)
                ->select();

            foreach ($reminders as $reminder) {
                // 发送订阅消息
                $this->sendSubscribeMessage($reminder);

                // 更新为下一阶段
                $reminder->stage = self::STAGE_RED_DOT;
                $reminder->send_time = date('Y-m-d H:i:s', time() + 3600); // 1小时后进入红点阶段
                $reminder->save();

                $count++;
            }

            if ($count > 0) {
                $output->writeln("  订阅消息阶段: 处理 {$count} 条");
            }
        } catch (\Throwable $e) {
            Log::error("订阅消息阶段处理失败: {$e->getMessage()}");
        }

        return $count;
    }

    /**
     * 处理后管红点阶段
     * @param Output $output
     * @return int
     */
    private function processRedDotStage(Output $output): int
    {
        $count = 0;

        try {
            $reminders = AppealReminder::where('stage', self::STAGE_RED_DOT)
                ->where('status', 0)
                ->where('send_time', '<=', date('Y-m-d H:i:s'))
                ->limit(100)
                ->select();

            foreach ($reminders as $reminder) {
                // 发送后台红点通知
                $this->sendRedDotNotification($reminder);

                // 更新为下一阶段
                $reminder->stage = self::STAGE_ESCALATION;
                $reminder->send_time = date('Y-m-d H:i:s', time() + 172800); // 48小时后催办
                $reminder->save();

                $count++;
            }

            if ($count > 0) {
                $output->writeln("  后台红点阶段: 处理 {$count} 条");
            }
        } catch (\Throwable $e) {
            Log::error("后台红点阶段处理失败: {$e->getMessage()}");
        }

        return $count;
    }

    /**
     * 处理48小时催办阶段
     * @param Output $output
     * @return int
     */
    private function processEscalationStage(Output $output): int
    {
        $count = 0;

        try {
            $reminders = AppealReminder::where('stage', self::STAGE_ESCALATION)
                ->where('status', 0)
                ->where('send_time', '<=', date('Y-m-d H:i:s'))
                ->limit(100)
                ->select();

            foreach ($reminders as $reminder) {
                // 发送48小时催办
                $this->sendEscalationReminder($reminder);

                // 记录升级
                ReminderEscalation::create([
                    'reminder_id'   => $reminder->id,
                    'escalation_level' => 3,
                    'escalation_type'  => '48h_overdue',
                    'escalation_time'  => date('Y-m-d H:i:s'),
                ]);

                $reminder->status = 1; // 已完成
                $reminder->save();

                $count++;
            }

            if ($count > 0) {
                $output->writeln("  48小时催办阶段: 处理 {$count} 条");
            }
        } catch (\Throwable $e) {
            Log::error("48小时催办阶段处理失败: {$e->getMessage()}");
        }

        return $count;
    }

    /**
     * 发送订阅消息
     * @param AppealReminder $reminder
     */
    private function sendSubscribeMessage(AppealReminder $reminder): void
    {
        try {
            // 微信小程序订阅消息推送（通过微信官方API）
            $wechatService = new WeChatService();
            $wechatService->sendToUserWithRetry(
                $reminder->user_id,
                'appeal_notify',
                [
                    'thing1' => '申诉处理',
                    'thing2' => '已提交，待处理',
                    'time3'  => date('Y-m-d H:i:s'),
                    'thing4' => '请耐心等待平台处理结果',
                ],
                "/pages/appeal/detail/detail?id={$reminder->appeal_id}",
                (string) $reminder->appeal_id,
                'appeal'
            );

            // WebSocket 实时推送兜底
            $wsService = new WebSocketService();
            $wsService->pushToUser($reminder->user_id, [
                'event'   => 'appeal_reminder',
                'type'    => 'subscribe',
                'title'   => '申诉处理通知',
                'content' => '您的申诉已提交，请耐心等待处理结果',
                'appeal_id' => $reminder->appeal_id,
            ]);

            Log::info("订阅消息已发送: user_id={$reminder->user_id}, appeal_id={$reminder->appeal_id}");
        } catch (\Throwable $e) {
            Log::error("发送订阅消息失败: {$e->getMessage()}");
        }
    }

    /**
     * 发送后台红点通知
     * @param AppealReminder $reminder
     */
    private function sendRedDotNotification(AppealReminder $reminder): void
    {
        try {
            $wsService = new WebSocketService();
            $wsService->pushToUser($reminder->user_id, [
                'event'   => 'appeal_reminder',
                'type'    => 'red_dot',
                'title'   => '申诉处理提醒',
                'content' => '您的申诉正在处理中，请关注后台通知',
                'appeal_id' => $reminder->appeal_id,
            ]);

            Log::info("后台红点通知已发送: user_id={$reminder->user_id}, appeal_id={$reminder->appeal_id}");
        } catch (\Throwable $e) {
            Log::error("发送后台红点通知失败: {$e->getMessage()}");
        }
    }

    /**
     * 发送48小时催办
     * @param AppealReminder $reminder
     */
    private function sendEscalationReminder(AppealReminder $reminder): void
    {
        try {
            $wsService = new WebSocketService();
            $wsService->pushToUser($reminder->user_id, [
                'event'   => 'appeal_reminder',
                'type'    => 'escalation',
                'title'   => '申诉超时催办',
                'content' => '您的申诉已超过48小时未处理，我们将加急处理',
                'appeal_id' => $reminder->appeal_id,
                'level'  => 'urgent',
            ]);

            Log::warning("48小时催办已发送: user_id={$reminder->user_id}, appeal_id={$reminder->appeal_id}");
        } catch (\Throwable $e) {
            Log::error("发送48小时催办失败: {$e->getMessage()}");
        }
    }
}