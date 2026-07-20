<?php

class UserProfiles extends \Phalcon\Mvc\Model
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
    public $avatar_path;

    /**
     *
     * @var string
     */
    public $phone;

    /**
     *
     * @var string
     */
    public $bio;

    /**
     *
     * @var string
     */
    public $timezone;

    /**
     *
     * @var string
     */
    public $locale;

    /**
     *
     * @var string
     */
    public $created_at;

    /**
     *
     * @var string
     */
    public $updated_at;

    /**
     * Admin-only — see the migration comment. Never set from the user's own
     * self-service Account page.
     *
     * @var string
     */
    public $age_verified_at;

    /**
     * Initialize method for model.
     */
    public function initialize()
    {
        $this->setSource("user_profiles");
        $this->keepSnapshots(true);
        $this->belongsTo('user_id', 'Users', 'id', ['alias' => 'Users']);
    }

}
