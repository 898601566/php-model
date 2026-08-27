<?php


namespace model\relation;


use model\Model;

/**
 * 一对一关联（hasOne）
 * 用法（定义在父模型中）：
 *   public function item2()
 *   {
 *       return $this->hasOne(Item::class, 'id', 'id'); // 关联模型, 关联表外键, 父表本地键
 *   }
 * 加载后关联数据以单个 Model 挂到父模型的 $relation[关联名] 上。
 */
class HasOne extends Relation
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
