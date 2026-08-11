<?php

class Roles extends Phalcon\Mvc\Model
{
    /**
     *
     * @var integer
     */
    public $id;

    /**
     *
     * @var string
     */
    public $name;

    /**
     *
     * @var string
     */
    public $created_at;

    /**
     * Initialize method for model.
     */
    public function initialize()
    {
        $this->setSource('roles');
        $this->keepSnapshots(true);
        $this->hasMany('id', 'Users', 'role_id', ['alias' => 'Users']);
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return Roles[]|Roles|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return Roles|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

    /**
     * Resolves role ids by name, for code that needs to check/filter by
     * role without hardcoding ids. Only role 1 (admin) is guaranteed by
     * 001_initial_schema.sql's literal insert order — anything seeded
     * later via SeedTask (e.g. 'operator', 'agent') has no such guarantee
     * and must be looked up by name instead.
     *
     * @return int[]
     */
    public static function idsByNames(array $names): array
    {
        if (!$names) {
            return [];
        }

        $ids = [];

        foreach (self::find(['conditions' => 'name IN ({names:array})', 'bind' => ['names' => $names]]) as $role) {
            $ids[] = (int) $role->id;
        }

        return $ids;
    }

}
