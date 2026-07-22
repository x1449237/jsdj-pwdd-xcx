<?php
declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use app\model\ThirdPartyApiLog;
use app\model\ThirdPartyRetryQueue;
use app\model\CronJobLog;
use think\facade\Log;
use GuzzleHttp\Client;

/**
 * 第三方API重试
 * 每5分钟执行，重试失败的第三方API调用（最多3次）
 */
class ThirdPartyRetry extends Command
{
    /**
     * 最大重试次数
     */
    private const MAX_RETRY = 3;

    protected function configure(): void
    {
        $this->setName('api:retry')
            ->setDescription('重试失败的第三方API调用，最多3次');
    }

    protected function execute(Input $input, Output $output): int
    {
        $startTime = microtime(true);
        $output->writeln("[" . date('Y-m-d H:i:s') . "] 开始执行第三方API重试...");

        try {
            // 获取待重试的队列
            $retryItems = ThirdPartyRetryQueue::where('status', 0)
                ->where('retry_count', '<', self::MAX_RETRY)
                ->where('next_retry_time', '<=', date('Y-m-d H:i:s'))
                ->order('create_time', 'asc')
                ->limit(50)
                ->select();

            $successCount = 0;
            $failCount = 0;

            foreach ($retryItems as $item) {
                $result = $this->retryCall($item);

                $item->retry_count++;
                $item->last_retry_time = date('Y-m-d H:i:s');

                if ($result['success']) {
                    $item->status = 1; // 成功
                    $successCount++;
                } else {
                    if ($item->retry_count >= self::MAX_RETRY) {
                        $item->status = 2; // 最终失败
                    } else {
                        // 指数退避：1分钟, 2分钟, 4分钟
                        $delay = pow(2, $item->retry_count) * 60;
                        $item->next_retry_time = date('Y-m-d H:i:s', time() + $delay);
                    }
                    $failCount++;
                }

                $item->save();

                // 记录API日志
                ThirdPartyApiLog::create([
                    'service_name' => $item->service_name,
                    'api_url'      => $item->api_url,
                    'request_data' => $item->request_data,
                    'response_data'=> $result['response'] ?? '',
                    'status'       => $result['success'] ? 1 : 0,
                    'retry_count'  => $item->retry_count,
                    'error_msg'    => $result['error'] ?? '',
                    'elapsed'      => $result['elapsed'] ?? 0,
                ]);
            }

            $elapsed = round(microtime(true) - $startTime, 3);
            $total = $successCount + $failCount;

            $output->writeln("[" . date('Y-m-d H:i:s') . "] 第三方API重试完成，成功 {$successCount}，失败 {$failCount}，总计 {$total}，耗时 {$elapsed}s");

            CronJobLog::create([
                'job_name'    => 'api:retry',
                'status'      => 1,
                'result'      => "成功 {$successCount}，失败 {$failCount}",
                'elapsed'     => $elapsed,
                'execute_time'=> date('Y-m-d H:i:s'),
            ]);

            Log::info("第三方API重试完成，成功 {$successCount}，失败 {$failCount}");
            return 0;
        } catch (\Throwable $e) {
            $output->writeln("<error>第三方API重试失败: {$e->getMessage()}</error>");

            CronJobLog::create([
                'job_name'    => 'api:retry',
                'status'      => 0,
                'result'      => $e->getMessage(),
                'elapsed'     => round(microtime(true) - $startTime, 3),
                'execute_time'=> date('Y-m-d H:i:s'),
            ]);

            Log::error("第三方API重试失败: {$e->getMessage()}");
            return 1;
        }
    }

    /**
     * 重试API调用
     * @param ThirdPartyRetryQueue $item
     * @return array
     */
    private function retryCall(ThirdPartyRetryQueue $item): array
    {
        $callStart = microtime(true);

        try {
            $requestData = json_decode($item->request_data, true) ?: [];

            $client = new Client(['timeout' => 30]);

            $options = [
                'headers' => json_decode($item->request_headers ?? '{}', true) ?: [],
            ];

            if ($item->request_method === 'POST' || $item->request_method === 'PUT') {
                $options['json'] = $requestData;
            } elseif ($item->request_method === 'GET') {
                $options['query'] = $requestData;
            }

            $response = $client->request($item->request_method, $item->api_url, $options);

            $body = $response->getBody()->getContents();
            $elapsed = round(microtime(true) - $callStart, 3);

            Log::info("API重试成功: {$item->service_name} -> {$item->api_url}");

            return [
                'success'  => true,
                'response' => $body,
                'elapsed'  => $elapsed,
            ];
        } catch (\Throwable $e) {
            $elapsed = round(microtime(true) - $callStart, 3);

            Log::warning("API重试失败: {$item->service_name} -> {$item->api_url}, error={$e->getMessage()}");

            return [
                'success'  => false,
                'error'    => $e->getMessage(),
                'response' => '',
                'elapsed'  => $elapsed,
            ];
        }
    }
}