<?php
declare(strict_types=1);

/**
 * One row per module a licence key is tried for at check-in.
 */
class LicenseKeyModules extends \Phalcon\Mvc\Model
{
    public $id;
    public $license_key_id;
    public $module_key;
    public $created_at;

    public function initialize(): void
    {
        $this->setSource('license_key_modules');
        $this->keepSnapshots(true);
    }
}
