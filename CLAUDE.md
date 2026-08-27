# CLAUDE.md

本文件为 Claude Code 在此代码库中工作时提供指导。

## 项目概述

alex-lee/php-model 是一个轻量级的 PHP 数据库模型库，基于 PDO + MySQL，提供类似 ORM 的开发体验：

- **查询构造器**（Query）：where / whereOr / join / alias / field / order / group / limit 等
- **模型**（Model）：属性魔术读写、ArrayAccess、静态代理查询、表名按类名自动推导
- **数据集**（Collection）：链式数组操作、内存过滤排序、关联加载
- **模型关联**（relation）：hasOne（一对一）、hasMany（一对多，开发中）

## 环境与依赖

| 项 | 说明 |
|---|---|
| PHP 版本 | >= 7.0（**不得使用 PHP 7.1+ 特性**：`?Type` 可空类型声明、`void` 返回类型、常量可见性等） |
| 依赖 | alex-lee/php-helper ^1.0（StringHelper::endsWith、ArrayHelper::last，test.php 还用到 sdump()） |
| 数据库 | MySQL（PDO，utf8mb4 编码） |
| 自动加载 | PSR-4：`model\` => `src/` |

## 项目结构

```
src/
├── Model.php            模型基类：属性读写（魔术方法 / ArrayAccess）、表名推导、关联定义入口
├── Query.php            查询构造器：SQL 拼装、CURD、聚合查询、事务、SQL 日志
├── Collection.php       数据集：数组式操作（源自 ThinkPHP，保留其版权头）、关联加载 load()
├── PDOOBJ.php           PDO 连接单例：setConfig() 配置、instance() 懒加载取连接
└── relation/
    ├── Relation.php     关联基类：持有子模型实例，未知方法透传给其 Query
    ├── HasOne.php       一对一关联
    └── HasMany.php      一对多关联
test.php                 手工验证脚本（未使用 PHPUnit），连接本地 MySQL 直接跑通各功能
```

## 核心调用链

1. `PDLOBJ::setConfig(...)` 配置数据库连接（进程内一次）；
2. 业务模型继承 `model\Model`，可公开声明 `$table` / `$pk` 覆盖默认值（`$table` 为空时按类名推导：去掉 `Model` 后缀转小写）；
3. `Model::__callStatic` / `__call` 把未定义方法透传给 `Query` 实例：
   - 静态调用 `Item::where(...)` → `(new static())->getQueryInstance()` 后转发；
   - 实例调用 `$item->where(...)` → 转发到自身 `$queryInstance`；
4. `Query::select()` 组装并执行 SQL，每行结果经 `Model::resultSet()` 装入新模型对象，返回 `Collection`；`with()` 声明的关联由 `Collection::load()` 按 `foreignKey in (...)` 批量加载（避免 N+1）；
5. 关联定义即模型方法：`$this->hasOne(类, 外键, 本地键)` / `hasMany(...)`，返回对应 Relation 对象。

## 代码规范（保持与现有代码一致）

- 缩进 4 空格；类名大驼峰，方法小驼峰；
- 项目面向 PHP 7.0：不引入 `declare(strict_types=1)` 与新版本语法；类型声明风格与所在文件保持一致；
- 注释统一使用中文 phpdoc 风格（`@param` / `@return` / `@access` / `@todo`），复杂方法附用法示例；
- **SQL 安全**：所有条件值经 `getBindKey()` 生成唯一命名占位符后由 `formatBind()` 绑定，禁止把用户输入直接拼进 SQL；
- 错误处理现状：PDO 操作 `catch (\PDOException)` 后 `exit($exception->getMessage())`——保持现状，勿擅自改为抛异常（除非用户要求）；
- `Collection.php` 来自 ThinkPHP 且带版权头，修改时保留头部声明。

## 开发工作流

- 无单元测试框架，`test.php` 为手工验证脚本（依赖 php-helper 的 `sdump()` 输出，需先 `composer install` 生成 vendor/）；
- 运行前需设置数据库密码环境变量：PowerShell 执行 `$env:DB_PASSWORD='...'`（CMD 为 `set DB_PASSWORD=...`），主机名/库名/用户名写在脚本内；
- 提交信息简短（见 git log：order / save / where / 异常）；
- 改动后在本地 MySQL 环境跑一遍 `test.php` 验证。

## 已知问题（阅读源码时注意）

- `Query::roolback()` 为历史拼写的兼容别名，正确拼写是 `rollback()`，两者行为一致（改名会破坏兼容，故并存）；
- `test.php` 连接的主机名 `mysql` 为容器内服务名，本机运行需先确认可解析（或改为 127.0.0.1）。

### 已修复（2026-08-27）

- `Query::find($id)`：主键值已生效（原硬编码 `where(pk, '=', 1)`），未传参时行为不变；
- `Query::save()`：插入分支改为 `insert($data)`（原多传了 `$where` 参数）；
- `Query::_init()`：不再将 `$pk` 重置为 `'id'`——构造函数从模型读取 `$pk`，模型未定义时回退 `'id'`，`delete()` 对自定义主键生效；
- `Relation::relationResult()`：返回查询结果 `$ret`（原返回 `$this->query`）；
- `Model::getQueryInstance()`：表名推导改为「类名以 `Model` 结尾且长度大于 5 时才截去后缀」——原 `strpos` 判断"是否包含"，而截断永远发生在末尾，用 `strpos !== FALSE` 修复是错的（会把 `ModelItem` 错误截成 `Mode`），故采用后缀判断；
- `test.php`：硬编码数据库密码已移除，改从环境变量 `DB_PASSWORD` 读取。
