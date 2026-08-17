<?php

class AuditLogArchive extends \Phalcon\Mvc\Model
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
    public $entity_type;

    /**
     *
     * @var integer
     */
    public $entity_id;

    /**
     *
     * @var string
     */
    public $action;

    /**
     *
     * @var integer
     */
    public $actor_user_id;

    /**
     *
     * @var string
     */
    public $old_values;

    /**
     *
     * @var string
     */
    public $new_values;

    /**
     *
     * @var string
     */
    public $created_at;

    /**
     *
     * @var string
     */
    public $archived_at;

    /**
     * Initialize method for model.
     */
    public function initialize(): void
    {
        $this->setSource('audit_log_archive');
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     */
    public static function findFirst($parameters = null): \Phalcon\Mvc\ModelInterface|null
    {
        return parent::findFirst($parameters);
    }

}
