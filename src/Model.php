<?php

namespace model;

use model\relation\HasMany;
use model\relation\HasOne;

/**
 * 模型基类
 * 所有业务模型继承本类，获得以下能力：
 * 1. 属性读写：通过魔术方法 / ArrayAccess 直接操作数据库行数据；
 * 2. 查询代理：未定义的方法经 __call / __callStatic 转发给 Query 查询构造器，
 *    可直接链式调用 Item::where(...)->select() 等；
 * 3. 关联定义：通过 hasOne / hasMany 声明一对一、一对多关联。
 *
 * @mixin Query
 */
class Model implements \ArrayAccess
{

    /**
     * 模型属性数据（对应数据库一行记录，字段名 => 字段值）
     * @var array
     */
    protected $data = [];

    /**
     * 已加载的关联数据 [关联方法名 => Model|Collection]
     * @var array
     */
    public $relation = [];

    /**
     * 数据库主键字段名（子类可覆盖，默认 NULL）
     * @var string|null
     */
    public $pk = NULL;

    /**
     * 数据库表名（子类可覆盖；为 NULL 时按类名自动推导）
     * @var string|null
     */
    public $table = NULL;

    /**
     * 当前模型绑定的查询构造器实例
     * @var Query|null
     */
    public $queryInstance = NULL;

    /**
     * 构造函数：预初始化查询构造器（推导表名并绑定 Query 实例）
     */
    public function __construct()
    {
        // getQueryInstance() 本身不接收参数，此处利用其副作用完成 $queryInstance 初始化
        $queryInstance = $this->getQueryInstance($this);
    }

    /**
     * 修改器 设置数据对象的值
     * @access public
     *
     * @param string $name 名称
     * @param mixed $value 值
     *
     * @return void
     */
    public function __set($name, $value)
    {
        $this->setAttr($name, $value);
    }

    /**
     * 获取器 获取数据对象的值
     * @access public
     *
     * @param string $name 名称
     *
     * @return mixed
     */
    public function __get($name)
    {
        return $this->getAttr($name);
    }

    /**
     * 检测数据对象的值
     * @access public
     *
     * @param string $name 名称
     *
     * @return boolean
     */
    public function __isset($name)
    {
        return !is_null($this->getAttr($name));
    }

    /**
     * 销毁数据对象的值
     * @access public
     *
     * @param string $name 名称
     *
     * @return void
     */
    public function __unset($name)
    {
        unset($this->data[$name], $this->relation[$name]);
    }

    /**
     * 快捷创建当前模型的实例
     *
     * @param array $options 预留的配置参数（当前未使用）
     *
     * @return static
     */
    public static function instance($options = [])
    {

        return new static();
    }

    // ArrayAccess：支持以数组方式读写模型属性 $model['field']

    /**
     * 设置属性（$model['field'] = $value）
     *
     * @param string $name  属性名
     * @param mixed  $value 属性值
     *
     * @return void
     */
    public function offsetSet($name, $value)
    {
        $this->setAttr($name, $value);
    }

    /**
     * 属性是否存在且不为 NULL（isset($model['field'])）
     *
     * @param string $name 属性名
     *
     * @return boolean
     */
    public function offsetExists($name)
    {
        return $this->__isset($name);
    }

    /**
     * 销毁属性（unset($model['field'])）
     *
     * @param string $name 属性名
     *
     * @return void
     */
    public function offsetUnset($name)
    {
        $this->__unset($name);
    }

    /**
     * 读取属性（$model['field']）
     *
     * @param string $name 属性名
     *
     * @return mixed
     */
    public function offsetGet($name)
    {
        return $this->getAttr($name);
    }

    /**
     * 读取属性值（不存在的属性返回 NULL）
     *
     * @param string $name 属性名
     *
     * @return mixed
     */
    protected function getAttr($name)
    {
        $data = &$this->data;
        return isset($data[$name]) ? $data[$name] : NULL;
    }

    /**
     * 写入属性值
     *
     * @param string $name  属性名
     * @param mixed  $value 属性值
     *
     * @return void
     */
    protected function setAttr($name, $value)
    {
        $data = &$this->data;
        $data[$name] = $value;
    }

    /**
     * 实例方法代理：把未定义的方法转发给查询构造器
     * 例：$item->where('id', '=', 1)->select();
     *
     * @param string $method 方法名
     * @param array  $args   参数列表
     *
     * @return mixed
     */
    public function __call($method, $args)
    {
        $queryInstance = $this->getQueryInstance();
        $res = call_user_func_array([$queryInstance, $method], $args);
        return $res;
    }

    /**
     * 静态方法代理：把未定义的静态方法转发给（新实例的）查询构造器
     * 例：Item::where('id', '=', 1)->select();
     *
     * @param string $method 方法名
     * @param array  $args   参数列表
     *
     * @return mixed
     */
    public static function __callStatic($method, $args)
    {
        $queryInstance = (new static())->getQueryInstance();
        $res = call_user_func_array([$queryInstance, $method], $args);
        return $res;
    }

    /**
     * 返回当前模型绑定的查询构造器实例（不存在时创建）
     * 创建时若 $table 未设置，则按类名自动推导表名：
     * 类名去掉末尾的 "Model" 字符后转小写，例如 ItemModel => item
     * @return Query
     */
    public function getQueryInstance(): Query
    {
        if (empty($this->queryInstance)) {
            // 获取数据库表名
            if (empty($this->table)) {
                // 获取模型类名称
                $model_name = get_class($this);
                // 删除类名最后的 Model 字符
                if (strpos($model_name, 'Model')) {
                    $model_name = substr($model_name, 0, -5);
                }
                // 数据库表名与类名一致
                $this->table = strtolower($model_name);
            }
            $this->queryInstance = new Query($this);
        }
        return $this->queryInstance;

    }


    /**
     * 装载查询结果：把一行数据库记录写入当前模型并返回自身
     * （由 Query::select() 在包装结果集时调用）
     *
     * @param array $data 一行查询结果（字段名 => 字段值）
     *
     * @return $this
     */
    public function resultSet($data)
    {
        $this->data = $data;
        return $this;
    }

    /**
     * 当前模型数据是否为空
     *
     * @return boolean 数据为空返回 TRUE
     */
    public function isEmpty()
    {
        return !empty($this->data) ? FALSE : TRUE;
    }

    /**
     * 转换为数组：合并自身数据与已加载的非空关联数据
     *
     * @return array
     * @todo hidden append visible
     */
    public function toArray()
    {
        $data = $this->data;
        foreach ($this->relation as $key => $value) {
            if (!empty($value)) {
                $data[$key] = $value->toArray();
            }
        }
        return $data;
    }

    /**
     * 一对一绑定（hasOne）
     * 用法（定义在当前模型中）：
     *   public function item2()
     *   {
     *       return $this->hasOne(Item::class, 'id', 'id'); // 关联模型, 关联表外键, 本表本地键
     *   }
     * 加载后关联数据以单个 Model 挂到 $relation[关联名] 上。
     *
     * @param string $className  关联模型类名（如 Item::class）
     * @param string $foreignKey 关联表中的外键字段名
     * @param string $localKey   本表中的关联键字段名（通常是主键）
     *
     * @return HasOne
     */
    public function hasOne(string $className, string $foreignKey, string $localKey)
    {
        return new HasOne($this, $className, $foreignKey, $localKey);
    }

    /**
     * 一对多绑定（hasMany）
     * 用法（定义在当前模型中，可继续链式追加查询条件）：
     *   public function page()
     *   {
     *       return $this->hasMany(Page::class, 'type', 'id')
     *                   ->where('page_id', '<=', 100);
     *   }
     * 加载后关联数据以 Collection 挂到 $relation[关联名] 上。
     *
     * @param string $className  关联模型类名（如 Page::class）
     * @param string $foreignKey 关联表中的外键字段名
     * @param string $localKey   本表中的关联键字段名（通常是主键）
     *
     * @return HasMany
     */
    public function hasMany(string $className, string $foreignKey, string $localKey)
    {
        return new HasMany($this, $className, $foreignKey, $localKey);
    }
}
