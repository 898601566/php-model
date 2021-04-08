<?php

namespace model;

use PDO;
use PDOException;

class PDOOBJ
{

    static $pdo = NULL;
    static $host = NULL;
    static $dbname = NULL;
    static $username = NULL;
    static $password = NULL;

    /**
     *
     * @param $host
     * @param $dbname
     * @param $username
     * @param $password
     */
    public static function setConfig($host, $dbname,$username,$password)
    {
        static::$host = $host;
        static::$dbname = $dbname;
        static::$username = $username;
        static::$password = $password;
    }

    /**
     * 单例,获取pdo实例
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
