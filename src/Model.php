<?php

namespace model;

/**
 * @mixin Query
 */
class Model implements \ArrayAccess
{

    protected static $queryInstance;

    protected $data = [];
    public $relation = [];
    public $pk = NULL;

    public function __construct()
    {
        $queryInstance = $this->getQueryInstance();
        // 获取数据库表名
        if (!$this->table) {
            // 获取模型类名称
            $model_name = get_class($this);
            // 删除类名最后的 Model 字符
            if (FALSE == strpos($model_name, 'Model')) {
                $model_name = substr($model_name, 0, -5);
            }
            // 数据库表名与类名一致
            $this->table = strtolower($model_name);
        }
        $queryInstance->table = strtolower($this->table);
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
     * @param array $options
     *
     * @return static
     */
    public static function instance($options = [])
    {

        return new static();
    }

    // ArrayAccess
    public function offsetSet($name, $value)
    {
        $this->setAttr($name, $value);
    }

    public function offsetExists($name)
    {
        return $this->__isset($name);
    }

    public function offsetUnset($name)
    {
        $this->__unset($name);
    }

    public function offsetGet($name)
    {
        return $this->getAttr($name);
    }

    protected function getAttr($name)
    {
        $data = &$this->data;
        return isset($data[$name]) ? $data[$name] : NULL;
    }

    protected function setAttr($name, $value)
    {
        $data = &$this->data;
        $data[$name] = $value;
    }

    public function __call($method, $args)
    {
        $queryInstance = $this->getQueryInstance();
        $res = call_user_func_array([$queryInstance, $method], $args);
        static::$queryInstance = NULL;
        return $res;
    }

    public static function __callStatic($method, $args)
    {
        $queryInstance = (static::instance())->getQueryInstance();
        $res = call_user_func_array([$queryInstance, $method], $args);
        static::$queryInstance = NULL;
        return $res;
    }

    /**
     * 返回QueryInstance
     * @return Query
     */
    public function getQueryInstance(): Query
    {
        if (empty(static::$queryInstance)) {
            static::$queryInstance = new Query($this);
        }
        return static::$queryInstance;
    }


    /**
     * 处理搜索结果
     */
    public function resultSet($data)
    {
        $this->data = $data;
        return $this;
    }

    /**
     * 处理搜索结果
     */
    public function isEmpty()
    {
        return !empty($this->data) ? FALSE : TRUE;
    }

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
}
