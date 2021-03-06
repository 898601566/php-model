<?php

use model\Model;

require_once __DIR__ . "/vendor/autoload.php";
error_reporting(E_ALL);
ini_set('display_errors', 'ON');
$a = [
    [
        'aaa' => 1,
        'bbb' => [
            ['a' => 'a1', 'b' => 1],
            ['a' => 'a2', 'b' => 2],
            ['a' => 'a3', 'b' => 3],
        ],
    ],
    [
        'aaa' => 2,
        'bbb' => [
            ['a' => 'a11', 'b' => 21],
            ['a' => 'a12', 'b' => 22],
            ['a' => 'a13', 'b' => 23],
        ],
    ],
    [
        'aaa' => 3,
        'bbb' => [
            ['a' => 'a21', 'b' => 221],
            ['a' => 'a22', 'b' => 222],
            ['a' => 'a23', 'b' => 223],
        ],
    ],
];
$ret = \model\PDOOBJ::setConfig('mysql', 'fastphp', 'root', 'Ro225851');


class Page extends Model
{
    /**
     * 自定义当前模型操作的数据库表名称，
     * 如果不指定，默认为类名称的小写字符串，
     * 这里就是 item 表
     * @var string
     */
    public $table = 'page';
    public $pk = 'page_id';
}

/**
 * 用户Model
 */
class Item extends Model
{
    /**
     * 自定义当前模型操作的数据库表名称，
     * 如果不指定，默认为类名称的小写字符串，
     * 这里就是 item 表
     * @var string
     */
//    public $table = 'item';
    /**
     * 搜索功能，因为Sql父类里面没有现成的like搜索，
     * 所以需要自己写SQL语句，对数据库的操作应该都放
     * 在Model里面，然后提供给Controller直接调用
     *
     * @param $title string 查询的关键词
     *
     * @return array 返回的数据
     */
    public function __construct()
    {
        parent::__construct();
    }

    public function search($keyword)
    {
        $res = static::where('item_name', "like", "%{$keyword}%")->select();
        return $res;
    }

    public function item2()
    {
        return $this->hasOne(Item::class, 'id', 'id');
    }

    public function page()
    {
        return $this->hasMany(Page::class, 'type', 'id')
                    ->where('page_id', '<=', 100);
    }

}

$item = new Item();
$res2 = null;
$item->transaction(function () use ($item,&$res2) {
    $res = $item->where('id', '=', 1)
                ->select();
    $res->load([
        'page' => function ($query) {
            /**
             * var $query \model\Query
             */
            $query->where('page_id', '=', 3);
        }, 'item2',
    ]);
    $res = $item->where('id', '=', '2')->update(['item_name' => '66666']);
    $res2 = $item->where('id', '=', 2)
                 ->with(['page', 'item2'])
                 ->select();
});
//$res2->load(['page', 'item2']);
sdump($res2->toArray());
//sdump((new Item())->find(1));
//sdump($res->toArray(), $res2->toArray(),
//    $item->getSourceSql());

