<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: zhangyajun <448901948@qq.com>
// +----------------------------------------------------------------------

namespace model;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use model\relation\HasMany;
use model\relation\HasOne;
use model\relation\Relation;

/**
 * 数据集类：查询结果的容器（元素通常为 Model 实例）
 * 实现数组式访问（ArrayAccess）、计数（Countable）、遍历（IteratorAggregate）
 * 与 JSON 序列化（JsonSerializable），并提供链式数组操作与内存中过滤排序。
 * 通过 load() 可为集合内所有模型批量加载关联数据。
 */
class Collection implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
    /**
     * 数据集数据
     * @var array
     */
    protected $items = [];

    /**
     * 构造函数：接受数组、Collection 作为初始数据
     *
     * @param mixed $items 初始数据
     */
    public function __construct($items = [])
    {
        $this->items = $this->convertToArray($items);
    }

    /**
     * 快捷创建数据集实例
     *
     * @param mixed $items 初始数据
     *
     * @return static
     */
    public static function make($items = [])
    {
        return new static($items);
    }

    /**
     * 是否为空
     * @access public
     * @return bool
     */
    public function isEmpty()
    {
        return empty($this->items);
    }

    /**
     * 转换为数组：元素为 Model / Collection 时递归转换
     *
     * @return array
     */
    public function toArray()
    {
        return array_map(function ($value) {
            return ($value instanceof Model || $value instanceof self) ? $value->toArray() : $value;
        }, $this->items);
    }

    /**
     * 返回原始数据数组（不递归转换元素）
     *
     * @return array
     */
    public function all()
    {
        return $this->items;
    }

    /**
     * 合并数组
     *
     * @access public
     *
     * @param mixed $items
     *
     * @return static
     */
    public function merge($items)
    {
        return new static(array_merge($this->items, $this->convertToArray($items)));
    }

    /**
     * 交换数组中的键和值
     *
     * @access public
     * @return static
     */
    public function flip()
    {
        return new static(array_flip($this->items));
    }

    /**
     * 按指定键整理数据
     *
     * @access public
     *
     * @param mixed $items 数据
     * @param string $indexKey 键名
     *
     * @return array
     */
    public function dictionary($items = NULL, &$indexKey = NULL)
    {
        if ($items instanceof self || $items instanceof Paginator) {
            $items = $items->all();
        }

        $items = is_null($items) ? $this->items : $items;

        if ($items && empty($indexKey)) {
            $indexKey = is_array($items[0]) ? 'id' : $items[0]->getPk();
        }

        if (isset($indexKey) && is_string($indexKey)) {
            return array_column($items, NULL, $indexKey);
        }

        return $items;
    }

    /**
     * 比较数组，返回差集
     *
     * @access public
     *
     * @param mixed $items 数据
     * @param string $indexKey 指定比较的键名
     *
     * @return static
     */
    public function diff($items, $indexKey = NULL)
    {
        if ($this->isEmpty() || is_scalar($this->items[0])) {
            return new static(array_diff($this->items, $this->convertToArray($items)));
        }

        $diff = [];
        $dictionary = $this->dictionary($items, $indexKey);

        if (is_string($indexKey)) {
            foreach ($this->items as $item) {
                if (!isset($dictionary[$item[$indexKey]])) {
                    $diff[] = $item;
                }
            }
        }

        return new static($diff);
    }

    /**
     * 比较数组，返回交集
     *
     * @access public
     *
     * @param mixed $items 数据
     * @param string $indexKey 指定比较的键名
     *
     * @return static
     */
    public function intersect($items, $indexKey = NULL)
    {
        if ($this->isEmpty() || is_scalar($this->items[0])) {
            return new static(array_diff($this->items, $this->convertToArray($items)));
        }

        $intersect = [];
        $dictionary = $this->dictionary($items, $indexKey);

        if (is_string($indexKey)) {
            foreach ($this->items as $item) {
                if (isset($dictionary[$item[$indexKey]])) {
                    $intersect[] = $item;
                }
            }
        }

        return new static($intersect);
    }

    /**
     * 返回数组中所有的键名
     *
     * @access public
     * @return array
     */
    public function keys()
    {
        $current = current($this->items);

        if (is_scalar($current)) {
            $array = $this->items;
        } elseif (is_array($current)) {
            $array = $current;
        } else {
            $array = $current->toArray();
        }

        return array_keys($array);
    }

    /**
     * 删除数组的最后一个元素（出栈）
     *
     * @access public
     * @return mixed
     */
    public function pop()
    {
        return array_pop($this->items);
    }

    /**
     * 通过使用用户自定义函数，以字符串返回数组
     *
     * @access public
     *
     * @param callable $callback
     * @param mixed $initial
     *
     * @return mixed
     */
    public function reduce(callable $callback, $initial = NULL)
    {
        return array_reduce($this->items, $callback, $initial);
    }

    /**
     * 以相反的顺序返回数组。
     *
     * @access public
     * @return static
     */
    public function reverse()
    {
        return new static(array_reverse($this->items));
    }

    /**
     * 删除数组中首个元素，并返回被删除元素的值
     *
     * @access public
     * @return mixed
     */
    public function shift()
    {
        return array_shift($this->items);
    }

    /**
     * 在数组结尾插入一个元素
     * @access public
     *
     * @param mixed $value
     * @param mixed $key
     *
     * @return void
     */
    public function push($value, $key = NULL)
    {
        if (is_null($key)) {
            $this->items[] = $value;
        } else {
            $this->items[$key] = $value;
        }
    }

    /**
     * 把一个数组分割为新的数组块.
     *
     * @access public
     *
     * @param int $size
     * @param bool $preserveKeys
     *
     * @return static
     */
    public function chunk($size, $preserveKeys = FALSE)
    {
        $chunks = [];

        foreach (array_chunk($this->items, $size, $preserveKeys) as $chunk) {
            $chunks[] = new static($chunk);
        }

        return new static($chunks);
    }

    /**
     * 在数组开头插入一个元素
     * @access public
     *
     * @param mixed $value
     * @param mixed $key
     *
     * @return void
     */
    public function unshift($value, $key = NULL)
    {
        if (is_null($key)) {
            array_unshift($this->items, $value);
        } else {
            $this->items = [$key => $value] + $this->items;
        }
    }

    /**
     * 给每个元素执行个回调
     *
     * @access public
     *
     * @param callable $callback
     *
     * @return $this
     */
    public function each(callable $callback)
    {
        foreach ($this->items as $key => $item) {
            $result = $callback($item, $key);

            if (FALSE === $result) {
                break;
            } elseif (!is_object($item)) {
                $this->items[$key] = $result;
            }
        }

        return $this;
    }

    /**
     * 用回调函数处理数组中的元素
     * @access public
     *
     * @param callable|null $callback
     *
     * @return static
     */
    public function map(callable $callback)
    {
        return new static(array_map($callback, $this->items));
    }

    /**
     * 用回调函数过滤数组中的元素
     * @access public
     *
     * @param callable|null $callback
     *
     * @return static
     */
    public function filter(callable $callback = NULL)
    {
        if ($callback) {
            return new static(array_filter($this->items, $callback));
        }

        return new static(array_filter($this->items));
    }

    /**
     * 根据字段条件过滤数组中的元素
     * @access public
     *
     * @param string $field 字段名
     * @param mixed $operator 操作符
     * @param mixed $value 数据
     *
     * @return static
     */
    public function where($field, $operator, $value = NULL)
    {
        if (is_null($value)) {
            $value = $operator;
            $operator = '=';
        }

        return $this->filter(function ($data) use ($field, $operator, $value) {
            if (strpos($field, '.')) {
                list($field, $relation) = explode('.', $field);

                $result = isset($data[$field][$relation]) ? $data[$field][$relation] : NULL;
            } else {
                $result = isset($data[$field]) ? $data[$field] : NULL;
            }

            switch (strtolower($operator)) {
                case '===':
                    return $result === $value;
                case '!==':
                    return $result !== $value;
                case '!=':
                case '<>':
                    return $result != $value;
                case '>':
                    return $result > $value;
                case '>=':
                    return $result >= $value;
                case '<':
                    return $result < $value;
                case '<=':
                    return $result <= $value;
                case 'like':
                    return is_string($result) && FALSE !== strpos($result, $value);
                case 'not like':
                    return is_string($result) && FALSE === strpos($result, $value);
                case 'in':
                    return is_scalar($result) && in_array($result, $value, TRUE);
                case 'not in':
                    return is_scalar($result) && !in_array($result, $value, TRUE);
                case 'between':
                    list($min, $max) = is_string($value) ? explode(',', $value) : $value;
                    return is_scalar($result) && $result >= $min && $result <= $max;
                case 'not between':
                    list($min, $max) = is_string($value) ? explode(',', $value) : $value;
                    return is_scalar($result) && $result > $max || $result < $min;
                case '==':
                case '=':
                default:
                    return $result == $value;
            }
        });
    }

    /**
     * 返回数据中指定的一列
     * @access public
     *
     * @param mixed $columnKey 键名
     * @param mixed $indexKey 作为索引值的列
     *
     * @return array
     */
    public function column($columnKey, $indexKey = NULL)
    {
        return array_column($this->items, $columnKey, $indexKey);
    }

    /**
     * 对数组排序
     *
     * @access public
     *
     * @param callable|null $callback
     *
     * @return static
     */
    public function sort(callable $callback = NULL)
    {
        $items = $this->items;

        $callback = $callback ?: function ($a, $b) {
            return $a == $b ? 0 : (($a < $b) ? -1 : 1);

        };

        uasort($items, $callback);

        return new static($items);
    }

    /**
     * 指定字段排序
     * @access public
     *
     * @param string $field 排序字段
     * @param string $order 排序
     * @param bool $intSort 是否为数字排序
     *
     * @return $this
     */
    public function order($field, $order = NULL, $intSort = TRUE)
    {
        return $this->sort(function ($a, $b) use ($field, $order, $intSort) {
            $fieldA = isset($a[$field]) ? $a[$field] : NULL;
            $fieldB = isset($b[$field]) ? $b[$field] : NULL;

            if ($intSort) {
                return 'desc' == strtolower($order) ? $fieldB >= $fieldA : $fieldA >= $fieldB;
            } else {
                return 'desc' == strtolower($order) ? strcmp($fieldB, $fieldA) : strcmp($fieldA, $fieldB);
            }
        });
    }

    /**
     * 将数组打乱
     *
     * @access public
     * @return static
     */
    public function shuffle()
    {
        $items = $this->items;

        shuffle($items);

        return new static($items);
    }

    /**
     * 截取数组
     *
     * @access public
     *
     * @param int $offset
     * @param int $length
     * @param bool $preserveKeys
     *
     * @return static
     */
    public function slice($offset, $length = NULL, $preserveKeys = FALSE)
    {
        return new static(array_slice($this->items, $offset, $length, $preserveKeys));
    }

    // ArrayAccess：支持 $collection[0] 形式访问元素
    public function offsetExists($offset)
    {
        return array_key_exists($offset, $this->items);
    }

    public function offsetGet($offset)
    {
        return $this->items[$offset];
    }

    public function offsetSet($offset, $value)
    {
        if (is_null($offset)) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    public function offsetUnset($offset)
    {
        unset($this->items[$offset]);
    }

    //Countable：支持 count($collection)
    public function count()
    {
        return count($this->items);
    }

    //IteratorAggregate：支持 foreach ($collection as $item)
    public function getIterator()
    {
        return new ArrayIterator($this->items);
    }

    //JsonSerializable：支持 json_encode($collection)
    public function jsonSerialize()
    {
        return $this->toArray();
    }

    /**
     * 转换当前数据集为JSON字符串
     * @access public
     *
     * @param integer $options json参数
     *
     * @return string
     */
    public function toJson($options = JSON_UNESCAPED_UNICODE)
    {
        return json_encode($this->toArray(), $options);
    }

    /**
     * 魔术方法：直接输出数据集时转为 JSON 字符串
     *
     * @return string
     */
    public function __toString()
    {
        return $this->toJson();
    }

    /**
     * 转换成数组
     *
     * @access public
     *
     * @param mixed $items
     *
     * @return array
     */
    protected function convertToArray($items)
    {
        if ($items instanceof self) {
            return $items->all();
        }

        return (array)$items;
    }

    /**
     * 按数组定义批量加载关联（值为关联名，或 [关联名 => 条件闭包]）
     *
     * @param array          $relation 关联定义数组
     * @param \Closure|null  $closure  预留的统一闭包
     *
     * @return void
     */
    public function loadArray($relation, $closure = NULL)
    {
        //处理数组
        foreach ($relation as $key => $value) {
            //闭包调用和普通调用
            if (!empty($value instanceof \Closure)) {
                $this->load($key, $value);
            } else {
                $this->load($value);
            }
        }
    }

    /**
     * 加载relation
     *
     * @param Mixed $relation
     * @param \Closure $closure
     *
     * @return $this
     */
    public function load($relation, $closure = NULL)
    {
        if (empty($relation)) {
            return FALSE;
        }
        switch (TRUE) {
            case is_array($relation):
                $this->loadArray($relation, $closure);
                break;
            default:

                if (!$this->isEmpty()) {
                    //取第一个模型，调用其关联方法获得 Relation 对象（含外键/本地键定义）
                    $item = current($this->items);
                    /**
                     * @var  $relation_obj Relation 关系模型
                     */
                    $relation_obj = $item->$relation();
                    /**
                     * @var  $foreign_field string 外键字段
                     */
                    $foreign_field = $relation_obj->foreignKey;
                    /**
                     * @var  $local_field string 主键
                     */
                    $local_field = $relation_obj->localKey;
                    /**
                     * @var  $local_values string 主键字段对应的值
                     */
                    //收集所有模型的本地键值，一次 in 查询取出全部关联数据（避免 N+1）
                    $local_values = array_column($this->toArray(), $local_field);
                    $relationResult = $relation_obj->relationResult($local_values, $closure);
                    //源数据添加关联数据：按外键值把关联记录分组挂到各模型上
                    foreach ($this->items as $key => $value) {
                        $collection = new Collection();
                        foreach ($relationResult as $key2 => $value2) {
                            if ($value2[$foreign_field] == $value[$local_field]) {
                                $collection->push($value2);
                            }
                        }
                        //一对多挂整个 Collection，一对一挂首条记录
                        if ($relation_obj instanceof HasMany) {
                            $this->items[$key]->relation[$relation] = $collection;
                        }
                        if ($relation_obj instanceof HasOne) {
                            $this->items[$key]->relation[$relation] = $collection[0];
                        }
                    }
                }
                break;
        }
        return $this;
    }
}
