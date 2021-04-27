<?php


namespace model\relation;



use model\Model;
use model\Query;

/**
 * 模型关联基础类
 * @package model\relation
 * @mixin Query
 */
abstract class Relation
{
    public $parent;
    public $model;
    public $foreignKey;
    public $localKey;
    public $query;

    public function __construct(Model $parent, string $model, string $foreignKey, string $localKey)
    {
        $this->parent = $parent;
        $this->model = $model;
        $this->foreignKey = $foreignKey;
        $this->localKey = $localKey;
        $this->query = new $model();
    }

    public function __call($method, $args)
    {
        $this->query = call_user_func_array([$this->query, $method], $args);
        return $this;
    }
    /**
     * @param $local_values
     * @param $closure
     *
     * @return mixed
     */
    public function relationResult($local_values, $closure)
    {
        //关联条件限定
        $this->where($this->foreignKey, 'in', $local_values);
        //闭包调用
        if (!empty($closure) && $closure instanceof \Closure) {
            $closure($this);
        }
        $ret = $this->select();
        return $this->query;
    }

}
