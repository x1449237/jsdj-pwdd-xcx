<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class OrderPackage extends Model
{
    protected $name = 'order_package';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const TYPE_DURATION = 'duration';
    const TYPE_GAMES    = 'games';

    const STATUS_ENABLED  = 1;
    const STATUS_DISABLED = 0;

    public function game()
    {
        return $this->belongsTo(GameList::class, 'game_id', 'id');
    }

    public function scopeEnabled($query)
    {
        $query->where('status', self::STATUS_ENABLED);
    }

    public function scopeByGame($query, int $gameId)
    {
        $query->where('game_id', $gameId);
    }

    public function scopeByType($query, string $type)
    {
        $query->where('type', $type);
    }

    public function scopeSorted($query)
    {
        $query->order('sort', 'asc');
    }

    public function getPriceAttr($value): string
    {
        return fen_to_yuan((int)$value);
    }

    public function setPriceAttr($value): int
    {
        return yuan_to_fen((string)$value);
    }

    public function getOriginalPriceAttr($value): string
    {
        return fen_to_yuan((int)$value);
    }

    public function setOriginalPriceAttr($value): int
    {
        return yuan_to_fen((string)$value);
    }
}
