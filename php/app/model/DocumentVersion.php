<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 平台文档版本历史模型
 */
class DocumentVersion extends Model
{
    protected $name = 'document_versions';
    protected $autoWriteTimestamp = false;
    public $timestamps = false;

    public function admin()
    {
        return $this->belongsTo(Admin::class, 'admin_id', 'id');
    }
}