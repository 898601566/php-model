<?php

namespace model;

use Helper\ArrayHelper;
use Helper\StringHelper;

class Query
{
    // 数据库表名
    protected $table;
    /**
     * @var \PDO|null
     */
    protected $pdo_object;

    private static $sqls = [];
    private static $source_sql = [];
    private $model;
    private $pk;
    // WHERE和ORDER拼装后的条件
    private $condition_str;
    private $join_str;
    private $alias_str;
    private $order_str;
    private $limit_str;
    private $group_str;
    private $bind;
    private $field;
    private $model_class_name;
    private $with_str;

    public function __construct(Model $model)
    {
        $this->model = $model;
        $this->pk = $model->pk;
        $this->pdo_object = PDOOBJ::instance();
        $rf = new \ReflectionObject($this->model);
        $this->model_class_name = $rf->name;
        $this->table = $model->table;
        $this->_init();
    }

    public function __isset($name)
    {
        return isset($this->$name);
    }

    public function __set($name, $value)
    {
        $this->$name = $value;
    }

    public function __get($name)
    {
        return $this->$name;
    }

    /**
     * 初始化
     */
    public function _init()
    {
        $this->bind = [];
        $this->field = "*";
        // 数据库主键
        $this->pk = 'id';
        // WHERE和ORDER拼装后的条件
        $this->condition_str = '';
        $this->join_str = '';
        $this->alias_str = '';
        $this->order_str = '';
        $this->limit_str = '';
        $this->group_str = '';
        $this->with_str = "";
        $this->bind = [];
        $this->field = "*";
    }

    /**
     * 清除搜索信息
     */
    public function clear()
    {
        $this->_init();
    }


    /**
     * 设置where条件
     *
     * @param $where
     * @param string $method
     * @param string $value
     *$field, $operate = null, $condition = null
     *
     * @return $this
     */
    public function where($field, $operate = '', $condition = NULL)
    {
        if (empty($field)) {
            return $this;
        }

        switch (TRUE) {
            case $field instanceof \Closure:
                $this->whereSplicce(" ( ", '', 'AND');
                $field($this);
                $this->whereSplicce(" ) ");
                break;
            case is_array($field):
                foreach ($field as $key => $value) {
                    if (is_array($value)) {
                        $this->where($value[0], $value[1], $value[2]);
                    } else {
                        $this->where($key, '=', $value);
                    }
                }
                break;
            case !empty($operate) && isset($condition):
                $bind_key = $this->getBindKey($field);
                switch ($operate) {
                    case 'in':
                        //处理非数组的值
                        $condition = is_array($condition) ? $condition : [$condition];
                        foreach ($condition as $condition2) {
                            $bind_key = $this->getBindKey($field);
                            $bind_keys[] = $bind_key;
                            $binds[$bind_key] = $condition2;
                        }
                        $bind_keys = implode(',', $bind_keys);
                        $this->whereSplicce("$field $operate ($bind_keys)", $binds, ' AND ');
                        break;
                    default:
                        $this->whereSplicce("$field $operate $bind_key", ["$bind_key" => $condition], ' AND ');
                        break;
                }
                break;
            default:
                $this->whereSplicce($field, '', 'AND');
                break;
        }
        return $this;
    }

    /**
     * 设置whereOr条件
     *
     * @param $where
     * @param string $method
     * @param string $value
     *$field, $operate = null, $condition = null
     *
     * @return $this
     */
    public function whereOr($field, $operate = '', $condition = NULL)
    {
        if (empty($field)) {
            return $this;
        }

        switch (TRUE) {
            case $field instanceof \Closure:
                $this->whereSplicce(" ( ", '', 'OR');
                $field($this);
                $this->whereSplicce(" ) ");
                break;
            case is_array($field):
                foreach ($field as $value) {
                    $this->whereOr($value[0], $value[1], $value[2]);
                }
                break;
            case !empty($operate) && isset($condition):
                $bind_key = $this->getBindKey($field);
                switch ($operate) {
                    case 'in':
                        //处理非数组的值
                        $condition = is_array($condition) ? $condition : [$condition];
                        foreach ($condition as $condition2) {
                            $bind_key = $this->getBindKey($field);
                            $bind_keys[] = $bind_key;
                            $binds[$bind_key] = $condition2;
                        }
                        $bind_keys = implode(',', $bind_keys);
                        $this->whereSplicce("$field $operate ($bind_keys)", $binds, ' OR ');
                        break;
                    default:
                        $this->whereSplicce("$field $operate $bind_key", ["$bind_key" => $condition], ' OR ');
                        break;
                }
                break;
            default:
                $this->whereSplicce($field, '', 'OR');
                break;
        }
        return $this;
    }

    /**
     * 设置join条件
     *
     * @param string $join
     * @param null $condition
     * @param string $type
     *
     * @return $this
     */
    public function join(string $join, $condition = NULL, $type = 'INNER'): self
    {
        $this->join_str = sprintf("%s %s %s %s %s ", $type, 'join', $join, 'on', $condition);
        return $this;
    }

    /**
     * 别名
     *
     * @param $alias
     *
     * @return $this
     */
    public function alias($alias): self
    {
        $this->alias_str = $alias;
        return $this;
    }

    /**
     * bind差异化
     *
     * @param $key
     * @param string $prefix
     *
     * @return string
     */
    public function getBindKey($key, $prefix = ''): string
    {
        $prefix = uniqid("cdt", FALSE) . $prefix;
        $bind_key = str_replace('.', '__', "$key");
        $bind_key = str_replace('%', '_', $bind_key);
        return ":{$prefix}_{$bind_key}";
    }

    /**
     * 拼接where
     *
     * @param $condition_str
     * @param array $bind
     * @param string $type AND OR 或者不填
     *
     * @return $this
     */
    protected function whereSplicce($condition_str, $bind = [], $type = ""): self
    {

        $trim_condition_str = trim($this->condition_str);
        //没有where填充where
        if (FALSE === strpos($trim_condition_str, 'WHERE')) {
            $this->condition_str .= " WHERE ";
            $trim_condition_str = trim($this->condition_str);
        }
        if ($condition_str) {
            //判断是否需要AND OR
            if (StringHelper::endsWith($trim_condition_str, 'WHERE')
                || StringHelper::endsWith($trim_condition_str, '(')) {
                $this->condition_str .= " $condition_str ";
            } else {
                $this->condition_str .= " {$type} $condition_str ";
            }
            if (!empty($bind)) {
                $this->addBind($bind);
            }
        }
        return $this;
    }

    /**
     *
     * @param $wherestr
     * @param array $bind
     *
     * @return $this
     */
    protected function addBind($bind = [], $prefix = '')
    {
        $prefix = $prefix ? "{$prefix}_" : $prefix;
        foreach ($bind as $key => $value) {
            $this->bind["{$prefix}{$key}"] = $value;
        }
        return $this;
    }

    /**
     * 绑定field
     *
     * @param $field 支持key-value的数组或原生字符串
     *
     * @return $this
     */
    public function field($field = [])
    {
        if (is_array($field)) {
            $this->field = "";
            foreach ($field as $key => $value) {
                $key = is_int($key) ? $value : $key;
                if (!empty($this->field)) {
                    $this->field .= ",$key as $value";
                } else {
                    $this->field .= "$key as $value";
                }
            }
        }
        if (is_string($field)) {
            $this->field = $field;
        }
        return $this;
    }

    /**
     * 拼装排序条件，使用方式：
     * $this->order(['id DESC', 'title ASC', ...])->fetch();
     * $this->order('id DESC,title ASC')->fetch();
     *
     * @param array $order 排序条件
     *
     * @return $this
     */
    public function order($order = [])
    {
        if ($order) {
            if (is_array($order)) {
                $this->order_str .= ' ORDER BY ';
                $this->order_str .= implode(',', $order);
            } else {
                $this->order_str .= $order;
            }
        }
        return $this;
    }
    /**
     * 分组
     *
     * @param $group
     *
     * @return $this
     */
    public function group($group)
    {
        if (!empty($group)) {
            $this->group_str .= " GROUP BY $group ";
        }
        return $this;
    }

    /**
     * 分页
     *
     * @param int $page
     * @param int $limit
     *
     * @return $this
     */
    public function limit($page = 1, $limit = 20)
    {
        if (!empty($page)) {
            $page = $page - 1;
            $page = $page * $limit;
            $this->limit_str .= " LIMIT $page,$limit ";
        } else {
            $this->limit_str .= " LIMIT $limit ";
        }
        return $this;
    }

    /**
     * 查询数组(单个查询最后还是会用到它)
     * @return Collection
     */
    public function select()
    {
        try {
            $sql = $this->composeSql();
            $sttmnt = $this->pdo_object->prepare($sql);
            $sttmnt = $this->formatBind($sttmnt, $this->bind);
            $sttmnt->execute();
            $res = $sttmnt->fetchAll();
            $model_class_name = $this->model_class_name;
            $ret = new Collection();
            foreach ($res as $key => $value) {
                /**
                 * @var Model $model
                 */
                $model = new $model_class_name();
                $ret->push($model->resultSet($value));
            }
            if (!empty($this->with_str)) {
                $ret->load($this->with_str);
            }
            $this->clear();
            return $ret;
        }
        catch (\PDOException $exception) {
            exit($exception->getMessage());
        }
    }

    /**
     * 组合查询SQl
     * @return string
     */
    public function composeSql()
    {
        $condition = $this->condition_str . $this->group_str . $this->order_str . $this->limit_str;
        $sql = sprintf('select %s from `%s` %s', $this->field, $this->table, $condition);
        return $sql;
    }

    /**
     *  直接查询sql
     */
    public function sql($sql)
    {
        try {
            $sttmnt = $this->pdo_object->prepare($sql);
            $sttmnt->execute();
            $res = $sttmnt->fetchAll();
            return $res;
        }
        catch (\PDOException $exception) {
            exit($exception->getMessage());
        }
    }

    /**
     *  直接查询sql
     */
    public function with($with)
    {
        $this->with_str = $with;
        return $this;
    }

    /**
     * @param array $id
     *
     * @return Model
     * @todo 主键查询
     */
    public function find($id = [])
    {

        if (!empty($id)) {
            $this->where($this->model->pk, '=', 1);
        }
        $res = $this->limit(1)->select();
        $one = $res[0];
        return $one;
    }


    public function count($field = '*')
    {
        $one = $this->field(["count($field)" => 'count'])->find();
        return !empty($one) ? $one['count'] : 0;
    }

    public function min($field)
    {
        $one = $this->field(["min($field)" => 'min'])->find();
        return !empty($one) ? $one['min'] : 0;
    }

    public function max($field)
    {
        $one = $this->field(["max($field)" => 'max'])->find();
        return !empty($one) ? $one['max'] : 0;
    }

    public function sum($field)
    {
        $one = $this->field(["sum($field)" => 'sum'])->find();
        return !empty($one) ? $one['sum'] : 0;
    }

    public function avg($field)
    {
        $one = $this->field(["avg($field)" => 'avg'])->find();
        return !empty($one) ? $one['avg'] : 0;
    }

    /**
     * 占位符绑定具体的变量值
     *
     * @param \PDOStatement $sttmnt 要绑定的PDOStatement对象
     * @param array $binds 参数
     *
     * @return \PDOStatement
     */
    public function formatBind(\PDOStatement $sttmnt, $binds = [])
    {
        if (empty($binds)) {
            $binds = $this->bind;
        }

        foreach ($binds as $bind => $value) {
            $bind = is_int($bind) ? $bind + 1 : ':' . trim($bind, ':');
            $sttmnt->bindValue($bind, $value);
            $binds[$bind] = "'$value'";
        }
        static::$sqls[] = strtr($sttmnt->queryString, $binds);
        static::$source_sql[] = [
            $sttmnt->queryString,
            $this->bind,
        ];

        return $sttmnt;
    }

    public function getLastSql()
    {

        return ArrayHelper::last(static::$sqls);
    }

    public function getSqls()
    {

        return static::$sqls;
    }

    public function getSourceSql()
    {

        return static::$source_sql;
    }

    /**
     * 新增数据
     *
     * @param type $data
     */
    public function insert($data)
    {
        try {
            $sql = sprintf('insert into `%s` %s', $this->table, $this->formatInsert($data));
            $sttmnt = $this->pdo_object->prepare($sql);
            $sttmnt = $this->formatBind($sttmnt);

            $this->clear();
            if ($sttmnt->execute()) {
                return $this->pdo_object->lastInsertId();
            }
        }
        catch (\PDOException $exception) {
            exit($exception->getMessage());
        }
    }

    /**
     * 更新数据
     *
     * @param type $data
     */
    public function update($data, $where = [])
    {
        try {
            if (!empty($where)) {
                $this->where($where);
            }
            $sql = sprintf('update `%s` set %s %s', $this->table, $this->formatUpdate($data), $this->condition_str);
            $sttmnt = $this->pdo_object->prepare($sql);
            $sttmnt = $this->formatBind($sttmnt);
            $sttmnt->execute();
            $this->clear();
            return $sttmnt->rowCount();
        }
        catch (\PDOException $exception) {
            exit($exception->getMessage());
        }
    }


    /**
     * 保存数据(如果数据已存在就更新,如果数据不存在就插入)
     *
     * @param $data
     * @param array $where
     *
     * @return int|string|void
     */
    public function save($data, $where)
    {
        $res = $this->where($where)->select();
        if (!empty($res) && FALSE == $res->isEmpty()) {
            return $this->update($data, $where);
        } else {
            return $this->insert($data, $where);
        }
    }

    /**
     * 将数组转换成插入格式的sql语句
     *
     * @param Array $data
     */
    private function formatInsert(array $data)
    {
        $field_arr = [];
        $bind_name_arr = [];
        $bind_data = [];
        foreach ($data as $key => $value) {
            $field_arr[] = sprintf('`%s`', $key);
            $bind_key = $this->getBindKey($key);
            $bind_name_arr[] = $bind_key;
            $bind_data[$bind_key] = $value;
        }
        $this->addBind($bind_data);
        $field = implode(',', $field_arr);
        $bind_name = implode(',', $bind_name_arr);
        $ret = sprintf('( %s ) values ( %s )', $field, $bind_name);
        return $ret;
    }

    /**
     * 将数组转换成更新格式的sql语句
     *
     * @param type $data
     *
     * @return type
     */
    private function formatUpdate($data)
    {
        $field_arr = [];
        $bind_data = [];
        foreach ($data as $key => $value) {
            $bind_key = $this->getBindKey($key);
            $field_arr[] = sprintf(' `%s` = %s ', $key, $bind_key);
            $bind_data[$bind_key] = $value;
        }
        $this->addBind($bind_data);
        $field = implode(',', $field_arr);
        return $field;
    }

    /**
     * 根据条件主键删除
     *
     * @param type $id
     *
     * @return type
     */
    public function delete($id)
    {
        try {
            $sql = sprintf("delete from `%s` where `%s` = :%s", $this->table, $this->pk, $this->pk);
            $sttmnt = $this->pdo_object->prepare($sql);
            $this->addBind([$this->pk => $id]);
            $sttmnt = $this->formatBind($sttmnt);
            $sttmnt->execute();
            $this->clear();
            return $sttmnt->rowCount();
        }
        catch (\PDOException $exception) {
            exit($exception->getMessage());
        }
    }

    /**
     * 开始事务
     */
    public function transaction(callable $callback)
    {
        $this->pdo_object->beginTransaction();
        try {
            call_user_func($callback);
        }
        catch (\Exception $exception) {
            $this->pdo_object->rollBack();
            throw $exception;
        }
        $this->pdo_object->commit();
    }

    /**
     * 开始事务
     */
    public function beginTransaction()
    {
        $this->pdo_object->beginTransaction();
    }

    /**
     * 提交事务
     */
    public function commit()
    {
        $this->pdo_object->commit();
    }

    /**
     * 回滚
     */
    public function roolback()
    {
        $this->pdo_object->rollBack();
    }


}
