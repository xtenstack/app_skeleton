<?php
declare(strict_types=1);

/**
 * The last known licence answer for one module, cached locally so nothing
 * waits on the licence server at page load. Written only by
 * App_skeleton\LicenseManager.
 */
class LicenseEntitlements extends \Phalcon\Mvc\Model
{
    public $id;
    public $module_key;
    public $state;
    public $license_key_id;
    public $last_successful_checkin_at;
    public $last_attempt_at;
    public $last_result;
    public $expires_on;
    public $created_at;
    public $updated_at;

    public function initialize(): void
    {
        $this->setSource('license_entitlements');
        $this->keepSnapshots(true);
        // Only the columns a save actually changed are written, so a
        // failed check-in saved from a row loaded a moment earlier cannot
        // put an older last_successful_checkin_at back over a newer one.
        $this->useDynamicUpdate(true);
    }

    public function beforeSave(): void
    {
        $this->updated_at = date('Y-m-d H:i:s');
    }
}
