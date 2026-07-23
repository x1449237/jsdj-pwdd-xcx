<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;
use think\facade\Log;

class TableShardService
{
    private const ORDER_TABLE_PREFIX = 'order_archive_';
    private const CHAT_MESSAGE_TABLE_PREFIX = 'chat_message_archive_';

    public function getOrderTableName(string $date = ''): string
    {
        $yearMonth = $this->getYearMonth($date);
        return self::ORDER_TABLE_PREFIX . $yearMonth;
    }

    public function getChatMessageTableName(string $date = ''): string
    {
        $yearMonth = $this->getYearMonth($date);
        return self::CHAT_MESSAGE_TABLE_PREFIX . $yearMonth;
    }

    private function getYearMonth(string $date = ''): string
    {
        if (empty($date)) {
            return date('Ym');
        }

        $timestamp = is_numeric($date) ? (int)$date : strtotime($date);
        if ($timestamp === false) {
            return date('Ym');
        }

        return date('Ym', $timestamp);
    }

    public function insertOrder(array $data, string $date = ''): int
    {
        $tableName = $this->getOrderTableName($date);

        if (!$this->tableExists($tableName)) {
            $this->createOrderShardTable($this->getYearMonth($date));
        }

        return Db::name($tableName)->insertGetId($data);
    }

    public function insertChatMessage(array $data, string $date = ''): int
    {
        $tableName = $this->getChatMessageTableName($date);

        if (!$this->tableExists($tableName)) {
            $this->createChatMessageShardTable($this->getYearMonth($date));
        }

        return Db::name($tableName)->insertGetId($data);
    }

    public function queryOrderByDate(array $where, string $startDate, string $endDate, int $page = 1, int $limit = 20): array
    {
        $tables = $this->getOrderTablesInRange($startDate, $endDate);

        if (empty($tables)) {
            return ['list' => [], 'total' => 0];
        }

        $unionQueries = [];
        foreach ($tables as $table) {
            $query = Db::name($table)->where($where);
            $unionQueries[] = $query->buildSql();
        }

        $unionSql = implode(' UNION ALL ', $unionQueries);

        $total = Db::table($unionSql . ' AS t')->count();
        $list = Db::table($unionSql . ' AS t')
            ->order('create_time', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        return ['list' => $list, 'total' => $total];
    }

    public function queryChatMessageByDate(array $where, string $startDate, string $endDate, int $page = 1, int $limit = 20): array
    {
        $tables = $this->getChatMessageTablesInRange($startDate, $endDate);

        if (empty($tables)) {
            return ['list' => [], 'total' => 0];
        }

        $unionQueries = [];
        foreach ($tables as $table) {
            $query = Db::name($table)->where($where);
            $unionQueries[] = $query->buildSql();
        }

        $unionSql = implode(' UNION ALL ', $unionQueries);

        $total = Db::table($unionSql . ' AS t')->count();
        $list = Db::table($unionSql . ' AS t')
            ->order('create_time', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        return ['list' => $list, 'total' => $total];
    }

    public function getOrderTablesInRange(string $startDate, string $endDate): array
    {
        return $this->getTablesInRange(self::ORDER_TABLE_PREFIX, $startDate, $endDate);
    }

    public function getChatMessageTablesInRange(string $startDate, string $endDate): array
    {
        return $this->getTablesInRange(self::CHAT_MESSAGE_TABLE_PREFIX, $startDate, $endDate);
    }

    private function getTablesInRange(string $prefix, string $startDate, string $endDate): array
    {
        $tables = [];
        $startTime = strtotime($startDate);
        $endTime = strtotime($endDate);

        if ($startTime === false || $endTime === false || $startTime > $endTime) {
            return $tables;
        }

        $currentTime = $startTime;
        while ($currentTime <= $endTime) {
            $yearMonth = date('Ym', $currentTime);
            $tableName = $prefix . $yearMonth;

            if ($this->tableExists($tableName)) {
                $tables[] = $tableName;
            }

            $currentTime = strtotime('+1 month', $currentTime);
        }

        return $tables;
    }

    public function createOrderShardTable(string $yearMonth): bool
    {
        $tableName = self::ORDER_TABLE_PREFIX . $yearMonth;
        $templateTable = 'order';

        return $this->createShardTable($tableName, $templateTable, "订单表-{$yearMonth}分表");
    }

    public function createChatMessageShardTable(string $yearMonth): bool
    {
        $tableName = self::CHAT_MESSAGE_TABLE_PREFIX . $yearMonth;
        $templateTable = 'chat_message';

        return $this->createShardTable($tableName, $templateTable, "聊天消息表-{$yearMonth}分表");
    }

    private function createShardTable(string $tableName, string $templateTable, string $comment): bool
    {
        try {
            if ($this->tableExists($tableName)) {
                return true;
            }

            $sql = "CREATE TABLE IF NOT EXISTS `{$tableName}` LIKE `{$templateTable}`";
            Db::execute($sql);

            $commentSql = "ALTER TABLE `{$tableName}` COMMENT = '{$comment}'";
            Db::execute($commentSql);

            Log::info("Shard table created: {$tableName}");
            return true;
        } catch (\Throwable $e) {
            Log::error("Create shard table error: " . $e->getMessage() . ", table={$tableName}");
            return false;
        }
    }

    public function createNextMonthTables(): bool
    {
        try {
            $nextMonth = date('Ym', strtotime('+1 month'));

            $orderCreated = $this->createOrderShardTable($nextMonth);
            $chatCreated = $this->createChatMessageShardTable($nextMonth);

            Log::info("Next month shard tables created: {$nextMonth}, order={$orderCreated}, chat={$chatCreated}");
            return $orderCreated && $chatCreated;
        } catch (\Throwable $e) {
            Log::error("Create next month tables error: " . $e->getMessage());
            return false;
        }
    }

    public function tableExists(string $tableName): bool
    {
        try {
            $database = config('database.connections.mysql.database');
            $result = Db::query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?", [$database, $tableName]);
            return !empty($result);
        } catch (\Throwable $e) {
            Log::error("Check table exists error: " . $e->getMessage());
            return false;
        }
    }

    public function getAllShardTables(string $prefix = ''): array
    {
        try {
            $database = config('database.connections.mysql.database');

            if (empty($prefix)) {
                $prefixes = [self::ORDER_TABLE_PREFIX, self::CHAT_MESSAGE_TABLE_PREFIX];
            } else {
                $prefixes = [$prefix];
            }

            $allTables = [];
            foreach ($prefixes as $p) {
                $likePattern = $p . '%';
                $result = Db::query("SELECT TABLE_NAME, TABLE_COMMENT, CREATE_TIME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE ? ORDER BY TABLE_NAME DESC", [$database, $likePattern]);
                $allTables = array_merge($allTables, $result);
            }

            return $allTables;
        } catch (\Throwable $e) {
            Log::error("Get all shard tables error: " . $e->getMessage());
            return [];
        }
    }

    public function getTableStats(string $tableName): array
    {
        try {
            $database = config('database.connections.mysql.database');
            $result = Db::query("SELECT TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, DATA_FREE, CREATE_TIME, UPDATE_TIME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?", [$database, $tableName]);

            if (empty($result)) {
                return [];
            }

            $row = $result[0];
            return [
                'table_name' => $tableName,
                'rows' => $row['TABLE_ROWS'] ?? 0,
                'data_size' => $this->formatBytes($row['DATA_LENGTH'] ?? 0),
                'index_size' => $this->formatBytes($row['INDEX_LENGTH'] ?? 0),
                'total_size' => $this->formatBytes(($row['DATA_LENGTH'] ?? 0) + ($row['INDEX_LENGTH'] ?? 0)),
                'free_size' => $this->formatBytes($row['DATA_FREE'] ?? 0),
                'create_time' => $row['CREATE_TIME'] ?? '',
                'update_time' => $row['UPDATE_TIME'] ?? '',
            ];
        } catch (\Throwable $e) {
            Log::error("Get table stats error: " . $e->getMessage());
            return [];
        }
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unitIndex = 0;

        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }

        return round($bytes, 2) . ' ' . $units[$unitIndex];
    }

    public function migrateDataToShard(string $sourceTable, string $targetTable, string $startDate, string $endDate, int $batchSize = 1000): array
    {
        try {
            $totalCount = 0;
            $offset = 0;

            do {
                $data = Db::name($sourceTable)
                    ->whereTime('create_time', 'between', [$startDate, $endDate])
                    ->limit($offset, $batchSize)
                    ->order('id', 'asc')
                    ->select()
                    ->toArray();

                if (empty($data)) {
                    break;
                }

                Db::name($targetTable)->insertAll($data);
                $totalCount += count($data);
                $offset += $batchSize;

                Log::info("Migrate data progress: table={$sourceTable} -> {$targetTable}, count={$totalCount}");
            } while (count($data) === $batchSize);

            return [
                'success' => true,
                'migrated_count' => $totalCount,
                'source_table' => $sourceTable,
                'target_table' => $targetTable,
            ];
        } catch (\Throwable $e) {
            Log::error("Migrate data error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'migrated_count' => $totalCount ?? 0,
            ];
        }
    }
}
