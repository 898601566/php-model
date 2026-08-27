<?php

namespace model;

use PDO;
use PDOException;

/**
 * PDO 连接单例管理类
 * 使用前先通过 setConfig() 配置连接信息（进程内一次），
 * 之后所有 Query 实例共用 instance() 返回的同一 PDO 连接。
 */
class PDOOBJ
{

    /**
     * PDO 连接实例（懒加载）
     * @var PDO|null
     */
    static $pdo = NULL;

    /**
     * 数据库主机地址
     * @var string|null
     */
    static $host = NULL;

    /**
     * 数据库名
     * @var string|null
     */
    static $dbname = NULL;

    /**
     * 数据库用户名
     * @var string|null
     */
    static $username = NULL;

    /**
     * 数据库密码
     * @var string|null
     */
    static $password = NULL;

    /**
     * 配置数据库连接信息（需在首次查询前调用）
     *
     * @param string $host     主机地址，如 127.0.0.1
     * @param string $dbname   数据库名
     * @param string $username 用户名
     * @param string $password 密码
     *
     * @return void
     */
    public static function setConfig($host, $dbname,$username,$password)
    {
        static::$host = $host;
        static::$dbname = $dbname;
        static::$username = $username;
        static::$password = $password;
    }

    /**
     * 单例,获取pdo实例（不存在时按配置懒加载创建）
     * 默认 mysql 驱动 + utf8mb4 编码 + FETCH_ASSOC 关联数组取行模式
     * @return PDO
     */
    public static function instance()
    {
        if (!empty(static::$pdo)) {
            return static::$pdo;
        } else {
            try {
                $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', static::$host, static::$dbname);
                $option = [
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ];
                static::$pdo = new \PDO($dsn, static::$username, static::$password, $option);
                return static::$pdo;
            }
            catch (PDOException $pe) {
                exit($pe->getMessage());
            }
        }
    }
}
