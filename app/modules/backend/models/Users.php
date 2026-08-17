<?php

use App_skeleton\Models\SoftDeletes;
use Phalcon\Filter\Validation;
use Phalcon\Filter\Validation\Validator\Email as EmailValidator;

/**
 * @method static Users|null findFirstById(int $id)
 */
class Users extends Phalcon\Mvc\Model
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
    public $role_id;

    /**
     *
     * @var string
     */
    public $email;

    /**
     *
     * @var string
     */
    public $password_hash;

    /**
     *
     * @var string
     */
    public $first_name;

    /**
     *
     * @var string
     */
    public $last_name;

    /**
     *
     * @var integer
     */
    public $is_active;

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
     *
     * @var string
     */
    public $email_verified_at;

    /**
     *
     * @var string
     */
    public $verification_token;

    /**
     *
     * @var string
     */
    public $verification_token_expires_at;

    /**
     *
     * @var string
     */
    public $password_reset_token;

    /**
     *
     * @var string
     */
    public $password_reset_expires_at;

    /**
     * Validations and business logic
     *
     * @return boolean
     */
    public function validation()
    {
        $validator = new Validation();

        $validator->add(
            'email',
            new EmailValidator(
                [
                    'model'   => $this,
                    'message' => 'Please enter a correct email address',
                ]
            )
        );

        return $this->validate($validator);
    }

    /**
     * Initialize method for model.
     */
    public function initialize(): void
    {
        $this->setSource('users');
        $this->keepSnapshots(true);
        $this->hasMany('id', 'Items', 'user_id', ['alias' => 'Items']);
        $this->belongsTo('role_id', 'Roles', 'id', ['alias' => 'Roles']);
        $this->hasOne('id', 'UserProfiles', 'user_id', ['alias' => 'Profile']);
        $this->hasMany('id', 'UserSettings', 'user_id', ['alias' => 'Settings']);
        $this->hasMany('id', 'ApiKeys', 'user_id', ['alias' => 'ApiKeys']);
    }

}
