<?php
declare(strict_types=1);

namespace app\service;

use app\model\PlayerTag;
use app\model\User;
use think\facade\Log;
use think\facade\Db;

class PlayerTagService
{
    private const TAG_WEIGHTS = [
        'game'     => 0.30,
        'rank'     => 0.25,
        'position' => 0.20,
        'voice'    => 0.15,
        'skill'    => 0.10,
    ];

    public function getPlayerTags(int $playerId): array
    {
        try {
            $tags = PlayerTag::byPlayer($playerId)->select()->toArray();
            $result = [];
            foreach ($tags as $tag) {
                $type = $tag['tag_type'];
                if (!isset($result[$type])) {
                    $result[$type] = [];
                }
                $result[$type][] = $tag['tag_value'];
            }
            return $result;
        } catch (\Throwable $e) {
            Log::error("获取打手标签失败: player_id={$playerId}, error={$e->getMessage()}");
            return [];
        }
    }

    public function getPlayerFlatTags(int $playerId): array
    {
        try {
            return PlayerTag::byPlayer($playerId)
                ->column('tag_value', 'id');
        } catch (\Throwable $e) {
            Log::error("获取打手扁平标签失败: player_id={$playerId}, error={$e->getMessage()}");
            return [];
        }
    }

    public function setPlayerTags(int $playerId, array $tags): bool
    {
        try {
            Db::startTrans();
            PlayerTag::byPlayer($playerId)->delete();
            $insertData = [];
            foreach ($tags as $type => $values) {
                if (is_array($values)) {
                    foreach ($values as $value) {
                        $insertData[] = [
                            'player_user_id' => $playerId,
                            'tag_type'       => $type,
                            'tag_value'      => $value,
                            'create_time'    => date('Y-m-d H:i:s'),
                        ];
                    }
                }
            }
            if (!empty($insertData)) {
                Db::name('player_tag')->insertAll($insertData);
            }
            Db::commit();
            return true;
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error("设置打手标签失败: player_id={$playerId}, error={$e->getMessage()}");
            throw $e;
        }
    }

    public function addPlayerTag(int $playerId, string $type, string $value): bool
    {
        try {
            $exists = PlayerTag::byPlayer($playerId)
                ->byType($type)
                ->where('tag_value', $value)
                ->find();
            if ($exists) {
                return true;
            }
            PlayerTag::create([
                'player_user_id' => $playerId,
                'tag_type'       => $type,
                'tag_value'      => $value,
            ]);
            return true;
        } catch (\Throwable $e) {
            Log::error("添加打手标签失败: player_id={$playerId}, error={$e->getMessage()}");
            throw $e;
        }
    }

    public function removePlayerTag(int $playerId, string $type, string $value): bool
    {
        try {
            PlayerTag::byPlayer($playerId)
                ->byType($type)
                ->where('tag_value', $value)
                ->delete();
            return true;
        } catch (\Throwable $e) {
            Log::error("删除打手标签失败: player_id={$playerId}, error={$e->getMessage()}");
            throw $e;
        }
    }

    public function calculateMatchScore(int $playerId, array $requireTags): float
    {
        try {
            $playerTags = $this->getPlayerTags($playerId);
            $totalScore = 0.0;
            $totalWeight = 0.0;

            foreach (self::TAG_WEIGHTS as $type => $weight) {
                if (isset($requireTags[$type]) && !empty($requireTags[$type])) {
                    $required = (array)$requireTags[$type];
                    $playerTypeTags = $playerTags[$type] ?? [];
                    if (empty($playerTypeTags)) {
                        $matchRate = 0;
                    } else {
                        $intersect = array_intersect($required, $playerTypeTags);
                        $matchRate = count($intersect) / count($required);
                    }
                    $totalScore += $matchRate * $weight;
                    $totalWeight += $weight;
                }
            }

            if ($totalWeight > 0) {
                $finalScore = $totalScore / $totalWeight;
            } else {
                $finalScore = 1.0;
            }

            return round($finalScore, 4);
        } catch (\Throwable $e) {
            Log::error("计算匹配度失败: player_id={$playerId}, error={$e->getMessage()}");
            return 0.0;
        }
    }

    public function getMatchedPlayers(array $requireTags, int $limit = 10): array
    {
        try {
            $gameFilter = $requireTags['game'] ?? [];
            $query = User::where('user_type', User::TYPE_PLAYER)
                ->where('status', User::STATUS_NORMAL);

            if (!empty($gameFilter)) {
                $query->whereExists(function ($query) use ($gameFilter) {
                    $query->table('player_tag pt')
                        ->where('pt.player_user_id', Db::raw('user.id'))
                        ->where('pt.tag_type', 'game')
                        ->whereIn('pt.tag_value', $gameFilter);
                });
            }

            $players = $query->column('id');

            $scored = [];
            foreach ($players as $playerId) {
                $score = $this->calculateMatchScore($playerId, $requireTags);
                $scored[] = [
                    'player_id'   => $playerId,
                    'match_score' => $score,
                ];
            }

            usort($scored, function ($a, $b) {
                return $b['match_score'] <=> $a['match_score'];
            });

            return array_slice($scored, 0, $limit);
        } catch (\Throwable $e) {
            Log::error("获取匹配打手失败: error={$e->getMessage()}");
            return [];
        }
    }
}
