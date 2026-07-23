<?php
declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Argument;
use think\console\input\Option;
use app\model\BackupRecord;
use app\model\CronJobLog;
use app\model\RestoreRecord;
use think\facade\Log;
use think\facade\Db;

class BackupFull extends Command
{
    private const RETAIN_DAYS = 30;
    private const ENCRYPTION_METHOD = 'aes-256-cbc';

    protected function configure(): void
    {
        $this->setName('backup:full')
            ->setDescription('全量加密备份自动化')
            ->addArgument('action', Argument::OPTIONAL, '操作类型 create|restore|list|clean', 'create')
            ->addOption('date', 'd', Option::VALUE_OPTIONAL, '指定日期备份/恢复 (Y-m-d)')
            ->addOption('file', 'f', Option::VALUE_OPTIONAL, '指定文件路径恢复');
    }

    protected function execute(Input $input, Output $output): int
    {
        $action = $input->getArgument('action');

        switch ($action) {
            case 'create':
                return $this->createBackup($output);
            case 'restore':
                return $this->restoreBackup($input, $output);
            case 'list':
                return $this->listBackups($output);
            case 'clean':
                return $this->cleanOldBackups($output);
            default:
                $output->writeln("<error>未知操作: {$action}</error>");
                return 1;
        }
    }

    private function createBackup(Output $output): int
    {
        $startTime = microtime(true);
        $output->writeln("[" . date('Y-m-d H:i:s') . "] 开始执行全量备份...");

        try {
            $backupFile = $this->createDatabaseBackup();
            $output->writeln("  - 数据库备份完成: " . basename($backupFile));

            $encryptedFile = $this->encryptBackupFile($backupFile);
            $output->writeln("  - AES-256加密完成: " . basename($encryptedFile));

            $ossUrl = $this->uploadToOss($encryptedFile);
            if ($ossUrl) {
                $output->writeln("  - OSS上传完成: {$ossUrl}");
            } else {
                $output->writeln("  <comment>  - OSS上传跳过 (配置缺失)</comment>");
            }

            $fileSize = filesize($encryptedFile);

            BackupRecord::create([
                'file_name'  => basename($encryptedFile),
                'file_size'  => $fileSize,
                'file_path'  => $ossUrl ?: $encryptedFile,
                'backup_type'=> 'full',
                'status'     => 1,
                'backup_date' => date('Y-m-d'),
            ]);

            $this->cleanOldBackups($output, false);

            $elapsed = round(microtime(true) - $startTime, 3);
            $output->writeln("[" . date('Y-m-d H:i:s') . "] 全量备份完成，耗时 {$elapsed}s");

            CronJobLog::create([
                'job_name'    => 'backup:full',
                'status'      => 1,
                'result'      => "全量备份完成, 文件=" . basename($encryptedFile),
                'elapsed'     => $elapsed,
                'execute_time'=> date('Y-m-d H:i:s'),
            ]);

            @unlink($encryptedFile);
            @unlink($backupFile);

            Log::info("全量备份完成，耗时 {$elapsed}s");
            return 0;
        } catch (\Throwable $e) {
            $output->writeln("<error>全量备份失败: {$e->getMessage()}</error>");

            CronJobLog::create([
                'job_name'    => 'backup:full',
                'status'      => 0,
                'result'      => $e->getMessage(),
                'elapsed'     => round(microtime(true) - $startTime, 3),
                'execute_time'=> date('Y-m-d H:i:s'),
            ]);

            Log::error("全量备份失败: {$e->getMessage()}");
            return 1;
        }
    }

    private function restoreBackup(Input $input, Output $output): int
    {
        $output->writeln("[" . date('Y-m-d H:i:s') . "] 开始恢复备份...");

        $date = $input->getOption('date');
        $file = $input->getOption('file');

        $backupFile = '';

        if ($file && file_exists($file)) {
            $backupFile = $file;
        } elseif ($date) {
            $record = BackupRecord::where('backup_date', $date)
                ->where('backup_type', 'full')
                ->where('status', 1)
                ->order('id', 'desc')
                ->find();

            if (!$record) {
                $output->writeln("<error>未找到指定日期的备份: {$date}</error>");
                return 1;
            }

            $localFile = $this->downloadFromOss($record['file_path']);
            if (!$localFile) {
                $output->writeln("<error>下载备份文件失败</error>");
                return 1;
            }
            $backupFile = $localFile;
        } else {
            $output->writeln("<error>请指定日期 (--date) 或文件路径 (--file)</error>");
            return 1;
        }

        try {
            $decryptedFile = $this->decryptBackupFile($backupFile);
            $output->writeln("  - 解密完成: " . basename($decryptedFile));

            $this->restoreDatabase($decryptedFile);
            $output->writeln("  - 数据库恢复完成");

            RestoreRecord::create([
                'backup_file' => basename($backupFile),
                'restore_time' => date('Y-m-d H:i:s'),
                'status' => 1,
                'operator_id' => 0,
            ]);

            $output->writeln("[" . date('Y-m-d H:i:s') . "] 备份恢复完成");

            @unlink($backupFile);
            @unlink($decryptedFile);

            Log::info("备份恢复完成: " . basename($backupFile));
            return 0;
        } catch (\Throwable $e) {
            $output->writeln("<error>恢复失败: {$e->getMessage()}</error>");
            Log::error("备份恢复失败: {$e->getMessage()}");
            return 1;
        }
    }

    private function listBackups(Output $output): int
    {
        $records = BackupRecord::where('backup_type', 'full')
            ->order('id', 'desc')
            ->limit(30)
            ->select()
            ->toArray();

        $output->writeln(str_repeat('=', 80));
        $output->writeln(sprintf("%-5s %-30s %-15s %-12s %-12s", 'ID', '文件名', '大小', '日期', '状态'));
        $output->writeln(str_repeat('-', 80));

        foreach ($records as $record) {
            $size = $this->formatBytes($record['file_size']);
            $status = $record['status'] == 1 ? '成功' : '失败';
            $output->writeln(sprintf(
                "%-5s %-30s %-15s %-12s %-12s",
                $record['id'],
                $record['file_name'],
                $size,
                $record['backup_date'],
                $status
            ));
        }

        $output->writeln(str_repeat('=', 80));
        $output->writeln("共 " . count($records) . " 条备份记录");

        return 0;
    }

    private function cleanOldBackups(Output $output, bool $verbose = true): int
    {
        if ($verbose) {
            $output->writeln("[" . date('Y-m-d H:i:s') . "] 清理 " . self::RETAIN_DAYS . " 天前的备份...");
        }

        $cutoffDate = date('Y-m-d', strtotime('-' . self::RETAIN_DAYS . ' days'));

        $oldRecords = BackupRecord::where('backup_date', '<', $cutoffDate)
            ->where('backup_type', 'full')
            ->where('status', 1)
            ->select()
            ->toArray();

        $count = 0;
        foreach ($oldRecords as $record) {
            try {
                $this->deleteFromOss($record['file_path']);
            } catch (\Throwable $e) {
                Log::warning("删除OSS备份失败: {$record['file_path']}, " . $e->getMessage());
            }

            BackupRecord::where('id', $record['id'])->update(['status' => 2]);
            $count++;
        }

        $backupDir = runtime_path('backup');
        if (is_dir($backupDir)) {
            $files = glob($backupDir . '*.enc');
            if ($files) {
                $cutoff = time() - (self::RETAIN_DAYS * 86400);
                foreach ($files as $file) {
                    if (filemtime($file) < $cutoff) {
                        @unlink($file);
                    }
                }
            }
        }

        if ($verbose) {
            $output->writeln("  - 已清理 {$count} 份过期备份");
        }

        Log::info("清理过期备份: {$count} 份");
        return 0;
    }

    private function createDatabaseBackup(): string
    {
        $backupDir = runtime_path('backup');
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $fileName = 'full_backup_' . date('Ymd_His') . '.sql';
        $filePath = $backupDir . $fileName;

        $host = config_get('database.connections.mysql.hostname', '127.0.0.1');
        $port = config_get('database.connections.mysql.hostport', '3306');
        $user = config_get('database.connections.mysql.username', 'root');
        $password = config_get('database.connections.mysql.password', '');
        $database = config_get('database.connections.mysql.database', 'game_platform');

        $passwordArg = $password ? "-p'{$password}'" : '';
        $command = "mysqldump -h{$host} -P{$port} -u{$user} {$passwordArg} --single-transaction --quick --lock-tables=false --routines --triggers --events {$database} > {$filePath} 2>&1";

        exec($command, $cmdOutput, $returnCode);

        if ($returnCode !== 0) {
            throw new \RuntimeException("mysqldump 失败: " . implode("\n", $cmdOutput));
        }

        return $filePath;
    }

    private function encryptBackupFile(string $filePath): string
    {
        $encryptedPath = $filePath . '.enc';

        $key = substr(hash('sha256', config_get('app.backup_key', 'default_backup_key_2024'), true), 0, 32);
        $iv = openssl_random_pseudo_bytes(16);

        $plainData = file_get_contents($filePath);
        if ($plainData === false) {
            throw new \RuntimeException("读取备份文件失败: {$filePath}");
        }

        $encryptedData = openssl_encrypt($plainData, self::ENCRYPTION_METHOD, $key, OPENSSL_RAW_DATA, $iv);

        if ($encryptedData === false) {
            throw new \RuntimeException("AES-256 加密失败");
        }

        $output = $iv . $encryptedData;
        file_put_contents($encryptedPath, $output);

        @unlink($filePath);

        return $encryptedPath;
    }

    private function decryptBackupFile(string $filePath): string
    {
        $decryptedPath = str_replace('.enc', '', $filePath);
        if ($decryptedPath === $filePath) {
            $decryptedPath = $filePath . '.dec';
        }

        $key = substr(hash('sha256', config_get('app.backup_key', 'default_backup_key_2024'), true), 0, 32);

        $encryptedData = file_get_contents($filePath);
        if ($encryptedData === false) {
            throw new \RuntimeException("读取加密文件失败: {$filePath}");
        }

        $iv = substr($encryptedData, 0, 16);
        $ciphertext = substr($encryptedData, 16);

        $decryptedData = openssl_decrypt($ciphertext, self::ENCRYPTION_METHOD, $key, OPENSSL_RAW_DATA, $iv);

        if ($decryptedData === false) {
            throw new \RuntimeException("AES-256 解密失败，密钥可能不正确");
        }

        file_put_contents($decryptedPath, $decryptedData);

        return $decryptedPath;
    }

    private function restoreDatabase(string $sqlFile): void
    {
        $host = config_get('database.connections.mysql.hostname', '127.0.0.1');
        $port = config_get('database.connections.mysql.hostport', '3306');
        $user = config_get('database.connections.mysql.username', 'root');
        $password = config_get('database.connections.mysql.password', '');
        $database = config_get('database.connections.mysql.database', 'game_platform');

        $passwordArg = $password ? "-p'{$password}'" : '';
        $command = "mysql -h{$host} -P{$port} -u{$user} {$passwordArg} {$database} < {$sqlFile} 2>&1";

        exec($command, $cmdOutput, $returnCode);

        if ($returnCode !== 0) {
            throw new \RuntimeException("数据库恢复失败: " . implode("\n", $cmdOutput));
        }
    }

    private function uploadToOss(string $filePath): string
    {
        $accessKeyId = config_get('oss.oss_access_key_id', '');
        $accessKeySecret = config_get('oss.oss_access_key_secret', '');
        $bucket = config_get('oss.oss_bucket', '');
        $endpoint = config_get('oss.oss_endpoint', '');

        if (empty($accessKeyId) || empty($bucket)) {
            return '';
        }

        try {
            $objectName = 'backup/full/' . date('Y/m/') . basename($filePath);
            $url = 'https://' . $bucket . '.' . $endpoint . '/' . $objectName;

            $client = new \GuzzleHttp\Client(['timeout' => 600]);

            $response = $client->put($url, [
                'headers' => [
                    'Content-Type' => 'application/octet-stream',
                ],
                'body' => fopen($filePath, 'r'),
            ]);

            if ($response->getStatusCode() === 200) {
                return $url;
            }

            throw new \RuntimeException("OSS 上传失败: HTTP " . $response->getStatusCode());
        } catch (\Throwable $e) {
            Log::error("OSS 上传失败: {$e->getMessage()}");
            return '';
        }
    }

    private function downloadFromOss(string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        try {
            $backupDir = runtime_path('backup');
            if (!is_dir($backupDir)) {
                mkdir($backupDir, 0755, true);
            }

            $fileName = basename(parse_url($url, PHP_URL_PATH));
            $localPath = $backupDir . $fileName;

            $client = new \GuzzleHttp\Client(['timeout' => 600]);
            $response = $client->get($url, ['sink' => $localPath]);

            if ($response->getStatusCode() === 200 && file_exists($localPath)) {
                return $localPath;
            }

            return null;
        } catch (\Throwable $e) {
            Log::error("OSS 下载失败: {$e->getMessage()}");
            return null;
        }
    }

    private function deleteFromOss(string $url): bool
    {
        if (empty($url) || strpos($url, 'http') !== 0) {
            return false;
        }

        try {
            $client = new \GuzzleHttp\Client(['timeout' => 30]);
            $response = $client->delete($url);
            return $response->getStatusCode() === 200;
        } catch (\Throwable $e) {
            Log::warning("OSS 删除失败: {$e->getMessage()}");
            return false;
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
}
