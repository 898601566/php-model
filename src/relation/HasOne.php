<?php


namespace model\relation;


use model\Model;

class HasOne extends Relation
{
    public $parent;
    public $model;
    public $foreignKey;
    public $localKey;
    public $query;

    public function __construct(Model $parent, string $model, string $foreignKey, string $localKey)
    {
        parent::__construct($parent, $model, $foreignKey, $localKey);
    }

}
