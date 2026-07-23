<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Log;

class LogService
{
    private const LOG_PATH = 'logs';

    public const TYPE_ERROR = 'error';
    public const TYPE_BUSINESS = 'business';
    public const TYPE_RISK = 'risk';

    public function error(string $message, array $context = []): void
    {
        $this->write(self::TYPE_ERROR, 'error', $message, $context);
    }

    public function business(string $action, string $message, array $context = []): void
    {
        $context['action'] = $action;
        $this->write(self::TYPE_BUSINESS, 'info', $message, $context);
    }

    public function risk(string $type, string $message, array $context = []): void
    {
        $context['risk_type'] = $type;
        $this->write(self::TYPE_RISK, 'warning', $message, $context);
    }

    private function write(string $type, string $level, string $message, array $context = []): void
    {
        try {
            $logData = array_merge([
                'timestamp' => date('Y-m-d H:i:s'),
                'level' => $level,
                'message' => $message,
                'trace_id' => trace_id(),
                'ip' => get_client_ip(),
            ], $context);

            $logDir = runtime_path(self::LOG_PATH . DIRECTORY_SEPARATOR . $type);
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }

            $logFile = $logDir . DIRECTORY_SEPARATOR . date('Y-m-d') . '.log';
            $logLine = json_encode($logData, JSON_UNESCAPED_UNICODE) . PHP_EOL;

            file_put_contents($logFile, $logLine, FILE_APPEND);
        } catch (\Throwable $e) {
            Log::error("LogService write error: " . $e->getMessage());
        }
    }

    public function search(string $type, array $conditions = [], int $page = 1, int $limit = 20): array
    {
        try {
            $results = [];
            $total = 0;

            $startDate = $conditions['start_date'] ?? date('Y-m-d');
            $endDate = $conditions['end_date'] ?? date('Y-m-d');
            $userId = $conditions['user_id'] ?? 0;
            $orderId = $conditions['order_id'] ?? 0;
            $keyword = $conditions['keyword'] ?? '';

            $currentDate = strtotime($startDate);
            $endTime = strtotime($endDate);

            $allLogs = [];

            while ($currentDate <= $endTime) {
                $dateStr = date('Y-m-d', $currentDate);
                $logFile = runtime_path(self::LOG_PATH . DIRECTORY_SEPARATOR . $type . DIRECTORY_SEPARATOR . $dateStr . '.log');

                if (file_exists($logFile)) {
                    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                    if ($lines) {
                        foreach ($lines as $line) {
                            $logData = json_decode($line, true);
                            if (!$logData) {
                                continue;
                            }

                            $matched = true;

                            if ($userId > 0 && ($logData['user_id'] ?? 0) != $userId) {
                                $matched = false;
                            }

                            if ($orderId > 0 && ($logData['order_id'] ?? 0) != $orderId) {
                                $matched = false;
                            }

                            if (!empty($keyword)) {
                                $haystack = json_encode($logData, JSON_UNESCAPED_UNICODE);
                                if (strpos($haystack, $keyword) === false) {
                                    $matched = false;
                                }
                            }

                            if ($matched) {
                                $allLogs[] = $logData;
                            }
                        }
                    }
                }

                $currentDate = strtotime('+1 day', $currentDate);
            }

            $total = count($allLogs);
            $offset = ($page - 1) * $limit;
            $results = array_slice($allLogs, $offset, $limit);

            return [
                'list' => $results,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
            ];
        } catch (\Throwable $e) {
            Log::error("LogService search error: " . $e->getMessage());
            return [
                'list' => [],
                'total' => 0,
                'page' => $page,
                'limit' => $limit,
            ];
        }
    }

    public function getLogTypes(): array
    {
        return [
            self::TYPE_ERROR => '错误日志',
            self::TYPE_BUSINESS => '业务日志',
            self::TYPE_RISK => '风控日志',
        ];
    }

    public function getStats(string $type, string $date = ''): array
    {
        try {
            $date = $date ?: date('Y-m-d');
            $logFile = runtime_path(self::LOG_PATH . DIRECTORY_SEPARATOR . $type . DIRECTORY_SEPARATOR . $date . '.log');

            $stats = [
                'date' => $date,
                'type' => $type,
                'count' => 0,
                'size' => 0,
            ];

            if (file_exists($logFile)) {
                $stats['size'] = filesize($logFile);
                $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $stats['count'] = $lines ? count($lines) : 0;
            }

            return $stats;
        } catch (\Throwable $e) {
            Log::error("LogService stats error: " . $e->getMessage());
            return [
                'date' => $date,
                'type' => $type,
                'count' => 0,
                'size' => 0,
            ];
        }
    }

    public function cleanOldLogs(string $type, int $days = 30): int
    {
        try {
            $cleanedCount = 0;
            $logDir = runtime_path(self::LOG_PATH . DIRECTORY_SEPARATOR . $type);

            if (!is_dir($logDir)) {
                return 0;
            }

            $threshold = time() - ($days * 86400);
            $files = glob($logDir . DIRECTORY_SEPARATOR . '*.log');

            if ($files) {
                foreach ($files as $file) {
                    if (filemtime($file) < $threshold) {
                        if (unlink($file)) {
                            $cleanedCount++;
                        }
                    }
                }
            }

            Log::info("Clean old logs: type={$type}, days={$days}, cleaned={$cleanedCount}");
            return $cleanedCount;
        } catch (\Throwable $e) {
            Log::error("Clean old logs error: " . $e->getMessage());
            return 0;
        }
    }
}
