<?php


namespace model\relation;

use model\Model;

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
//    public function relationData(){
//        $obj = new $this->model();
//        //关联条件限定
//        $query = $obj->where($foreign_field, 'in', $local_values);
//        //闭包调用
//        if (!empty($closure) && $closure instanceof \Closure) {
//            $closure($query);
//        }
//        $ret = $query->select();
//    }
}
