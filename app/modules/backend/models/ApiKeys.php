<?php

/**
 * @method static ApiKeys|null findFirstById(int $id)
 */
class ApiKeys extends \Phalcon\Mvc\Model
{
    /**
     *
     * @var integer
     */
    public $id;

    /**
     *
     * @var integer
     */
    public $user_id;

    /**
     *
     * @var string
     */
    public $name;

    /**
     *
     * @var string
     */
    public $token_hash;

    /**
     *
     * @var string
     */
    public $token_prefix;

    /**
     *
     * @var string
     */
    public $last_used_at;

    /**
     *
     * @var string
     */
    public $revoked_at;

    /**
     * When the key stops working, or null for none. Set by the provisioning
     * API (a key issued with expires_in_days, or the old key during a
     * rotation's grace period).
     *
     * @var string|null
     */
    public $expires_at;

    /**
     *
     * @var string
     */
    public $created_at;

    /**
     * Initialize method for model.
     */
    public function initialize(): void
    {
        $this->setSource('api_keys');
        $this->keepSnapshots(true);
        $this->belongsTo('user_id', 'Users', 'id', ['alias' => 'Users']);
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
