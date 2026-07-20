<?php

class AuditLog extends \Phalcon\Mvc\Model
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
     * Soft reference to another audit_log.id — set on a 'reversal' row to
     * point at the entry it undid, or found on an original row (via a
     * lookup, not a relation) once something has reversed it.
     *
     * @var integer
     */
    public $reversed_audit_log_id;

    /**
     * Initialize method for model.
     */
    public function initialize()
    {
        $this->setSource("audit_log");
        $this->belongsTo('actor_user_id', 'Users', 'id', ['alias' => 'Users']);
    }

}
