<?php


namespace model\relation;

class HasMany
{
    protected $parent;
    protected $model;
    protected $foreignKey;
    protected $localKey;
    protected $query;

    public function __construct(Model $parent, string $model, string $foreignKey, string $localKey)
    {
        $this->parent = $parent;
        $this->model = $model;
        $this->foreignKey = $foreignKey;
        $this->localKey = $localKey;
        $this->query = new $model();
    }
}
