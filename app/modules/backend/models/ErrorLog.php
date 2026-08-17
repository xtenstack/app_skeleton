<?php

class ErrorLog extends \Phalcon\Mvc\Model
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
    public $exception_class;

    /**
     *
     * @var string
     */
    public $message;

    /**
     *
     * @var string
     */
    public $file;

    /**
     *
     * @var integer
     */
    public $line;

    /**
     *
     * @var string
     */
    public $trace;

    /**
     *
     * @var string
     */
    public $request_method;

    /**
     *
     * @var string
     */
    public $request_uri;

    /**
     *
     * @var integer
     */
    public $user_id;

    /**
     *
     * @var string
     */
    public $resolved_at;

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
        $this->setSource('error_log');
        $this->belongsTo('user_id', 'Users', 'id', ['alias' => 'Users']);
    }
}
