<?php
declare(strict_types=1);

namespace App_skeleton;

use Phalcon\Di\Injectable;

/**
 * Client side of module licensing (docs/MODULE-SPEC.md, Licensing): which
 * installed modules need a licence key, what the licence server last said
 * about each, and the check-in that asks it again.
 *
 * Reads are local. isLicensed() and entitlement() only ever look at
 * license_entitlements, so a page load never waits on the network; the
 * network is touched by checkIn()/checkInAll() alone. Nothing here throws
 * into a request: a check-in that fails, or cannot reach the server,
 * records the attempt and leaves the last successful check-in exactly as
 * it was. That timestamp is what the 120-day grace period counts from.
 *
 * Nothing is unloaded or disabled by this class. A module asks
 * isLicensed() and decides for itself what to restrict; the engine's own
 * part is the admin notice and the past-grace modal (adminNotice()).
 */
class LicenseManager extends Injectable
{
    public const GRACE_DAYS = 120;

    /** A licence that ends within this many days is pointed out to admins. */
    public const EXPIRY_NOTICE_DAYS = 30;

    /** No module.json for that key on this instance. */
    public const STATE_NOT_INSTALLED = 'not_installed';

    /** The module declares no licence, or "keyRequired": false. */
    public const STATE_NOT_REQUIRED = 'not_required';

    /** The most recent check-in succeeded, within the last GRACE_DAYS. */
    public const STATE_VALID = 'valid';

    /** A check-in has succeeded within GRACE_DAYS, but the most recent one did not. */
    public const STATE_GRACE = 'grace';

    /** The last successful check-in is more than GRACE_DAYS old. */
    public const STATE_EXPIRED = 'expired';

    /** A key is stored for the module but no check-in has ever succeeded. */
    public const STATE_UNVALIDATED = 'unvalidated';

    /** The module needs a key and none is stored for it. */
    public const STATE_NO_KEY = 'no_key';

    public const LICENSED_STATES = [self::STATE_NOT_REQUIRED, self::STATE_VALID, self::STATE_GRACE];

    /**
     * Fired on eventsBus after a module's saved state changes, with
     * ['module', 'state', 'previous', 'licensed', 'licenseKeyId'].
     */
    public const EVENT_CHANGED = 'license:changed';

    /** settings row holding the date (Y-m-d) of the last usage-gated check-in run. */
    private const DAILY_CLAIM_SETTING = 'license_checkin_attempted_on';

    private ?LicenseCheckinClient $client = null;

    private ?\Closure $clock = null;

    /** @var array<string, \LicenseEntitlements>|null */
    private ?array $rows = null;

    /** @var array<string, int[]>|null module key => ids of the keys tried for it */
    private ?array $keyIdsByModule = null;

    public function isLicensed(string $moduleKey): bool
    {
        return $this->entitlement($moduleKey)['licensed'];
    }

    /**
     * @return array{
     *     module: string,
     *     state: string,
     *     licensed: bool,
     *     required: bool,
     *     sharesKeyWith: ?string,
     *     hasKey: bool,
     *     licenseKeyId: ?int,
     *     lastSuccessfulCheckinAt: ?string,
     *     lastAttemptAt: ?string,
     *     lastResult: ?string,
     *     graceDaysLeft: ?int,
     *     expiresOn: ?string,
     *     expiresInDays: ?int
     * } licenseKeyId is the key that last validated the module;
     *   graceDaysLeft is null unless the state is valid or grace.
     *   expiresOn is the last day covered (Y-m-d) as the licence server
     *   last gave it, null when it gave none; expiresInDays counts from
     *   today (0 on the last day, negative once past).
     */
    public function entitlement(string $moduleKey): array
    {
        $declaration = $this->declaration($moduleKey);

        $entitlement = [
            'module'                  => $moduleKey,
            'state'                   => self::STATE_NOT_REQUIRED,
            'licensed'                => true,
            'required'                => false,
            'sharesKeyWith'           => null,
            'hasKey'                  => false,
            'licenseKeyId'            => null,
            'lastSuccessfulCheckinAt' => null,
            'lastAttemptAt'           => null,
            'lastResult'              => null,
            'graceDaysLeft'           => null,
            'expiresOn'               => null,
            'expiresInDays'           => null,
        ];

        if ($declaration === null) {
            return ['state' => self::STATE_NOT_INSTALLED, 'licensed' => false] + $entitlement;
        }

        if (!$declaration['required']) {
            return $entitlement;
        }

        $row     = $this->rows()[$moduleKey] ?? null;
        $hasKey  = (bool) $this->candidateKeyIds($moduleKey);
        $success = $row?->last_successful_checkin_at ? (string) $row->last_successful_checkin_at : null;
        $state   = $this->state($hasKey, $success, $row?->last_result ? (string) $row->last_result : null);
        $expires = $row?->expires_on ? substr((string) $row->expires_on, 0, 10) : null;

        return [
            'state'                   => $state,
            'licensed'                => in_array($state, self::LICENSED_STATES, true),
            'required'                => true,
            'sharesKeyWith'           => $declaration['sharesKeyWith'],
            'hasKey'                  => $hasKey,
            'licenseKeyId'            => $row?->license_key_id !== null ? (int) $row->license_key_id : null,
            'lastSuccessfulCheckinAt' => $success,
            'lastAttemptAt'           => $row?->last_attempt_at ? (string) $row->last_attempt_at : null,
            'lastResult'              => $row?->last_result ? (string) $row->last_result : null,
            'graceDaysLeft'           => in_array($state, [self::STATE_VALID, self::STATE_GRACE], true)
                ? $this->graceDaysLeft((string) $success)
                : null,
            'expiresOn'               => $expires,
            'expiresInDays'           => $expires !== null
                ? (int) round((strtotime($expires . ' 00:00:00 UTC') - strtotime(gmdate('Y-m-d', $this->now()) . ' 00:00:00 UTC')) / 86400)
                : null,
        ] + $entitlement;
    }

    /**
     * Entitlements of every installed module that needs a key, keyed by
     * module key, in discovery order.
     *
     * @return array<string, array<string, mixed>>
     */
    public function entitlements(): array
    {
        $entitlements = [];

        foreach ($this->modulesNeedingAKey() as $moduleKey) {
            $entitlements[$moduleKey] = $this->entitlement($moduleKey);
        }

        return $entitlements;
    }

    /**
     * Installed modules whose module.json requires a licence key, either
     * outright ("keyRequired": true) or through "sharesKeyWith".
     *
     * @return string[]
     */
    public function modulesNeedingAKey(): array
    {
        $keys = [];

        foreach ($this->moduleManager->discover() as $manifest) {
            if ($this->declaration((string) $manifest['key'])['required'] ?? false) {
                $keys[] = (string) $manifest['key'];
            }
        }

        return $keys;
    }

    /**
     * Asks the licence server about one module now, trying each key
     * stored for it until one is accepted. Blocks on the network (bounded
     * by LicenseCheckinClient's timeouts), so it belongs behind an admin
     * action or the CLI, not in a page load.
     *
     * @return array<string, mixed> the module's entitlement afterwards
     */
    public function checkIn(string $moduleKey): array
    {
        return $this->checkInModules([$moduleKey])[$moduleKey];
    }

    /**
     * checkIn() for several modules, enabled or not. Once the server
     * proves unreachable the remaining modules are recorded as
     * unreachable too rather than each waiting out its own timeout.
     *
     * @param string[] $moduleKeys
     *
     * @return array<string, array<string, mixed>> entitlements afterwards, by module key
     */
    public function checkInModules(array $moduleKeys): array
    {
        $unreachable  = false;
        $entitlements = [];

        foreach ($moduleKeys as $moduleKey) {
            try {
                $unreachable = $this->attempt($moduleKey, $unreachable) === LicenseCheckinClient::UNREACHABLE || $unreachable;
            } catch (\Throwable $e) {
                error_log("LicenseManager: check-in for {$moduleKey} failed: " . $e->getMessage());
            }
        }

        foreach ($moduleKeys as $moduleKey) {
            $entitlements[$moduleKey] = $this->entitlement($moduleKey);
        }

        return $entitlements;
    }

    /**
     * Checks in every enabled module that needs a key, and brings the
     * saved state of the rest up to date (a grace period can run out
     * with no check-in involved).
     *
     * @return array<string, array<string, mixed>> entitlements of every module that needs a key
     */
    public function checkInAll(): array
    {
        $this->checkInModules(array_values(array_intersect($this->modulesNeedingAKey(), $this->moduleManager->loadableModuleKeys())));
        $this->syncStates();

        return $this->entitlements();
    }

    /**
     * Installed modules the stored key $keyId would be sent for: those it
     * is assigned to, and those sharing a key with them.
     *
     * @return string[]
     */
    public function modulesTriedWithKey(int $keyId): array
    {
        return array_values(array_filter(
            $this->modulesNeedingAKey(),
            fn (string $moduleKey): bool => in_array($keyId, $this->candidateKeyIds($moduleKey), true)
        ));
    }

    /**
     * The usage-gated check-in: runs checkInAll() at most once per
     * calendar day, off the first authenticated request of that day
     * (signed in, or carrying an API key), and only after the response
     * has gone out. An instance nobody is using makes no calls at all,
     * and one with no paid module installed returns at the first test,
     * having read nothing but module.json. Registered as a shutdown
     * function by bootstrap_web.php.
     */
    public function checkInAfterResponse(): void
    {
        try {
            if (!$this->modulesNeedingAKey() || !$this->requestIsAuthenticated() || !$this->dailyCheckInDue()) {
                return;
            }

            // Release the session lock and the client before the network
            // call: neither this user's next request nor the page they are
            // waiting for should sit behind a slow licence server.
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            ignore_user_abort(true);

            if (PHP_SAPI !== 'cli') {
                $this->finishResponse();
            }

            // From here on the work is the instance's own, not the
            // caller's: what it changes is audited with no actor.
            $this->getDI()->getShared('currentPrincipal')->clear();

            $this->checkInAll();
        } catch (\Throwable $e) {
            error_log('LicenseManager: usage-gated check-in failed: ' . $e->getMessage());
        }
    }

    /**
     * Ends the response where the SAPI can (PHP-FPM, LiteSpeed). Where it
     * cannot, pushes the page out so the browser has it, and lets the
     * connection close when the check-in is done.
     */
    private function finishResponse(): void
    {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();

            return;
        }

        if (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();

            return;
        }

        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        flush();
    }

    /**
     * The same daily check-in for the cron runner: an instance that only
     * ever runs scheduled jobs is in use too (REQ-234), and would
     * otherwise never check in and run out of grace. Shares the one-per-
     * day claim with checkInAfterResponse(), so whichever comes first
     * that day does it.
     */
    public function checkInIfDue(): void
    {
        try {
            if ($this->dailyCheckInDue()) {
                $this->checkInAll();
            }
        } catch (\Throwable $e) {
            error_log('LicenseManager: scheduled check-in failed: ' . $e->getMessage());
        }
    }

    /**
     * True for one caller per calendar day, who then owes the day's
     * check-in: the day is marked as taken before anything is sent, so a
     * check-in that fails is not retried until tomorrow. Always false on
     * an instance with no paid module installed, which is decided from
     * module.json alone, without a query.
     */
    public function dailyCheckInDue(): bool
    {
        if (!$this->modulesNeedingAKey()) {
            return false;
        }

        $today = date('Y-m-d', $this->now());

        return $this->settings->get(self::DAILY_CLAIM_SETTING) !== $today && $this->claimDay($today);
    }

    /**
     * What the admin layout shows: enabled modules in their grace period
     * (a notice) and enabled modules that are not licensed at all (the
     * modal). Entitlements, by module key.
     *
     * Also 'expiring': licensed modules whose licence ends within
     * EXPIRY_NOTICE_DAYS.
     *
     * @return array{grace: array<string, array<string, mixed>>, unlicensed: array<string, array<string, mixed>>, expiring: array<string, array<string, mixed>>}
     */
    public function adminNotice(): array
    {
        $notice = ['grace' => [], 'unlicensed' => [], 'expiring' => []];

        try {
            $needingAKey = $this->modulesNeedingAKey();

            if (!$needingAKey) {
                return $notice;
            }

            foreach (array_intersect($needingAKey, $this->moduleManager->loadableModuleKeys()) as $moduleKey) {
                $entitlement = $this->entitlement($moduleKey);

                if ($entitlement['state'] === self::STATE_GRACE) {
                    $notice['grace'][$moduleKey] = $entitlement;
                } elseif (!$entitlement['licensed']) {
                    $notice['unlicensed'][$moduleKey] = $entitlement;
                }

                if ($entitlement['licensed'] && $entitlement['expiresInDays'] !== null && $entitlement['expiresInDays'] <= self::EXPIRY_NOTICE_DAYS) {
                    $notice['expiring'][$moduleKey] = $entitlement;
                }
            }
        } catch (\Throwable $e) {
            error_log('LicenseManager: could not work out the admin licence notice: ' . $e->getMessage());
        }

        return $notice;
    }

    /**
     * Stores a new key, encrypted, to be tried for $moduleKeys. Does not
     * check in; the caller decides when to.
     *
     * @param string[] $moduleKeys
     *
     * @throws \InvalidArgumentException with a message fit to show the admin
     */
    public function addKey(string $key, ?string $label, array $moduleKeys): \LicenseKeys
    {
        $key = trim($key);

        $this->assertUsableKey($key, null);

        $licenseKey        = new \LicenseKeys();
        $licenseKey->label = $this->cleanLabel($label);
        $licenseKey->setKey($key);

        if (!$licenseKey->save()) {
            throw new \InvalidArgumentException('The licence key could not be saved: ' . implode('; ', $licenseKey->getMessages()));
        }

        $this->assign($licenseKey, $moduleKeys);
        $this->syncStates();

        return $licenseKey;
    }

    /**
     * Changes a stored key's label and the modules it is tried for and,
     * when $key is not empty, the key itself. A module keeps its check-in
     * history across a replacement; the new key is simply what gets sent
     * from now on.
     *
     * @param string[] $moduleKeys
     *
     * @throws \InvalidArgumentException with a message fit to show the admin
     */
    public function updateKey(int $id, ?string $key, ?string $label, array $moduleKeys): \LicenseKeys
    {
        $licenseKey = \LicenseKeys::findFirst(['conditions' => 'id = :id:', 'bind' => ['id' => $id]]);

        if (!$licenseKey instanceof \LicenseKeys) {
            throw new \InvalidArgumentException('That licence key was not found.');
        }

        $key = trim((string) $key);

        if ($key !== '') {
            $this->assertUsableKey($key, $id);
            $licenseKey->setKey($key);
        }

        $licenseKey->label = $this->cleanLabel($label);

        if (!$licenseKey->save()) {
            throw new \InvalidArgumentException('The licence key could not be saved: ' . implode('; ', $licenseKey->getMessages()));
        }

        $this->assign($licenseKey, $moduleKeys);
        $this->syncStates();

        return $licenseKey;
    }

    /**
     * Removes a key from this instance. Any module that key was the one
     * to validate loses that check-in history with it and is unlicensed
     * until another key validates it.
     */
    public function removeKey(int $id): bool
    {
        $licenseKey = \LicenseKeys::findFirst(['conditions' => 'id = :id:', 'bind' => ['id' => $id]]);

        if (!$licenseKey instanceof \LicenseKeys) {
            return false;
        }

        foreach (\LicenseKeyModules::find(['conditions' => 'license_key_id = :id:', 'bind' => ['id' => $id]]) as $assignment) {
            $assignment->delete();
        }

        $removed = $licenseKey->remove();

        $this->syncStates();

        return $removed;
    }

    /**
     * Module keys a stored key is tried for, as the admin assigned them.
     *
     * @return array<int, string[]> licence key id => module keys
     */
    public function assignments(): array
    {
        $assignments = [];

        foreach ($this->keyIdsByModule() as $moduleKey => $keyIds) {
            foreach ($keyIds as $keyId) {
                $assignments[$keyId][] = $moduleKey;
            }
        }

        return $assignments;
    }

    /**
     * Brings license_entitlements in line with the keys now stored and
     * the time now elapsed, for every installed module that needs a key,
     * firing 'license:changed' for each state that moved. No network.
     */
    public function syncStates(): void
    {
        $this->rows           = null;
        $this->keyIdsByModule = null;

        foreach ($this->modulesNeedingAKey() as $moduleKey) {
            try {
                $this->save($moduleKey);
            } catch (\Throwable $e) {
                error_log("LicenseManager: could not save the licence state of {$moduleKey}: " . $e->getMessage());
            }
        }
    }

    public function serverUrl(): string
    {
        return $this->client()->serverUrl();
    }

    public function useClient(LicenseCheckinClient $client): void
    {
        $this->client = $client;
    }

    /**
     * @param \Closure(): int $clock returns the current Unix time
     */
    public function useClock(\Closure $clock): void
    {
        $this->clock = $clock;
    }

    /**
     * What $moduleKey's module.json says about licensing, or null if no
     * such module is installed. Only a literal true for "keyRequired" or
     * a non-empty "sharesKeyWith" makes a key required.
     *
     * @return array{required: bool, sharesKeyWith: ?string}|null
     */
    private function declaration(string $moduleKey): ?array
    {
        $manifest = $this->moduleManager->discover()[$moduleKey] ?? null;

        if ($manifest === null) {
            return null;
        }

        $license = is_array($manifest['license'] ?? null) ? $manifest['license'] : [];
        $shares  = $license['sharesKeyWith'] ?? null;
        $shares  = is_string($shares) && $shares !== '' && $shares !== $moduleKey ? $shares : null;

        return [
            'required'      => ($license['keyRequired'] ?? false) === true || $shares !== null,
            'sharesKeyWith' => $shares,
        ];
    }

    private function state(bool $hasKey, ?string $lastSuccess, ?string $lastResult): string
    {
        if (!$hasKey) {
            return self::STATE_NO_KEY;
        }

        if ($lastSuccess === null) {
            return self::STATE_UNVALIDATED;
        }

        if ($this->now() - (int) strtotime($lastSuccess) > self::GRACE_DAYS * 86400) {
            return self::STATE_EXPIRED;
        }

        return $lastResult === LicenseCheckinClient::VALID ? self::STATE_VALID : self::STATE_GRACE;
    }

    private function graceDaysLeft(string $lastSuccess): int
    {
        $secondsLeft = (int) strtotime($lastSuccess) + self::GRACE_DAYS * 86400 - $this->now();

        return max(0, (int) ceil($secondsLeft / 86400));
    }

    /**
     * Ids of the stored keys to try for $moduleKey: those assigned to the
     * module itself, then those assigned to the module it shares a key
     * with (and so on up the chain). The key that last validated the
     * module goes first, so a healthy instance makes one request.
     *
     * @return int[]
     */
    private function candidateKeyIds(string $moduleKey): array
    {
        $byModule = $this->keyIdsByModule();
        $keyIds   = [];
        $visited  = [];
        $holder   = $moduleKey;

        while ($holder !== null && !isset($visited[$holder])) {
            $visited[$holder] = true;
            $keyIds           = array_merge($keyIds, $byModule[$holder] ?? []);
            $holder           = $this->declaration($holder)['sharesKeyWith'] ?? null;
        }

        $keyIds = array_values(array_unique($keyIds));
        $last   = $this->rows()[$moduleKey]->license_key_id ?? null;

        if ($last !== null && in_array((int) $last, $keyIds, true)) {
            $keyIds = array_values(array_unique([(int) $last, ...$keyIds]));
        }

        return $keyIds;
    }

    /**
     * One check-in for one module: each candidate key in turn until the
     * server accepts one. A rejection moves on to the next key; an
     * unreachable server ends the attempt, since the next key would only
     * wait out the same timeout.
     *
     * @param bool $serverKnownUnreachable record the attempt as unreachable without calling
     *
     * @return string|null the LicenseCheckinClient result, or null if there was nothing to ask
     */
    private function attempt(string $moduleKey, bool $serverKnownUnreachable): ?string
    {
        if (!($this->declaration($moduleKey)['required'] ?? false)) {
            return null;
        }

        $keyIds = $this->candidateKeyIds($moduleKey);

        if (!$keyIds) {
            $this->save($moduleKey);

            return null;
        }

        $result  = $serverKnownUnreachable ? LicenseCheckinClient::UNREACHABLE : LicenseCheckinClient::REJECTED;
        $changes = [];

        foreach ($serverKnownUnreachable ? [] : $keyIds as $keyId) {
            $licenseKey = \LicenseKeys::findFirst(['conditions' => 'id = :id:', 'bind' => ['id' => $keyId]]);
            $key        = $licenseKey instanceof \LicenseKeys ? $licenseKey->revealKey() : null;

            if ($key === null || $key === '') {
                error_log("LicenseManager: licence key {$keyId} could not be decrypted, skipping it for {$moduleKey}");

                continue;
            }

            $result = $this->client()->checkIn($key, $moduleKey);

            if ($result === LicenseCheckinClient::VALID) {
                $changes = [
                    'last_successful_checkin_at' => date('Y-m-d H:i:s', $this->now()),
                    'license_key_id'             => $keyId,
                    'expires_on'                 => $this->client()->lastExpiresOn(),
                ];
            }

            if ($result !== LicenseCheckinClient::REJECTED) {
                break;
            }
        }

        $this->save($moduleKey, $changes + [
            'last_attempt_at' => date('Y-m-d H:i:s', $this->now()),
            'last_result'     => $result,
        ]);

        return $result;
    }

    /**
     * Writes $changes to the module's row, recomputes its state, and
     * fires 'license:changed' if the saved state moved. A success that
     * was earned by a key no longer stored for the module is dropped
     * first: check-in history belongs to the key that earned it.
     *
     * @param array<string, mixed> $changes column => value
     */
    private function save(string $moduleKey, array $changes = []): void
    {
        $row = \LicenseEntitlements::findFirst(['conditions' => 'module_key = :key:', 'bind' => ['key' => $moduleKey]]);

        if (!$row instanceof \LicenseEntitlements) {
            $row             = new \LicenseEntitlements();
            $row->module_key = $moduleKey;
            $row->created_at = date('Y-m-d H:i:s');
        }

        $previous = $row->state !== null ? (string) $row->state : null;
        $keyIds   = $this->candidateKeyIds($moduleKey);

        if ($row->license_key_id !== null && !in_array((int) $row->license_key_id, $keyIds, true)) {
            $changes += ['license_key_id' => null, 'last_successful_checkin_at' => null, 'last_result' => null, 'expires_on' => null];
        }

        foreach ($changes as $column => $value) {
            $row->$column = $value;
        }

        $state = $this->state(
            (bool) $keyIds,
            $row->last_successful_checkin_at ? (string) $row->last_successful_checkin_at : null,
            $row->last_result ? (string) $row->last_result : null
        );

        if (!$changes && $state === $previous) {
            return;
        }

        $row->state = $state;

        if (!$row->save()) {
            throw new \RuntimeException('license_entitlements row did not save: ' . implode('; ', $row->getMessages()));
        }

        $this->rows = null;

        if ($state !== $previous) {
            $this->announce($moduleKey, $state, $previous, $row->license_key_id !== null ? (int) $row->license_key_id : null);
        }
    }

    /**
     * After the row is saved, never before: a listener reading the state
     * back must see what it was just told. A listener that throws is
     * logged and does not undo the save or reach the caller.
     */
    private function announce(string $moduleKey, string $state, ?string $previous, ?int $licenseKeyId): void
    {
        try {
            $this->eventsBus->fire(self::EVENT_CHANGED, $this, [
                'module'       => $moduleKey,
                'state'        => $state,
                'previous'     => $previous,
                'licensed'     => in_array($state, self::LICENSED_STATES, true),
                'licenseKeyId' => $licenseKeyId,
            ]);
        } catch (\Throwable $e) {
            error_log("LicenseManager: a '" . self::EVENT_CHANGED . "' listener failed for {$moduleKey}: " . $e->getMessage());
        }
    }

    /**
     * @param string[] $moduleKeys
     */
    private function assign(\LicenseKeys $licenseKey, array $moduleKeys): void
    {
        $wanted  = array_values(array_unique(array_intersect(array_map('strval', $moduleKeys), $this->modulesNeedingAKey())));
        $current = [];

        $assignments = \LicenseKeyModules::find(['conditions' => 'license_key_id = :id:', 'bind' => ['id' => $licenseKey->id]]);

        foreach ($assignments as $assignment) {
            if (in_array($assignment->module_key, $wanted, true)) {
                $current[] = $assignment->module_key;
            } else {
                $assignment->delete();
            }
        }

        foreach (array_diff($wanted, $current) as $moduleKey) {
            $assignment                 = new \LicenseKeyModules();
            $assignment->license_key_id = $licenseKey->id;
            $assignment->module_key     = $moduleKey;
            $assignment->created_at     = date('Y-m-d H:i:s');
            $assignment->save();
        }
    }

    private function assertUsableKey(string $key, ?int $exceptId): void
    {
        if ($key === '') {
            throw new \InvalidArgumentException('Enter the licence key.');
        }

        if (strlen($key) > 255 || preg_match('/\s/', $key)) {
            throw new \InvalidArgumentException('That does not look like a licence key: it must be one unbroken string of at most 255 characters.');
        }

        foreach (\LicenseKeys::find() as $existing) {
            if ((int) $existing->id !== $exceptId && $existing->revealKey() === $key) {
                throw new \InvalidArgumentException('That licence key is already stored on this instance.');
            }
        }
    }

    private function cleanLabel(?string $label): ?string
    {
        $label = trim((string) $label);

        return $label === '' ? null : mb_substr($label, 0, 100);
    }

    /**
     * @return array<string, \LicenseEntitlements>
     */
    private function rows(): array
    {
        if ($this->rows === null) {
            $this->rows = [];

            try {
                foreach (\LicenseEntitlements::find() as $row) {
                    $this->rows[(string) $row->module_key] = $row;
                }
            } catch (\Throwable $e) {
                // Migration 024 not applied yet: every paid module reads as having no key.
                error_log('LicenseManager: license_entitlements could not be read: ' . $e->getMessage());
            }
        }

        return $this->rows;
    }

    /**
     * @return array<string, int[]>
     */
    private function keyIdsByModule(): array
    {
        if ($this->keyIdsByModule === null) {
            $this->keyIdsByModule = [];

            try {
                $live = [];

                foreach (\LicenseKeys::find() as $licenseKey) {
                    $live[(int) $licenseKey->id] = true;
                }

                foreach (\LicenseKeyModules::find(['order' => 'license_key_id']) as $assignment) {
                    if (isset($live[(int) $assignment->license_key_id])) {
                        $this->keyIdsByModule[(string) $assignment->module_key][] = (int) $assignment->license_key_id;
                    }
                }
            } catch (\Throwable $e) {
                error_log('LicenseManager: licence keys could not be read: ' . $e->getMessage());
            }
        }

        return $this->keyIdsByModule;
    }

    /**
     * Whether this request has a principal (App_skeleton\CurrentPrincipal):
     * a signed-in browser session, or an API key, which has no session at
     * all. A guest's request is answered without asking CurrentPrincipal:
     * nothing has named a principal and no session is open, and asking
     * would send it to look in the session, which would start one (and
     * set a cookie) after the response has gone.
     */
    private function requestIsAuthenticated(): bool
    {
        $di = $this->getDI();

        if (!$di->getService('currentPrincipal')->isResolved() && session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        return $di->getShared('currentPrincipal')->userId() !== null;
    }

    /**
     * True for exactly one caller per day: the UPDATE only matches while
     * the stored date is not yet today's, so concurrent requests cannot
     * both win it.
     */
    private function claimDay(string $today): bool
    {
        $this->db->execute(
            'UPDATE settings SET setting_value = :today WHERE setting_key = :key AND (setting_value IS NULL OR setting_value <> :not_today)',
            ['today' => $today, 'key' => self::DAILY_CLAIM_SETTING, 'not_today' => $today]
        );

        if ($this->db->affectedRows() === 1) {
            return true;
        }

        $exists = $this->db->fetchOne(
            'SELECT 1 AS present FROM settings WHERE setting_key = :key',
            \Phalcon\Db\Enum::FETCH_ASSOC,
            ['key' => self::DAILY_CLAIM_SETTING]
        );

        if ($exists) {
            return false;
        }

        try {
            // setting_key is UNIQUE, so of two first-ever claimants one fails here.
            return $this->db->execute(
                'INSERT INTO settings (setting_key, setting_value) VALUES (:key, :today)',
                ['key' => self::DAILY_CLAIM_SETTING, 'today' => $today]
            );
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function client(): LicenseCheckinClient
    {
        return $this->client ??= new LicenseCheckinClient((string) ($this->config->path('licensing.server_url') ?? ''));
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }
}
