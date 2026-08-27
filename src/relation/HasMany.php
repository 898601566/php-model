<?php


namespace model\relation;

use model\Model;

/**
 * 一对多关联（hasMany）
 * 用法（定义在父模型中，可链式追加查询条件）：
 *   public function page()
 *   {
 *       return $this->hasMany(Page::class, 'type', 'id')
 *                   ->where('page_id', '<=', 100);
 *   }
 * 加载后关联数据以 Collection 挂到父模型的 $relation[关联名] 上。
 */
class HasMany extends Relation
{
    public $parent;
    public $model;
    public $foreignKey;
    public $localKey;
    public $query;

    public function __construct(Model $parent, string $model, string $foreignKey, string $localKey)
    {
        parent::__construct($parent, $model, $foreignKey, $localKey);
    }



}
