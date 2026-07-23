<?php
declare(strict_types=1);

namespace app\swoole\Handler;

use think\facade\Log;

class ConnectionHandler
{
    private static $connectionStats = [
        'total_connections' => 0,
        'peak_connections' => 0,
        'total_messages' => 0,
        'total_errors' => 0,
    ];

    public function onConnect(int $fd, string $ip): void
    {
        self::$connectionStats['total_connections']++;

        if (self::$connectionStats['total_connections'] > self::$connectionStats['peak_connections']) {
            self::$connectionStats['peak_connections'] = self::$connectionStats['total_connections'];
        }

        Log::debug("Connection established: fd={$fd}, ip={$ip}");
    }

    public function onDisconnect(int $fd, int $userId = 0): void
    {
        Log::debug("Connection closed: fd={$fd}, user_id={$userId}");
    }

    public function incrementMessageCount(): void
    {
        self::$connectionStats['total_messages']++;
    }

    public function incrementErrorCount(): void
    {
        self::$connectionStats['total_errors']++;
    }

    public static function getStats(): array
    {
        return self::$connectionStats;
    }

    public static function resetStats(): void
    {
        self::$connectionStats = [
            'total_connections' => 0,
            'peak_connections' => 0,
            'total_messages' => 0,
            'total_errors' => 0,
        ];
    }
}
