<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class GameList extends Model
{
    protected $name = 'game_list';
    protected $autoWriteTimestamp = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    const STATUS_ENABLED  = 1;
    const STATUS_DISABLED = 0;

    public function scopeEnabled($query)
    {
        $query->where('status', self::STATUS_ENABLED);
    }

    public function scopeSorted($query)
    {
        $query->order('sort', 'asc');
    }

    public function packages()
    {
        return $this->hasMany(OrderPackage::class, 'game_id', 'id');
    }
}
