<?php
declare(strict_types=1);

namespace app\queue;

use think\facade\Cache;
use think\facade\Log;

class JobHandler
{
    private $redis;

    private const QUEUE_PREFIX = 'queue:';
    private const FAILED_QUEUE_PREFIX = 'queue:failed:';
    private const DELAYED_QUEUE_PREFIX = 'queue:delayed:';

    private $availableQueues = [
        'order_settle'   => 'OrderSettleJob',
        'order_timeout'  => 'OrderTimeoutJob',
        'message_push'   => 'MessagePushJob',
    ];

    public function __construct()
    {
        $this->redis = Cache::store('redis')->handler();
    }

    public function push(string $queue, array $data, int $delay = 0): bool
    {
        try {
            $jobData = [
                'id' => uniqid('job_', true),
                'queue' => $queue,
                'data' => $data,
                'attempts' => 0,
                'max_attempts' => 3,
                'created_at' => time(),
                'available_at' => time() + $delay,
            ];

            if ($delay > 0) {
                $key = self::DELAYED_QUEUE_PREFIX . $queue;
                $this->redis->zAdd($key, time() + $delay, json_encode($jobData, JSON_UNESCAPED_UNICODE));
            } else {
                $key = self::QUEUE_PREFIX . $queue;
                $this->redis->lPush($key, json_encode($jobData, JSON_UNESCAPED_UNICODE));
            }

            Log::info("Job pushed: queue={$queue}, job_id={$jobData['id']}, delay={$delay}s");
            return true;
        } catch (\Throwable $e) {
            Log::error("Job push error: " . $e->getMessage() . ", queue={$queue}");
            return false;
        }
    }

    public function pop(string $queue): ?array
    {
        try {
            $this->migrateDelayedJobs($queue);

            $key = self::QUEUE_PREFIX . $queue;
            $job = $this->redis->rPop($key);

            if ($job) {
                return json_decode($job, true);
            }

            return null;
        } catch (\Throwable $e) {
            Log::error("Job pop error: " . $e->getMessage() . ", queue={$queue}");
            return null;
        }
    }

    public function process(string $queue): bool
    {
        $job = $this->pop($queue);
        if (!$job) {
            return false;
        }

        $jobClass = $this->getJobClass($queue);
        if (!$jobClass) {
            $this->markFailed($job, "Unknown job handler for queue: {$queue}");
            return false;
        }

        try {
            $job['attempts']++;

            $handler = new $jobClass();
            $result = $handler->handle($job['data']);

            if ($result) {
                Log::info("Job processed: queue={$queue}, job_id={$job['id']}, attempts={$job['attempts']}");
                return true;
            } else {
                throw new \Exception("Job handler returned false");
            }
        } catch (\Throwable $e) {
            Log::error("Job failed: queue={$queue}, job_id={$job['id']}, error=" . $e->getMessage());

            if ($job['attempts'] < $job['max_attempts']) {
                $this->release($queue, $job, 60);
            } else {
                $this->markFailed($job, $e->getMessage());
            }

            return false;
        }
    }

    public function release(string $queue, array $job, int $delay = 60): void
    {
        try {
            $job['available_at'] = time() + $delay;

            $key = self::DELAYED_QUEUE_PREFIX . $queue;
            $this->redis->zAdd($key, time() + $delay, json_encode($job, JSON_UNESCAPED_UNICODE));

            Log::info("Job released: queue={$queue}, job_id={$job['id']}, delay={$delay}s");
        } catch (\Throwable $e) {
            Log::error("Job release error: " . $e->getMessage() . ", queue={$queue}");
        }
    }

    public function markFailed(array $job, string $error): void
    {
        try {
            $queue = $job['queue'];
            $job['failed_at'] = time();
            $job['error'] = $error;

            $key = self::FAILED_QUEUE_PREFIX . $queue;
            $this->redis->lPush($key, json_encode($job, JSON_UNESCAPED_UNICODE));
            $this->redis->lTrim($key, 0, 999);

            Log::error("Job marked as failed: queue={$queue}, job_id={$job['id']}, error={$error}");
        } catch (\Throwable $e) {
            Log::error("Mark job failed error: " . $e->getMessage());
        }
    }

    public function getQueueSize(string $queue): int
    {
        try {
            $key = self::QUEUE_PREFIX . $queue;
            return $this->redis->lLen($key);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public function getFailedQueueSize(string $queue): int
    {
        try {
            $key = self::FAILED_QUEUE_PREFIX . $queue;
            return $this->redis->lLen($key);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public function getDelayedQueueSize(string $queue): int
    {
        try {
            $key = self::DELAYED_QUEUE_PREFIX . $queue;
            return $this->redis->zCard($key);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public function clearQueue(string $queue): bool
    {
        try {
            $key = self::QUEUE_PREFIX . $queue;
            $this->redis->del($key);
            return true;
        } catch (\Throwable $e) {
            Log::error("Clear queue error: " . $e->getMessage());
            return false;
        }
    }

    public function clearFailedQueue(string $queue): bool
    {
        try {
            $key = self::FAILED_QUEUE_PREFIX . $queue;
            $this->redis->del($key);
            return true;
        } catch (\Throwable $e) {
            Log::error("Clear failed queue error: " . $e->getMessage());
            return false;
        }
    }

    public function retryFailed(string $queue, int $limit = 100): int
    {
        try {
            $failedKey = self::FAILED_QUEUE_PREFIX . $queue;
            $count = 0;

            for ($i = 0; $i < $limit; $i++) {
                $job = $this->redis->rPop($failedKey);
                if (!$job) {
                    break;
                }

                $jobData = json_decode($job, true);
                $jobData['attempts'] = 0;
                $jobData['failed_at'] = null;
                $jobData['error'] = null;

                $key = self::QUEUE_PREFIX . $queue;
                $this->redis->lPush($key, json_encode($jobData, JSON_UNESCAPED_UNICODE));
                $count++;
            }

            Log::info("Retry failed jobs: queue={$queue}, count={$count}");
            return $count;
        } catch (\Throwable $e) {
            Log::error("Retry failed jobs error: " . $e->getMessage());
            return 0;
        }
    }

    private function migrateDelayedJobs(string $queue): void
    {
        try {
            $delayedKey = self::DELAYED_QUEUE_PREFIX . $queue;
            $now = time();

            $jobs = $this->redis->zRangeByScore($delayedKey, 0, $now, ['limit' => [0, 100]]);

            if (empty($jobs)) {
                return;
            }

            $key = self::QUEUE_PREFIX . $queue;

            foreach ($jobs as $job) {
                $this->redis->lPush($key, $job);
                $this->redis->zRem($delayedKey, $job);
            }
        } catch (\Throwable $e) {
            Log::error("Migrate delayed jobs error: " . $e->getMessage());
        }
    }

    private function getJobClass(string $queue): ?string
    {
        if (isset($this->availableQueues[$queue])) {
            return '\\app\\queue\\jobs\\' . $this->availableQueues[$queue];
        }
        return null;
    }

    public function getAvailableQueues(): array
    {
        return array_keys($this->availableQueues);
    }

    public function getAllQueueStats(): array
    {
        $stats = [];
        foreach ($this->availableQueues as $queue => $handler) {
            $stats[$queue] = [
                'waiting' => $this->getQueueSize($queue),
                'delayed' => $this->getDelayedQueueSize($queue),
                'failed' => $this->getFailedQueueSize($queue),
            ];
        }
        return $stats;
    }
}
