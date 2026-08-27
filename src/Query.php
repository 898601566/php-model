<?php

namespace model;

use Helper\ArrayHelper;
use Helper\StringHelper;

/**
 * 查询构造器
 * 负责拼装 SQL（字段 / WHERE / JOIN / 分组 / 排序 / 分页）并通过 PDO 预处理执行，
 * 所有条件值均以命名占位符参数绑定，避免 SQL 注入。
 *
 * 一次查询完成后会自动 clear() 复位拼装状态，实例可复用发起下一次查询。
 * 模型类通过 __call / __callStatic 代理到本类，故可写作：
 *   Item::where('id', '=', 1)->order('id desc')->select();
 */
class Query
{
    /**
     * 数据库表名（来自绑定模型）
     * @var string
     */
    protected $table;

    /**
     * PDO 连接实例（PDOOBJ 单例）
     * @var \PDO|null
     */
    protected $pdo_object;

    /**
     * 已执行 SQL 日志（占位符已替换为值，便于直观查看）
     * @var array
     */
    private static $sqls = [];

    /**
     * 已执行 SQL 原始日志 [ [SQL, 绑定参数], ... ]
     * @var array
     */
    private static $source_sql = [];

    /**
     * 绑定的模型实例
     * @var Model
     */
    private $model;

    /**
     * 数据库主键字段名
     * @var string
     */
    private $pk;

    /**
     * WHERE 拼装后的条件串（含 WHERE 关键字与 AND / OR 连接词）
     * @var string
     */
    private $condition_str;

    /**
     * JOIN 子句
     * @var string
     */
    private $join_str;

    /**
     * 表别名
     * @var string
     */
    private $alias_str;

    /**
     * ORDER BY 子句
     * @var string
     */
    private $order_str;

    /**
     * LIMIT 子句
     * @var string
     */
    private $limit_str;

    /**
     * GROUP BY 子句
     * @var string
     */
    private $group_str;

    /**
     * 参数绑定表 [占位符 => 值]
     * @var array
     */
    private $bind;

    /**
     * 查询字段（默认 *）
     * @var string
     */
    private $field;

    /**
     * 绑定的模型完整类名（包装查询结果行时使用）
     * @var string
     */
    private $model_class_name;

    /**
     * 预加载的关联定义（with 方法设置）
     * @var array|string
     */
    private $with_str;

    /**
     * 构造函数：绑定模型并初始化 PDO 连接与查询状态
     *
     * @param Model $model 绑定的模型实例（提供表名、主键、结果包装类）
     */
    public function __construct(Model $model)
    {
        $this->model = $model;
        //模型未定义主键时回退为 id
        $this->pk = $model->pk ?: 'id';
        //获取 PDO 单例连接
        $this->pdo_object = PDOOBJ::instance();
        //反射获取模型完整类名，查询结果将包装为该类的实例
        $rf = new \ReflectionObject($this->model);
        $this->model_class_name = $rf->name;
        $this->table = $model->table;
        $this->_init();
    }

    /**
     * 魔术方法：以属性形式检测成员是否存在（仅对已声明属性生效）
     *
     * @param string $name 成员名
     *
     * @return boolean
     */
    public function __isset($name)
    {
        return isset($this->$name);
    }

    /**
     * 魔术方法：以属性形式写入成员
     *
     * @param string $name  成员名
     * @param mixed  $value 值
     *
     * @return void
     */
    public function __set($name, $value)
    {
        $this->$name = $value;
    }

    /**
     * 魔术方法：以属性形式读取成员
     *
     * @param string $name 成员名
     *
     * @return mixed
     */
    public function __get($name)
    {
        return $this->$name;
    }

    /**
     * 初始化 / 复位查询拼装状态（字段、条件、绑定参数等恢复默认值）
     * 注意：主键 $pk 在构造时从模型读取，不在此复位
     */
    public function _init()
    {
        $this->bind = [];
        $this->field = "*";
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
     * 清除查询信息：复位全部拼装状态，便于复用当前实例发起新查询
     * 每次 select / insert / update / delete 执行后内部会自动调用
     *
     * @return void
     */
    public function clear()
    {
        $this->_init();
    }


    /**
     * 设置 where 条件（多个条件间用 AND 连接）
     * 用法示例：
     *   1. 三参数：   where('id', '=', 1) / where('id', 'in', [1, 2, 3])
     *   2. 数组：     where([ ['id', '>', 3], ['status', '=', 1] ])
     *                where(['status' => 1, 'type' => 2])  // 键值对默认等值
     *   3. 闭包嵌套： where(function ($query) {
     *                    $query->where('id', 'in', [1, 2])
     *                          ->whereOr('status', '=', 2);
     *                })                                     // 生成 ( ... ) 分组
     *   4. 原生字符串：where('id > 10')                       // 值不经绑定，慎用
     *
     * @param mixed  $field     字段名 / 数组条件 / 闭包 / 原生条件串
     * @param string $operate   操作符（=、>、<、>=、<=、in、like 等）
     * @param mixed  $condition 操作值（in 操作符时可传数组）
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
                        //为每个值生成独立占位符，拼成 field in (:a,:b,...) 形式
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
     * 设置 whereOr 条件（多个条件间用 OR 连接），参数格式与 where() 一致
     * 用法示例：
     *   whereOr('status', '=', 2)            // ... OR status = 2
     *   whereOr(function ($query) { ... })   // ... OR ( ... ) 分组
     *
     * @param mixed  $field     字段名 / 数组条件 / 闭包 / 原生条件串
     * @param string $operate   操作符
     * @param mixed  $condition 操作值
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
                        //为每个值生成独立占位符，拼成 field in (:a,:b,...) 形式
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
     * 设置 join 条件
     * 用法：join('user u', 'u.id = order.user_id', 'LEFT')
     * 生成：LEFT join user u on u.id = order.user_id
     *
     * @param string $join      关联的表名（可带别名）
     * @param mixed  $condition ON 关联条件（原生表达式）
     * @param string $type      连接类型：INNER / LEFT / RIGHT
     *
     * @return $this
     */
    public function join(string $join, $condition = NULL, $type = 'INNER'): self
    {
        $this->join_str = sprintf("%s %s %s %s %s ", $type, 'join', $join, 'on', $condition);
        return $this;
    }

    /**
     * 设置当前表的别名
     * 用法：alias('o')->join('user u', 'u.id = o.user_id')
     *
     * @param string $alias 别名
     *
     * @return $this
     */
    public function alias($alias): self
    {
        $this->alias_str = $alias;
        return $this;
    }

    /**
     * 生成差异化的参数绑定占位符
     * 以 uniqid 前缀保证同一字段多次条件（如同字段两个 where）占位符不冲突，
     * 并把字段名中的 "."、"%" 替换为占位符中的合法字符。
     * 例：getBindKey('id') => ":cdt5f1e2a3b4c5d6_id"
     *
     * @param string $key    字段名
     * @param string $prefix 额外前缀
     *
     * @return string 命名占位符（以 : 开头）
     */
    public function getBindKey($key, $prefix = ''): string
    {
        $prefix = uniqid("cdt", FALSE) . $prefix;
        //替换占位符中不合法的字符（. 和 %）
        $bind_key = str_replace('.', '__', "$key");
        $bind_key = str_replace('%', '_', $bind_key);
        return ":{$prefix}_{$bind_key}";
    }

    /**
     * 拼接 where 条件片段到条件串
     * 规则：
     * 1. 条件串尚无 WHERE 关键字时先补上；
     * 2. 紧邻 WHERE 或 "(" 时直接拼接（首个条件 / 闭包分组起始），否则用 AND/OR 连接；
     * 3. 携带的绑定参数一并注册到 $this->bind。
     *
     * @param string $condition_str 条件片段
     * @param array  $bind          绑定参数 [占位符 => 值]
     * @param string $type          连接词 AND / OR，首个条件时忽略
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
            //注册本片段的绑定参数
            if (!empty($bind)) {
                $this->addBind($bind);
            }
        }
        return $this;
    }

    /**
     * 批量注册绑定参数到 $this->bind（可加统一前缀避免占位符重名）
     *
     * @param array  $bind   绑定参数 [占位符 => 值]
     * @param string $prefix 占位符前缀
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
     * 设置查询字段
     * 用法：
     *   field('id, name')                  // 原生字符串
     *   field(['count(*)' => 'count'])     // 键值对：字段 => 别名
     *   field(['id', 'name'])              // 索引数组（值会作为别名输出，见实现）
     *
     * @param mixed $field 支持键值对数组或原生字符串
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
     * order(['id DESC', 'title ASC', ...])->select();
     * order('id DESC,title ASC')->select();
     *
     * @param mixed $order 排序条件（数组或字符串）
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
     * 设置分组条件
     * 用法：group('status') => GROUP BY status
     *
     * @param string $group GROUP BY 字段表达式
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
     * 分页：按"页码 + 每页条数"换算为 LIMIT 偏移量
     * 用法：limit(2, 20)  // 第 2 页、每页 20 条 => LIMIT 20,20
     * 注意：$page 为空（0 / null）时直接输出 LIMIT $limit
     *
     * @param int $page  页码（从 1 开始）
     * @param int $limit 每页条数
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
     * 执行查询，返回结果集（单条 / 聚合查询最终都经由本方法）
     * 流程：拼装 SQL -> 预处理 -> 绑定参数 -> 执行 -> 逐行包装为模型对象装入 Collection
     *       -> 若设置过 with() 则批量加载关联 -> 复位查询状态
     *
     * @return Collection 元素为绑定模型的实例
     */
    public function select()
    {
        try {
            $sql = $this->composeSql();
            //预处理并绑定参数后执行
            $sttmnt = $this->pdo_object->prepare($sql);
            $sttmnt = $this->formatBind($sttmnt, $this->bind);
            $sttmnt->execute();
            $res = $sttmnt->fetchAll();
            //逐行包装为绑定模型的实例
            $model_class_name = $this->model_class_name;
            $ret = new Collection();
            foreach ($res as $key => $value) {
                /**
                 * @var Model $model
                 */
                $model = new $model_class_name();
                $ret->push($model->resultSet($value));
            }
            //批量加载 with() 声明的关联，避免逐行查询（N+1）
            if (!empty($this->with_str)) {
                $ret->load($this->with_str);
            }
            //复位查询状态，实例可复用
            $this->clear();
            return $ret;
        }
        catch (\PDOException $exception) {
            exit($exception->getMessage());
        }
    }

    /**
     * 组合完整查询 SQL：select 字段 from `表` [WHERE][GROUP BY][ORDER BY][LIMIT]
     * @return string
     */
    public function composeSql()
    {
        $condition = $this->condition_str . $this->group_str . $this->order_str . $this->limit_str;
        $sql = sprintf('select %s from `%s` %s', $this->field, $this->table, $condition);
        return $sql;
    }

    /**
     * 直接执行原生 SQL 查询（不走参数绑定，勿拼接用户输入）
     *
     * @param string $sql 原生查询语句
     *
     * @return array 所有结果行（关联数组）
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
     * 声明预加载关联（select 时按 foreignKey in (...) 批量加载，避免 N+1 查询）
     * 用法：with(['page', 'item2'])
     *
     * @param array|string $with 关联方法名（load() 支持的格式均可）
     *
     * @return $this
     */
    public function with($with)
    {
        $this->with_str = $with;
        return $this;
    }

    /**
     * 查询单条记录（内部按 limit(1)->select() 取第一条）
     * 传入主键值时按主键等值查询（主键取绑定模型的 $pk，未定义时为 id）
     *
     * @param mixed $id 主键值（可选）
     *
     * @return Model|null
     */
    public function find($id = NULL)
    {

        if (!empty($id)) {
            $this->where($this->pk, '=', $id);
        }
        $res = $this->limit(1)->select();
        $one = $res[0];
        return $one;
    }


    /**
     * 统计数量（聚合查询，可与 where 等条件链式组合）
     *
     * @param string $field 统计字段，默认 *
     *
     * @return mixed 无结果时返回 0
     */
    public function count($field = '*')
    {
        $one = $this->field(["count($field)" => 'count'])->find();
        return !empty($one) ? $one['count'] : 0;
    }

    /**
     * 最小值（聚合查询）
     *
     * @param string $field 字段名
     *
     * @return mixed 无结果时返回 0
     */
    public function min($field)
    {
        $one = $this->field(["min($field)" => 'min'])->find();
        return !empty($one) ? $one['min'] : 0;
    }

    /**
     * 最大值（聚合查询）
     *
     * @param string $field 字段名
     *
     * @return mixed 无结果时返回 0
     */
    public function max($field)
    {
        $one = $this->field(["max($field)" => 'max'])->find();
        return !empty($one) ? $one['max'] : 0;
    }

    /**
     * 求和（聚合查询）
     *
     * @param string $field 字段名
     *
     * @return mixed 无结果时返回 0
     */
    public function sum($field)
    {
        $one = $this->field(["sum($field)" => 'sum'])->find();
        return !empty($one) ? $one['sum'] : 0;
    }

    /**
     * 平均值（聚合查询）
     *
     * @param string $field 字段名
     *
     * @return mixed 无结果时返回 0
     */
    public function avg($field)
    {
        $one = $this->field(["avg($field)" => 'avg'])->find();
        return !empty($one) ? $one['avg'] : 0;
    }

    /**
     * 占位符绑定具体的变量值，并记录 SQL 日志
     * 数字下标按 PDO 位置参数（从 1 开始）绑定，命名占位符自动补 ":"；
     * 绑定后将占位符替换为值写入 $sqls（直观日志），原始 SQL 与参数写入 $source_sql。
     *
     * @param \PDOStatement $sttmnt 要绑定的PDOStatement对象
     * @param array $binds 参数，缺省使用 $this->bind
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
            //日志用：占位符 => 带引号的值
            $binds[$bind] = "'$value'";
        }
        //记录替换占位符后的 SQL，以及原始 SQL + 绑定参数
        static::$sqls[] = strtr($sttmnt->queryString, $binds);
        static::$source_sql[] = [
            $sttmnt->queryString,
            $this->bind,
        ];

        return $sttmnt;
    }

    /**
     * 获取最近一条已执行的 SQL（占位符已替换为值，仅用于调试查看）
     *
     * @return string
     */
    public function getLastSql()
    {

        return ArrayHelper::last(static::$sqls);
    }

    /**
     * 获取全部已执行 SQL（占位符已替换为值）
     *
     * @return array
     */
    public function getSqls()
    {

        return static::$sqls;
    }

    /**
     * 获取全部已执行的原始 SQL 与绑定参数，格式 [ [SQL, 参数数组], ... ]
     *
     * @return array
     */
    public function getSourceSql()
    {

        return static::$source_sql;
    }

    /**
     * 新增数据（字段与值全部参数绑定）
     *
     * @param array $data 待插入数据 [字段名 => 值]
     *
     * @return string 成功返回自增主键 ID
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
     * 用法：where('id', '=', 1)->update(['name' => 'new'])
     *      或 update(['name' => 'new'], ['id' => 1])
     *
     * @param array $data  待更新数据 [字段名 => 值]
     * @param array $where 可选条件（键值对或 where() 支持的数组格式）
     *
     * @return int 影响行数
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
     * 保存数据：按 $where 查询，记录存在则更新，不存在则插入
     * 用法：save(['name' => 'new'], ['id' => 1])
     *
     * @param array $data  保存的数据 [字段名 => 值]
     * @param array $where 判断记录是否存在的条件
     *
     * @return int|string 更新返回影响行数，插入返回自增主键 ID
     */
    public function save($data, $where)
    {
        $res = $this->where($where)->select();
        if (!empty($res) && FALSE == $res->isEmpty()) {
            return $this->update($data, $where);
        } else {
            return $this->insert($data);
        }
    }

    /**
     * 将数组转换成插入格式的 SQL 片段
     * 例：['name' => 'a', 'age' => 1] => ( `name`,`age` ) values ( :xx_name,:xx_age )
     * 同时把值注册到绑定参数表
     *
     * @param array $data 待插入数据
     *
     * @return string 插入片段（字段列表 + values 占位符列表）
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
     * 将数组转换成更新格式的 SQL 片段
     * 例：['name' => 'a'] => `name` = :xx_name
     * 同时把值注册到绑定参数表
     *
     * @param array $data 待更新数据
     *
     * @return string set 片段
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
     * 根据主键删除记录
     * 用法：delete(3) => delete from `表` where `id` = :id
     *
     * @param mixed $id 主键值
     *
     * @return int 影响行数
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
     * 闭包式事务：回调执行成功自动提交，抛出异常自动回滚并重新抛出
     * 用法：Item::transaction(function () { ... });
     *
     * @param callable $callback 事务内执行的操作
     *
     * @return void
     * @throws \Exception 回调抛出的异常
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
     * 手动开启事务（配合 commit / roolback 使用）
     */
    public function beginTransaction()
    {
        $this->pdo_object->beginTransaction();
    }

    /**
     * 手动提交事务
     */
    public function commit()
    {
        $this->pdo_object->commit();
    }

    /**
     * 手动回滚事务
     */
    public function rollback()
    {
        $this->pdo_object->rollBack();
    }

    /**
     * 手动回滚事务
     * 注：方法名 roolback 为历史拼写，为兼容旧调用保留，等价于 rollback()
     */
    public function roolback()
    {
        $this->rollback();
    }


}
