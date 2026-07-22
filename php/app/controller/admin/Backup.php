<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseController;
use app\model\Admin;
use app\model\BackupRecord;
use app\model\RestoreRecord;
use think\facade\Db;
use think\facade\Log;
use think\Request;

/**
 * 备份恢复控制器
 * 仅 admin/admin2 可见
 */
class Backup extends BaseController
{
    /**
     * 构造函数 - 权限校验：仅 admin/admin2 可见
     */
    public function __construct()
    {
        $this->checkPermission();
    }

    /**
     * 备份列表
     */
    public function list(Request $request)
    {
        [$page, $limit] = $this->pageParams();
        $backupType = $request->param('backup_type', '');
        $status     = $request->param('status', '');

        $query = BackupRecord::order('create_time', 'desc');

        if (!empty($backupType)) {
            $query->where('backup_type', $backupType);
        }

        if ($status !== '') {
            $query->where('status', (int)$status);
        }

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        $this->operationLog('admin_backup_list', '查看备份列表');

        return $this->page($list, $total, $page, $limit);
    }

    /**
     * 创建备份
     */
    public function create(Request $request)
    {
        $backupType = $request->param('backup_type', 'database'); // full/database/files

        $validTypes = ['full', 'database', 'files'];
        if (!in_array($backupType, $validTypes)) {
            return $this->error('备份类型无效，可选: full/database/files');
        }

        $fileName = 'backup_' . $backupType . '_' . date('YmdHis') . '.sql';
        $filePath = runtime_path() . 'backup/' . $fileName;

        $backupDir = dirname($filePath);
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        // 创建备份记录
        $backup = BackupRecord::create([
            'admin_id'   => $this->adminId(),
            'backup_type'=> $backupType,
            'file_name'  => $fileName,
            'file_path'  => $filePath,
            'file_size'  => 0,
            'status'     => BackupRecord::STATUS_PROCESS,
        ]);

        try {
            // 执行备份
            $this->performBackup($backupType, $filePath);

            $fileSize = file_exists($filePath) ? filesize($filePath) : 0;

            $backup->file_size = $fileSize;
            $backup->status = BackupRecord::STATUS_SUCCESS;
            $backup->save();

            $this->operationLog('admin_backup_create', "创建备份: {$backupType}，文件: {$fileName}");

            return $this->success([
                'id'        => $backup->id,
                'file_name' => $fileName,
                'file_size' => $fileSize,
                'backup_type' => $backupType,
            ], '备份创建成功');
        } catch (\Throwable $e) {
            $backup->status = BackupRecord::STATUS_FAIL;
            $backup->error_message = $e->getMessage();
            $backup->save();

            Log::error('备份失败: ' . $e->getMessage());

            return $this->error('备份失败: ' . $e->getMessage());
        }
    }

    /**
     * 一键恢复至指定时间点
     */
    public function restore(Request $request)
    {
        $backupId = $request->paramInt('backup_id', 0);

        if ($backupId <= 0) {
            return $this->error('备份ID无效');
        }

        $backup = BackupRecord::find($backupId);
        if (!$backup) {
            return $this->error('备份记录不存在', 404);
        }

        if ($backup->getData('status') != BackupRecord::STATUS_SUCCESS) {
            return $this->error('该备份不可恢复');
        }

        if (!file_exists($backup->getData('file_path'))) {
            return $this->error('备份文件不存在');
        }

        // 创建恢复记录
        $restore = RestoreRecord::create([
            'admin_id'   => $this->adminId(),
            'backup_id'  => $backupId,
            'status'     => 0, // 处理中
            'create_time'=> date('Y-m-d H:i:s'),
        ]);

        try {
            $this->performRestore($backup->getData('file_path'));

            $restore->status = 1; // 成功
            $restore->restore_time = date('Y-m-d H:i:s');
            $restore->save();

            $this->operationLog('admin_backup_restore', "恢复备份 ID:{$backupId}，文件: {$backup->getData('file_name')}");

            return $this->success([
                'restore_id'  => $restore->id,
                'backup_id'   => $backupId,
                'backup_file' => $backup->getData('file_name'),
            ], '恢复成功');
        } catch (\Throwable $e) {
            $restore->status = 2; // 失败
            $restore->error_message = $e->getMessage();
            $restore->save();

            Log::error('恢复失败: ' . $e->getMessage());

            return $this->error('恢复失败: ' . $e->getMessage());
        }
    }

    /**
     * 下载备份文件
     */
    public function download(Request $request)
    {
        $backupId = $request->paramInt('backup_id', 0);

        if ($backupId <= 0) {
            return $this->error('备份ID无效');
        }

        $backup = BackupRecord::find($backupId);
        if (!$backup) {
            return $this->error('备份记录不存在', 404);
        }

        $filePath = $backup->getData('file_path');
        if (!file_exists($filePath)) {
            return $this->error('备份文件不存在');
        }

        $this->operationLog('admin_backup_download', "下载备份文件 ID:{$backupId}");

        // 返回下载响应
        return download($filePath, $backup->getData('file_name'));
    }

    /**
     * 权限校验 - 仅 admin/admin2 可见
     */
    private function checkPermission(): void
    {
        $adminId = $this->adminId();
        $admin = Admin::find($adminId);

        if (!$admin) {
            throw new \think\exception\HttpException(403, '无权限访问');
        }

        // 检查用户名是否为 admin 或 admin2
        $username = $admin->getData('username');
        if (!in_array($username, ['admin', 'admin2'])) {
            throw new \think\exception\HttpException(403, '仅超级管理员可访问');
        }
    }

    /**
     * 执行备份
     * @param string $backupType
     * @param string $filePath
     */
    private function performBackup(string $backupType, string $filePath): void
    {
        $content = '';

        if ($backupType === 'database' || $backupType === 'full') {
            // 获取所有表
            $tables = Db::query('SHOW TABLES');
            $dbName = Db::getConfig('connections.mysql.database');

            $content .= "-- Database Backup\n";
            $content .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
            $content .= "-- Database: {$dbName}\n\n";

            $content .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

            foreach ($tables as $table) {
                $tableName = current($table);

                // 表结构
                $createTable = Db::query("SHOW CREATE TABLE `{$tableName}`");
                $content .= "DROP TABLE IF EXISTS `{$tableName}`;\n";
                $content .= $createTable[0]['Create Table'] . ";\n\n";

                // 表数据
                $rows = Db::table($tableName)->select()->toArray();
                if (!empty($rows)) {
                    $content .= "INSERT INTO `{$tableName}` VALUES\n";
                    $values = [];
                    foreach ($rows as $row) {
                        $vals = [];
                        foreach ($row as $val) {
                            if ($val === null) {
                                $vals[] = 'NULL';
                            } else {
                                $vals[] = "'" . addslashes((string)$val) . "'";
                            }
                        }
                        $values[] = '(' . implode(', ', $vals) . ')';
                    }
                    $content .= implode(",\n", $values) . ";\n\n";
                }
            }

            $content .= "SET FOREIGN_KEY_CHECKS=1;\n";
        }

        file_put_contents($filePath, $content);
    }

    /**
     * 执行恢复
     * @param string $filePath
     */
    private function performRestore(string $filePath): void
    {
        $sql = file_get_contents($filePath);

        // 分割 SQL 语句
        $statements = array_filter(
            array_map('trim', explode(";\n", $sql)),
            function ($s) {
                return !empty($s) && !preg_match('/^--/', $s);
            }
        );

        foreach ($statements as $statement) {
            Db::execute($statement);
        }
    }
}