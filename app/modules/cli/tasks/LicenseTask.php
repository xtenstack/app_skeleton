<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Cli\Tasks;

use App_skeleton\LicenseManager;

/**
 * Usage: ./run license status
 *        ./run license checkin
 *        ./run license checkin <module-key>
 *
 * Licence state of the installed modules that need a licence key (see
 * App_skeleton\LicenseManager and docs/MODULE-SPEC.md, Licensing).
 *
 * 'status' reads the local record only. 'checkin' asks the licence
 * server now: with no argument, about every enabled module that needs a
 * key; with a module key, about that one module whether it is enabled or
 * not. A normal instance never needs to run this, because the first
 * signed-in request of each day does it. It is here for a first install,
 * for checking a key straight after entering it, and for an instance that
 * would rather schedule the check-in itself.
 */
class LicenseTask extends \Phalcon\Cli\Task
{
    private const STATE_LABELS = [
        LicenseManager::STATE_NOT_INSTALLED => 'not installed',
        LicenseManager::STATE_NOT_REQUIRED  => 'no licence key needed',
        LicenseManager::STATE_VALID         => 'licensed',
        LicenseManager::STATE_GRACE         => 'licensed, in grace period',
        LicenseManager::STATE_EXPIRED       => 'NOT LICENSED, grace period over',
        LicenseManager::STATE_UNVALIDATED   => 'NOT LICENSED, key never validated',
        LicenseManager::STATE_NO_KEY        => 'NOT LICENSED, no key stored',
    ];

    public function mainAction(): void
    {
        echo 'Usage: ./run license status | checkin [<module-key>]' . PHP_EOL;
    }

    public function statusAction(): void
    {
        $this->report($this->licenseManager->entitlements());
    }

    public function checkinAction($moduleKey = null): void
    {
        echo 'Licence server: ' . $this->licenseManager->serverUrl() . PHP_EOL;

        if ($moduleKey === null) {
            $this->report($this->licenseManager->checkInAll());

            return;
        }

        $moduleKey = (string) $moduleKey;

        $this->report([$moduleKey => $this->licenseManager->checkIn($moduleKey)]);
    }

    /**
     * @param array<string, array<string, mixed>> $entitlements
     */
    private function report(array $entitlements): void
    {
        if (!$entitlements) {
            echo 'No installed module needs a licence key.' . PHP_EOL;

            return;
        }

        foreach ($entitlements as $moduleKey => $entitlement) {
            echo "  {$moduleKey}: " . self::STATE_LABELS[$entitlement['state']] . PHP_EOL;

            if ($entitlement['lastSuccessfulCheckinAt'] !== null) {
                echo "      last successful check-in {$entitlement['lastSuccessfulCheckinAt']}";
                echo $entitlement['graceDaysLeft'] !== null ? ", {$entitlement['graceDaysLeft']} day(s) of grace left" : '';
                echo PHP_EOL;
            }

            if ($entitlement['lastAttemptAt'] !== null && $entitlement['lastResult'] !== 'valid') {
                $outcome = $entitlement['lastResult'] === 'rejected'
                    ? 'the licence server did not accept the key for this module'
                    : 'the licence server could not be reached';

                echo "      last attempt {$entitlement['lastAttemptAt']}: {$outcome}" . PHP_EOL;
            }
        }
    }
}
