<?php
declare(strict_types=1);

namespace app\service;

use app\model\OrderTypeConfig;
use app\model\OrderPackage;
use app\model\GameList;
use think\facade\Log;
use think\facade\Db;

class OrderTypeService
{
    public function getEnabledTypes(): array
    {
        try {
            return OrderTypeConfig::enabled()->sorted()->select()->toArray();
        } catch (\Throwable $e) {
            Log::error("获取订单类型失败: {$e->getMessage()}");
            return [];
        }
    }

    public function getTypeConfig(string $type): ?array
    {
        try {
            $config = OrderTypeConfig::where('type', $type)->find();
            return $config ? $config->toArray() : null;
        } catch (\Throwable $e) {
            Log::error("获取订单类型配置失败: {$e->getMessage()}");
            return null;
        }
    }

    public function getPackageList(int $gameId = 0, string $type = ''): array
    {
        try {
            $query = OrderPackage::enabled()->sorted();
            if ($gameId > 0) {
                $query->byGame($gameId);
            }
            if (!empty($type)) {
                $query->byType($type);
            }
            return $query->select()->toArray();
        } catch (\Throwable $e) {
            Log::error("获取套餐列表失败: {$e->getMessage()}");
            return [];
        }
    }

    public function getPackageDetail(int $packageId): ?array
    {
        try {
            $package = OrderPackage::find($packageId);
            return $package ? $package->toArray() : null;
        } catch (\Throwable $e) {
            Log::error("获取套餐详情失败: {$e->getMessage()}");
            return null;
        }
    }

    public function createPackage(array $data): int
    {
        try {
            Db::startTrans();
            $package = OrderPackage::create([
                'name'           => $data['name'],
                'game_id'        => $data['game_id'] ?? 0,
                'type'           => $data['type'] ?? 'duration',
                'duration_hours' => $data['duration_hours'] ?? 0,
                'games_count'    => $data['games_count'] ?? 0,
                'price'          => $data['price'] ?? '0',
                'original_price' => $data['original_price'] ?? '0',
                'status'         => $data['status'] ?? OrderPackage::STATUS_ENABLED,
                'sort'           => $data['sort'] ?? 0,
            ]);
            Db::commit();
            return $package->id;
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error("创建套餐失败: {$e->getMessage()}");
            throw $e;
        }
    }

    public function updatePackage(int $packageId, array $data): bool
    {
        try {
            Db::startTrans();
            $package = OrderPackage::find($packageId);
            if (!$package) {
                throw new \RuntimeException('套餐不存在');
            }
            $package->save($data);
            Db::commit();
            return true;
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error("更新套餐失败: {$e->getMessage()}");
            throw $e;
        }
    }

    public function togglePackageStatus(int $packageId): bool
    {
        try {
            $package = OrderPackage::find($packageId);
            if (!$package) {
                throw new \RuntimeException('套餐不存在');
            }
            $newStatus = $package->status == OrderPackage::STATUS_ENABLED
                ? OrderPackage::STATUS_DISABLED
                : OrderPackage::STATUS_ENABLED;
            $package->status = $newStatus;
            $package->save();
            return true;
        } catch (\Throwable $e) {
            Log::error("切换套餐状态失败: {$e->getMessage()}");
            throw $e;
        }
    }

    public function deletePackage(int $packageId): bool
    {
        try {
            $package = OrderPackage::find($packageId);
            if (!$package) {
                throw new \RuntimeException('套餐不存在');
            }
            $package->delete();
            return true;
        } catch (\Throwable $e) {
            Log::error("删除套餐失败: {$e->getMessage()}");
            throw $e;
        }
    }

    public function getGameList(): array
    {
        try {
            return GameList::enabled()->sorted()->select()->toArray();
        } catch (\Throwable $e) {
            Log::error("获取游戏列表失败: {$e->getMessage()}");
            return [];
        }
    }
}
