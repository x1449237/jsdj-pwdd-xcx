<?php
declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use app\model\BackupRecord;
use app\model\CronJobLog;
use think\facade\Log;
use think\facade\Db;

/**
 * 每日自动备份
 * 每日凌晨执行，AES-256加密全量备份至OSS
 */
class DailyBackup extends Command
{
    protected function configure(): void
    {
        $this->setName('backup:daily')
            ->setDescription('每日凌晨AES-256加密全量备份至OSS');
    }

    protected function execute(Input $input, Output $output): int
    {
        $startTime = microtime(true);
        $output->writeln("[" . date('Y-m-d H:i:s') . "] 开始执行每日自动备份...");

        try {
            $backupFile = $this->createBackup();
            $backupFile = $this->encryptBackup($backupFile);
            $ossUrl = $this->uploadToOss($backupFile);

            // 清理本地临时文件
            @unlink($backupFile);

            $elapsed = round(microtime(true) - $startTime, 3);
            $fileSize = $ossUrl ? '已上传' : '上传失败';

            $output->writeln("[" . date('Y-m-d H:i:s') . "] 每日备份完成，文件={$ossUrl}，耗时 {$elapsed}s");

            // 记录备份日志
            BackupRecord::create([
                'file_name'  => basename($backupFile),
                'file_size'  => $fileSize,
                'file_path'  => $ossUrl,
                'backup_type'=> 'full',
                'status'     => $ossUrl ? 1 : 0,
            ]);

            // 记录定时任务日志
            CronJobLog::create([
                'job_name'    => 'backup:daily',
                'status'      => $ossUrl ? 1 : 0,
                'result'      => $ossUrl ?: 'OSS上传失败',
                'elapsed'     => $elapsed,
                'execute_time'=> date('Y-m-d H:i:s'),
            ]);

            Log::info("每日备份完成，文件={$ossUrl}，耗时 {$elapsed}s");
            return $ossUrl ? 0 : 1;
        } catch (\Throwable $e) {
            $output->writeln("<error>每日备份失败: {$e->getMessage()}</error>");

            CronJobLog::create([
                'job_name'    => 'backup:daily',
                'status'      => 0,
                'result'      => $e->getMessage(),
                'elapsed'     => round(microtime(true) - $startTime, 3),
                'execute_time'=> date('Y-m-d H:i:s'),
            ]);

            Log::error("每日备份失败: {$e->getMessage()}");
            return 1;
        }
    }

    /**
     * 创建数据库备份
     * @return string 备份文件路径
     */
    private function createBackup(): string
    {
        $backupDir = runtime_path('backup');
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $fileName = 'backup_' . date('Ymd_His') . '.sql';
        $filePath = $backupDir . $fileName;

        // 获取数据库配置
        $host = config_get('database.hostname', '127.0.0.1');
        $port = config_get('database.hostport', '3306');
        $user = config_get('database.username', 'root');
        $password = config_get('database.password', '');
        $database = config_get('database.database', 'game_platform');

        // 使用 mysqldump 导出
        $passwordArg = $password ? "-p'{$password}'" : '';
        $command = "mysqldump -h{$host} -P{$port} -u{$user} {$passwordArg} --single-transaction --quick --lock-tables=false {$database} > {$filePath} 2>&1";

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \RuntimeException("mysqldump 失败: " . implode("\n", $output));
        }

        Log::info("数据库备份文件创建: {$filePath}");

        return $filePath;
    }

    /**
     * AES-256加密备份文件
     * @param string $filePath
     * @return string 加密后的文件路径
     */
    private function encryptBackup(string $filePath): string
    {
        $encryptedPath = $filePath . '.enc';

        // 使用 AES-256-CBC 加密
        $key = substr(hash('sha256', config_get('jwt.secret_key', 'default_key'), true), 0, 32);
        $iv = openssl_random_pseudo_bytes(16);

        $plainData = file_get_contents($filePath);
        if ($plainData === false) {
            throw new \RuntimeException("读取备份文件失败: {$filePath}");
        }

        $encryptedData = openssl_encrypt($plainData, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        if ($encryptedData === false) {
            throw new \RuntimeException("AES-256 加密失败");
        }

        // 写入 IV + 加密数据
        $output = $iv . $encryptedData;
        file_put_contents($encryptedPath, $output);

        // 删除原始文件
        @unlink($filePath);

        Log::info("备份文件加密完成: {$encryptedPath}");

        return $encryptedPath;
    }

    /**
     * 上传到 OSS
     * @param string $filePath
     * @return string OSS URL
     */
    private function uploadToOss(string $filePath): string
    {
        $accessKeyId = config_get('oss.oss_access_key_id', '');
        $accessKeySecret = config_get('oss.oss_access_key_secret', '');
        $bucket = config_get('oss.oss_bucket', '');
        $endpoint = config_get('oss.oss_endpoint', '');
        $cdnDomain = config_get('oss.oss_cdn_domain', '');

        if (empty($accessKeyId) || empty($bucket)) {
            Log::warning('OSS 配置缺失，跳过上传');
            return '';
        }

        try {
            $objectName = 'backup/' . date('Y/m/') . basename($filePath);
            $url = 'https://' . $bucket . '.' . $endpoint . '/' . $objectName;

            $client = new \GuzzleHttp\Client(['timeout' => 300]);

            $response = $client->put($url, [
                'headers' => [
                    'Content-Type' => 'application/octet-stream',
                ],
                'body' => fopen($filePath, 'r'),
            ]);

            if ($response->getStatusCode() === 200) {
                $ossUrl = $cdnDomain ? $cdnDomain . '/' . $objectName : $url;
                Log::info("备份文件上传 OSS 成功: {$ossUrl}");
                return $ossUrl;
            }

            throw new \RuntimeException("OSS 上传失败: HTTP " . $response->getStatusCode());
        } catch (\Throwable $e) {
            Log::error("OSS 上传失败: {$e->getMessage()}");
            return '';
        }
    }
}