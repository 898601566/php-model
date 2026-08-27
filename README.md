# php-model

轻量级 PHP 数据库模型库：查询构造器 + 模型 + 数据集（Collection）+ 模型关联，基于 PDO / MySQL。

## 特性

- **查询构造器**：`where` / `whereOr`（支持三参数、数组、`in`、闭包嵌套分组）、`join`、`alias`、`field`、`order`、`group`、`limit`
- **CURD**：`select` / `insert` / `update` / `save`（存在则更新否则插入）/ `delete`（按主键）
- **聚合查询**：`count` / `min` / `max` / `sum` / `avg`
- **事务**：`transaction(callable)` 闭包式自动提交回滚，或 `beginTransaction` / `commit` / `rollback` 手动式
- **模型**：属性魔术读写、`ArrayAccess`、按类名自动推导表名（可用 `$table` / `$pk` 覆盖）
- **数据集 Collection**：`map` / `filter` / `each` / `sort` / `where` / `column` 等链式数组操作，可直接 `json_encode`
- **模型关联**：`hasOne` 一对一、`hasMany` 一对多（开发中），支持 `with()` 预加载与 `load()` 延迟加载，批量查询避免 N+1
- **SQL 调试**：`getLastSql()` / `getSqls()` / `getSourceSql()` 查看已执行的 SQL 与绑定参数
- **安全**：所有条件值通过 PDO 预处理参数绑定，防止 SQL 注入

## 环境要求

- PHP >= 7.0
- PDO_MySQL 扩展
- [alex-lee/php-helper](https://packagist.org/) ^1.0

## 安装

```bash
composer require alex-lee/php-model
```

## 快速开始

### 1. 配置数据库连接

```php
\model\PDOBJ::setConfig('127.0.0.1', 'database', 'username', 'password');
```

### 2. 定义模型

```php
use model\Model;

class Item extends Model
{
    // 可选：显式指定表名与主键；不指定则按类名推导（去掉 Model 后缀并转小写）
    public $table = 'item';
    public $pk    = 'id';

    // 一对一：Item.id = Item2.id
    public function item2()
    {
        return $this->hasOne(Item2::class, 'id', 'id');
    }

    // 一对多：Page.type 指向 Item.id，定义时还可继续追加查询条件
    public function page()
    {
        return $this->hasMany(Page::class, 'type', 'id')
                    ->where('page_id', '<=', 100);
    }
}
```

### 3. 查询

```php
// where 三参数
$res = Item::where('id', '=', 1)->select();

// where 数组
$res = Item::where([
    ['id', '>', 3],
    ['status', '=', 1],
])->select();

// 闭包嵌套分组 + OR
$res = Item::where(function ($query) {
    $query->where('id', 'in', [1, 2, 3])
          ->whereOr('status', '=', 2);
})->select();

// 字段 / 排序 / 分页
$res = Item::field(['id', 'item_name'])
           ->order('id DESC')
           ->limit(1, 20)   // 第 1 页，每页 20 条
           ->select();

// 单条与聚合
$one  = Item::where('id', '=', 1)->find();
$count = Item::where('status', '=', 1)->count();

// 原生 SQL（只读查询）
$rows = Item::sql('select * from item limit 10');
```

### 4. 新增 / 更新 / 保存 / 删除

```php
// 新增，返回自增主键
$id = Item::insert(['item_name' => 'test']);

// 更新，返回影响行数
$affected = Item::where('id', '=', 2)->update(['item_name' => 'new']);

// 保存：按 $where 查询，存在则更新，不存在则插入
Item::save(['item_name' => 'new'], ['id' => 2]);

// 按主键删除
Item::delete(3);
```

### 5. 关联

```php
// with 预加载：查询时一并取出关联数据
$res = Item::where('id', '=', 2)->with(['page', 'item2'])->select();
sdump($res->toArray());   // 关联数据已合入 page / item2 键

// load 延迟加载：查询后按需加载，支持闭包限定关联查询条件
$res = Item::where('id', '=', 1)->select();
$res->load([
    'page' => function ($query) {
        $query->where('page_id', '=', 3);
    },
    'item2',
]);
```

### 6. 事务

```php
// 闭包式：异常自动回滚并抛出
Item::transaction(function () {
    Item::where('id', '=', 2)->update(['item_name' => '666']);
});

// 手动式
Item::beginTransaction();
try {
    Item::where('id', '=', 2)->update(['item_name' => '666']);
    Item::commit();
} catch (\Exception $e) {
    Item::rollback();
}
```

## 目录结构

```
src/
├── Model.php            模型基类：属性读写、表名推导、关联定义入口
├── Query.php            查询构造器：SQL 拼装、CURD、聚合查询、事务、SQL 日志
├── Collection.php       数据集：链式数组操作、关联加载
├── PDOOBJ.php           PDO 连接单例
└── relation/            关联（Relation 基类、HasOne、HasMany）
```

## 开发调试

项目暂未使用 PHPUnit，`test.php` 为手工验证脚本。运行前先安装依赖并设置数据库密码环境变量：

```bash
composer install

# PowerShell
$env:DB_PASSWORD = '数据库密码'
php test.php

# CMD / Linux
set DB_PASSWORD=数据库密码   # Linux: export DB_PASSWORD=数据库密码
php test.php
```

## License

MIT
