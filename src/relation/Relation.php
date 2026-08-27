<?php


namespace model\relation;



use model\Model;
use model\Query;

/**
 * 模型关联基础类
 * 由 Model::hasOne() / Model::hasMany() 创建，持有父模型与关联模型实例；
 * 未定义方法经 __call 透传给关联模型（进而代理到其 Query），
 * 因此定义关联时可链式追加查询条件：
 *   $this->hasMany(Page::class, 'type', 'id')->where('page_id', '<=', 100);
 *
 * @package model\relation
 * @mixin Query
 */
abstract class Relation
{
    /**
     * 父模型实例（声明关联的一方）
     * @var Model
     */
    public $parent;

    /**
     * 关联模型类名
     * @var string
     */
    public $model;

    /**
     * 关联表中的外键字段名
     * @var string
     */
    public $foreignKey;

    /**
     * 父表中的本地键字段名（通常是主键）
     * @var string
     */
    public $localKey;

    /**
     * 关联模型实例（方法调用透传的目标）
     * @var Model
     */
    public $query;

    /**
     * 构造函数：保存关联定义并实例化关联模型
     *
     * @param Model  $parent     父模型实例
     * @param string $model      关联模型类名
     * @param string $foreignKey 关联表外键字段名
     * @param string $localKey   父表本地键字段名
     */
    public function __construct(Model $parent, string $model, string $foreignKey, string $localKey)
    {
        $this->parent = $parent;
        $this->model = $model;
        $this->foreignKey = $foreignKey;
        $this->localKey = $localKey;
        $this->query = new $model();
    }

    /**
     * 方法调用透传：转发给关联模型（进而代理到其 Query），
     * 用于定义关联时追加 where 等查询条件
     *
     * @param string $method 方法名
     * @param array  $args   参数列表
     *
     * @return $this
     */
    public function __call($method, $args)
    {
        $this->query = call_user_func_array([$this->query, $method], $args);
        return $this;
    }

    /**
     * 批量查询关联结果：按父模型本地键值集合做 in 查询
     *
     * @param array         $local_values 父模型本地键值集合
     * @param \Closure|null $closure      附加条件闭包（接收当前 Relation）
     *
     * @return mixed 关联查询结果集
     * @todo 当前返回 $this->query，应返回查询结果 $ret，待修复
     */
    public function relationResult($local_values, $closure)
    {
        //关联条件限定：外键 in (本地键值集合)
        $this->where($this->foreignKey, 'in', $local_values);
        //闭包调用：允许调用方追加查询条件
        if (!empty($closure) && $closure instanceof \Closure) {
            $closure($this);
        }
        $ret = $this->select();
        return $this->query;
    }

}
