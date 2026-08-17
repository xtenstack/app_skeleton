<?php

use App_skeleton\Models\SoftDeletes;

class Items extends Phalcon\Mvc\Model
{
    use SoftDeletes;

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
    public $title;

    /**
     *
     * @var string
     */
    public $description;

    /**
     *
     * @var string
     */
    public $status;

    /**
     *
     * @var string
     */
    public $created_at;

    /**
     *
     * @var string
     */
    public $deleted_at;

    /**
     * Initialize method for model.
     */
    public function initialize(): void
    {
        $this->setSource('items');
        $this->keepSnapshots(true);
        $this->belongsTo('user_id', 'Users', 'id', ['alias' => 'Users']);
    }

}
