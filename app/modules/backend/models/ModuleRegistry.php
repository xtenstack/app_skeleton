<?php

class ModuleRegistry extends \Phalcon\Mvc\Model
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
    public $module_key;

    /**
     *
     * @var string
     */
    public $code;

    /**
     *
     * @var string
     */
    public $tier;

    /**
     *
     * @var string
     */
    public $package_name;

    /**
     *
     * @var string
     */
    public $version;

    /**
     *
     * @var boolean
     */
    public $enabled;

    /**
     *
     * @var string
     */
    public $discovered_at;

    /**
     *
     * @var string
     */
    public $updated_at;

    /**
     * Initialize method for model.
     */
    public function initialize()
    {
        $this->setSource("module_registry");
        $this->keepSnapshots(true);
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return ModuleRegistry[]|ModuleRegistry|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return ModuleRegistry|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
