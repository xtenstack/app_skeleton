<?php
declare(strict_types=1);

use App_skeleton\Audit;
use App_skeleton\Crypto;
use App_skeleton\LicenseCheckinClient;
use App_skeleton\LicenseManager;
use App_skeleton\ModuleManager;
use Phalcon\Di\FactoryDefault;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../fixtures/license-server/FakeLicenseServer.php';

/**
 * The licence check-in engine (docs/MODULE-SPEC.md, Licensing): what
 * state a module is in, how that state moves, and what a check-in does
 * and does not change.
 *
 * Same "real thing" rule as ModuleDependenciesTest. The real
 * LicenseManager works on the real license_* tables through the real
 * models, reads real module.json files (written to a temp directory per
 * test), and checks in over real HTTP with the real client. Two things
 * are stood in for: the list of installed Composer packages, and the
 * licence server, which is tests/fixtures/license-server on a loopback
 * port. No test calls the real licence server. The clock is a closure
 * the test moves, so 119, 120 and 121 days are exact.
 *
 * Fixture modules are all 'lictest_*':
 *   lictest_app   needs its own key
 *   lictest_chat  shares lictest_app's key
 *   lictest_solo  needs its own key
 *   lictest_free  declares "keyRequired": false
 *   lictest_plain declares no licence at all
 */
final class LicenseManagerTest extends TestCase
{
    private const BUNDLE_KEY = 'lictest-bundle-key-0001';
    private const SOLO_KEY   = 'lictest-solo-key-0002';
    private const DAY        = 86400;

    private static FakeLicenseServer $server;

    private static FactoryDefault $di;

    private string $packagesDir;

    private string $errorLog;

    private string $previousErrorLog;

    private int $now;

    private ?string $previousClaim;

    /** @var list<array<string, mixed>> */
    private array $events = [];

    public static function setUpBeforeClass(): void
    {
        $di = new FactoryDefault();
        require APP_PATH . '/config/services.php';
        require APP_PATH . '/config/loader.php';

        \Phalcon\Di\Di::setDefault($di);

        self::$di     = $di;
        self::$server = FakeLicenseServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    protected function setUp(): void
    {
        $this->packagesDir = sys_get_temp_dir() . '/app_skeleton_lictest_' . bin2hex(random_bytes(4));
        mkdir($this->packagesDir);

        $this->errorLog         = $this->packagesDir . '/error.log';
        $this->previousErrorLog = (string) ini_set('error_log', $this->errorLog);

        // Mid-morning, so a test that steps the clock by whole days never
        // lands on the other side of midnight by accident.
        $this->now    = (int) strtotime('2026-03-01 10:00:00');
        $this->events = [];

        // Each test starts with the day's check-in unclaimed; whatever the
        // instance under test had is put back in tearDown().
        $claim               = \Settings::findFirst(['conditions' => "setting_key = 'license_checkin_attempted_on'"]);
        $this->previousClaim = $claim ? (string) $claim->setting_value : null;

        self::$di->getShared('db')->execute("DELETE FROM settings WHERE setting_key = 'license_checkin_attempted_on'");

        self::$server->know([
            self::BUNDLE_KEY => ['lictest_app', 'lictest_chat'],
            self::SOLO_KEY   => ['lictest_solo'],
        ]);
        self::$server->waitUntilIdle();
        self::$server->forgetRequests();

        self::$di->remove('eventsBus');
        self::$di->setShared('eventsBus', fn () => new \Phalcon\Events\Manager());

        // Each test starts as a guest's request: no principal, no key.
        self::$di->getShared('currentPrincipal')->clear();
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog);

        self::$di->getShared('currentPrincipal')->clear();
        unset($_SERVER['HTTP_AUTHORIZATION']);

        $db = self::$di->getShared('db');

        $db->execute("DELETE FROM license_entitlements WHERE module_key LIKE 'lictest!_%' ESCAPE '!'");
        $db->execute("DELETE FROM license_key_modules WHERE license_key_id IN (SELECT id FROM license_keys WHERE label LIKE 'lictest %')");
        $db->execute("DELETE FROM license_keys WHERE label LIKE 'lictest %'");
        $db->execute("DELETE FROM module_registry WHERE module_key LIKE 'lictest!_%' ESCAPE '!'");
        $db->execute("DELETE FROM settings WHERE setting_key = 'license_checkin_attempted_on'");

        if ($this->previousClaim !== null) {
            $db->execute(
                "INSERT INTO settings (setting_key, setting_value) VALUES ('license_checkin_attempted_on', :value)",
                ['value' => $this->previousClaim]
            );
        }

        foreach (glob($this->packagesDir . '/*/*') ?: [] as $file) {
            unlink($file);
        }

        foreach (glob($this->packagesDir . '/*') ?: [] as $path) {
            is_dir($path) ? rmdir($path) : unlink($path);
        }

        rmdir($this->packagesDir);
    }

    public function testAModuleThatNeedsNoKeyIsLicensedWithNoRowAndNoRequest(): void
    {
        $licenseManager = $this->licenseManager();

        foreach (['lictest_free', 'lictest_plain'] as $moduleKey) {
            self::assertTrue($licenseManager->isLicensed($moduleKey), $moduleKey);
            self::assertSame(LicenseManager::STATE_NOT_REQUIRED, $licenseManager->entitlement($moduleKey)['state'], $moduleKey);
            self::assertSame(LicenseManager::STATE_NOT_REQUIRED, $licenseManager->checkIn($moduleKey)['state'], $moduleKey);
        }

        self::assertSame(['lictest_app', 'lictest_chat', 'lictest_solo'], $licenseManager->modulesNeedingAKey());
        self::assertSame([], self::$server->requests(), 'a module with no licence must never cause a call');
        self::assertSame(0, \LicenseEntitlements::count("module_key IN ('lictest_free', 'lictest_plain')"));
    }

    public function testAModuleThatIsNotInstalledIsNotLicensed(): void
    {
        $entitlement = $this->licenseManager()->entitlement('lictest_missing');

        self::assertSame(LicenseManager::STATE_NOT_INSTALLED, $entitlement['state']);
        self::assertFalse($entitlement['licensed'], 'a mistyped module key must not read as licensed');
    }

    public function testAPaidModuleWithNoKeyIsNotLicensedAndNothingIsSent(): void
    {
        $licenseManager = $this->licenseManager();

        self::assertFalse($licenseManager->isLicensed('lictest_app'));

        $entitlement = $licenseManager->checkIn('lictest_app');

        self::assertSame(LicenseManager::STATE_NO_KEY, $entitlement['state']);
        self::assertFalse($entitlement['hasKey']);
        self::assertNull($entitlement['graceDaysLeft']);
        self::assertSame([], self::$server->requests());
    }

    public function testAStoredKeyIsEncryptedAtRestAndOnlyItsLastFourCharactersAreKept(): void
    {
        $licenseKey = $this->licenseManager()->addKey(self::BUNDLE_KEY, 'lictest bundle', ['lictest_app']);

        $row = self::$di->getShared('db')->fetchOne(
            'SELECT * FROM license_keys WHERE id = :id',
            \Phalcon\Db\Enum::FETCH_ASSOC,
            ['id' => $licenseKey->id]
        );

        self::assertStringNotContainsString(self::BUNDLE_KEY, implode('|', array_map('strval', $row)), 'the key is in the row in the clear');
        self::assertSame(self::BUNDLE_KEY, Crypto::decrypt((string) $row['key_encrypted']));
        self::assertSame('0001', $row['key_hint']);
        self::assertStringNotContainsString(substr(self::BUNDLE_KEY, 0, -4), $licenseKey->masked());

        $audited = self::$di->getShared('db')->fetchAll(
            "SELECT new_values FROM audit_log WHERE entity_type = 'license_keys' AND entity_id = :id",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            ['id' => $licenseKey->id]
        );

        self::assertNotEmpty($audited, 'adding a key should be in the audit log');
        self::assertStringNotContainsString(self::BUNDLE_KEY, json_encode($audited), 'the audit log holds the key in the clear');
    }

    public function testTheAuditLogRecordsThatAKeyChangedButNeverTheKeyEvenEncrypted(): void
    {
        $licenseManager = $this->licenseManager();
        $licenseKey     = $licenseManager->addKey(self::BUNDLE_KEY, 'lictest bundle', ['lictest_app']);
        $replaced       = $licenseManager->updateKey((int) $licenseKey->id, self::SOLO_KEY, 'lictest bundle', ['lictest_app']);
        $ciphertexts    = [(string) $licenseKey->key_encrypted, (string) $replaced->key_encrypted];

        self::assertNotContains('', $ciphertexts);
        self::assertTrue($licenseManager->removeKey((int) $licenseKey->id));

        $rows = self::$di->getShared('db')->fetchAll(
            "SELECT action, old_values, new_values FROM audit_log WHERE entity_type = 'license_keys' AND entity_id = :id ORDER BY id",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            ['id' => $licenseKey->id]
        );

        self::assertSame(['insert', 'update', 'update'], array_column($rows, 'action'), 'adding, replacing and removing a key should each be in the audit log');

        // Decoded, not searched as text: the ciphertext is base64, and its
        // slashes are escaped inside the stored JSON.
        $logged = [];

        foreach ($rows as $row) {
            foreach (['old_values', 'new_values'] as $column) {
                $values = $row[$column] !== null ? json_decode((string) $row[$column], true) : [];

                if (array_key_exists('key_encrypted', $values)) {
                    $logged[] = $values['key_encrypted'];
                }
            }
        }

        // Added; replaced (old and new); removed (old, then blank).
        self::assertSame([Audit::REDACTED, Audit::REDACTED, Audit::REDACTED, Audit::REDACTED, ''], $logged);

        foreach ($ciphertexts as $ciphertext) {
            self::assertNotContains($ciphertext, $logged, 'the audit log holds a licence key, encrypted, after the key itself was removed');
        }
    }

    public function testCryptoRekeyKnowsAboutTheLicenceKeyColumn(): void
    {
        $this->licenseManager()->addKey(self::BUNDLE_KEY, 'lictest bundle', ['lictest_app']);

        $status = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(BASE_PATH . '/run') . ' crypto status 2>&1');

        self::assertMatchesRegularExpression(
            '/license_keys\.key_encrypted: [1-9]\d* encrypted value/',
            $status,
            'a key that ./run crypto rekey does not re-encrypt becomes unreadable after a rekey'
        );
    }

    public function testTheSameKeyCannotBeStoredTwiceAndAnEmptyOneIsRefused(): void
    {
        $licenseManager = $this->licenseManager();
        $licenseManager->addKey(self::BUNDLE_KEY, 'lictest bundle', ['lictest_app']);

        foreach ([self::BUNDLE_KEY, '  ', "two\nlines"] as $badKey) {
            try {
                $licenseManager->addKey($badKey, 'lictest duplicate', ['lictest_app']);
                self::fail('addKey() should have refused ' . json_encode($badKey));
            } catch (\InvalidArgumentException $e) {
                self::assertStringNotContainsString(self::BUNDLE_KEY, $e->getMessage());
            }
        }

        self::assertSame(1, \LicenseKeys::count("label LIKE 'lictest %'"));
    }

    public function testACheckInValidatesTheModuleWithOneRequestCarryingItsOwnCode(): void
    {
        $licenseManager = $this->licenseManager();
        $licenseKey     = $licenseManager->addKey(self::BUNDLE_KEY, 'lictest bundle', ['lictest_app']);

        self::assertSame(LicenseManager::STATE_UNVALIDATED, $licenseManager->entitlement('lictest_app')['state']);
        self::assertFalse($licenseManager->isLicensed('lictest_app'), 'a key that has never been confirmed is not a licence');

        $entitlement = $licenseManager->checkIn('lictest_app');

        self::assertSame(LicenseManager::STATE_VALID, $entitlement['state']);
        self::assertTrue($entitlement['licensed']);
        self::assertSame((int) $licenseKey->id, $entitlement['licenseKeyId']);
        self::assertSame(date('Y-m-d H:i:s', $this->now), $entitlement['lastSuccessfulCheckinAt']);
        self::assertSame(LicenseManager::GRACE_DAYS, $entitlement['graceDaysLeft']);
        self::assertSame(
            [['method' => 'POST', 'path' => '/api/lice/checkin', 'key' => self::BUNDLE_KEY, 'module' => 'lictest_app']],
            self::$server->requests()
        );

        // A second manager, as the next request would have: the answer is
        // read from the database, not from the first one's memory.
        self::$server->forgetRequests();
        self::assertTrue($this->licenseManager()->isLicensed('lictest_app'));
        self::assertSame([], self::$server->requests(), 'reading the licence state must not touch the network');
    }

    public function testTheLastDayCoveredIsKeptAndPointedOutWhenItIsClose(): void
    {
        $licenseManager = $this->licenseManager();
        $licenseManager->addKey(self::BUNDLE_KEY, 'lictest bundle', ['lictest_app']);

        // No end date from the server: nothing to say.
        $entitlement = $licenseManager->checkIn('lictest_app');
        self::assertNull($entitlement['expiresOn']);
        self::assertNull($entitlement['expiresInDays']);

        // Far off: kept, shown on the Licences screen, no notice.
        $far = gmdate('Y-m-d', $this->now + 200 * self::DAY);
        self::$server->know([self::BUNDLE_KEY => ['lictest_app']], [], 'normal', [self::BUNDLE_KEY => $far], gmdate('Y-m-d', $this->now));
        $entitlement = $licenseManager->checkIn('lictest_app');
        self::assertSame($far, $entitlement['expiresOn']);
        self::assertSame(200, $entitlement['expiresInDays']);

        // Close: a second manager reads it back from the database.
        $near = gmdate('Y-m-d', $this->now + 12 * self::DAY);
        self::$server->know([self::BUNDLE_KEY => ['lictest_app']], [], 'normal', [self::BUNDLE_KEY => $near], gmdate('Y-m-d', $this->now));
        $licenseManager->checkIn('lictest_app');

        $entitlement = $this->licenseManager()->entitlement('lictest_app');
        self::assertSame($near, $entitlement['expiresOn']);
        self::assertSame(12, $entitlement['expiresInDays']);
        self::assertTrue($entitlement['licensed'], 'a licence that is about to end is still a licence');

        $this->enable(['lictest_app']);
        self::assertSame(['lictest_app'], array_keys($this->licenseManager()->adminNotice()['expiring']), 'within 30 days: admins are told');

        // The server drops the end date again (renewed with no expiry).
        self::$server->know([self::BUNDLE_KEY => ['lictest_app']]);
        self::assertNull($licenseManager->checkIn('lictest_app')['expiresOn']);
    }

    public function testTheCronRunnerMakesTheDaysCheckInOnceWithNobodySignedIn(): void
    {
        $licenseManager = $this->licenseManager();
        $licenseManager->addKey(self::BUNDLE_KEY, 'lictest bundle', ['lictest_app']);
        $this->enable(['lictest_app']);
        self::$server->forgetRequests();

        $licenseManager->checkInIfDue();
        self::assertSame(['lictest_app'], array_column(self::$server->requests(), 'module'), 'the first cron pass of the day checks in');
        self::assertTrue($licenseManager->isLicensed('lictest_app'));

        self::$server->forgetRequests();
        $licenseManager->checkInIfDue();
        $this->licenseManager()->checkInIfDue();
        self::assertSame([], self::$server->requests(), 'later passes the same day send nothing');
    }

    public function testSharesKeyWithUsesTheNamedModulesKeyForTheSharingModulesOwnCode(): void
    {
        $licenseManager = $this->licenseManager();
        $licenseKey     = $licenseManager->addKey(self::BUNDLE_KEY, 'lictest bundle', ['lictest_app']);

        self::assertSame(['lictest_app', 'lictest_chat'], $licenseManager->modulesTriedWithKey((int) $licenseKey->id));

        $entitlement = $licenseManager->checkIn('lictest_chat');

        self::assertSame(LicenseManager::STATE_VALID, $entitlement['state']);
        self::assertSame('lictest_app', $entitlement['sharesKeyWith']);
        self::assertSame((int) $licenseKey->id, $entitlement['licenseKeyId']);
        self::assertSame(
            [['method' => 'POST', 'path' => '/api/lice/checkin', 'key' => self::BUNDLE_KEY, 'module' => 'lictest_chat']],
            self::$server->requests(),
            "the bundle's key is sent for the sharing module's own code, not the bundling module's"
        );
        self::assertSame(
            LicenseManager::STATE_UNVALIDATED,
            $licenseManager->entitlement('lictest_app')['state'],
            'validating the sharing module says nothing about the module it shares with'
        );
    }

    public function testASharingModuleTheKeyDoesNotCoverIsNotLicensedByItsBundle(): void
    {
        // The customer bought the application without the chat channel.
        self::$server->know([self::BUNDLE_KEY => ['lictest_app']]);

        $licenseManager = $this->licenseManager();
        $licenseManager->addKey(self::BUNDLE_KEY, 'lictest bundle', ['lictest_app']);

        $entitlements = $licenseManager->checkInModules(['lictest_app', 'lictest_chat']);

        self::assertSame(LicenseManager::STATE_VALID, $entitlements['lictest_app']['state']);
        self::assertSame(LicenseManager::STATE_UNVALIDATED, $entitlements['lictest_chat']['state']);
        self::assertSame('rejected', $entitlements['lictest_chat']['lastResult']);
        self::assertFalse($entitlements['lictest_chat']['licensed']);
    }

    public function testASharingModuleBoughtOnItsOwnIsLicensedByAKeyOfItsOwn(): void
    {
        self::$server->know(['lictest-chat-only-key-0003' => ['lictest_chat']]);

        $licenseManager = $this->licenseManager();
        $licenseManager->addKey('lictest-chat-only-key-0003', 'lictest chat only', ['lictest_chat']);

        $entitlements = $licenseManager->checkInModules(['lictest_app', 'lictest_chat']);

        self::assertSame(LicenseManager::STATE_VALID, $entitlements['lictest_chat']['state']);
        self::assertSame(LicenseManager::STATE_NO_KEY, $entitlements['lictest_app']['state'], "a plugin's own key is not tried for the application");
        self::assertSame(['lictest_chat'], array_column(self::$server->requests(), 'module'));
    }

    public function testTwoKeysOnOneInstanceEachLicenseTheirOwnModules(): void
    {
        $licenseManager = $this->licenseManager();
        $bundle         = $licenseManager->addKey(self::BUNDLE_KEY, 'lictest bundle', ['lictest_app']);
        $solo           = $licenseManager->addKey(self::SOLO_KEY, 'lictest solo', ['lictest_solo']);

        $this->enable(['lictest_app', 'lictest_chat', 'lictest_solo']);

        $entitlements = $licenseManager->checkInAll();

        self::assertSame(
            ['lictest_app' => 'valid', 'lictest_chat' => 'valid', 'lictest_solo' => 'valid'],
            array_map(static fn (array $entitlement): string => $entitlement['state'], $entitlements)
        );
        self::assertSame((int) $bundle->id, $entitlements['lictest_app']['licenseKeyId']);
        self::assertSame((int) $bundle->id, $entitlements['lictest_chat']['licenseKeyId']);
        self::assertSame((int) $solo->id, $entitlements['lictest_solo']['licenseKeyId']);

        self::assertSame(
            [
                ['key' => self::BUNDLE_KEY, 'module' => 'lictest_app'],
                ['key' => self::BUNDLE_KEY, 'module' => 'lictest_chat'],
                ['key' => self::SOLO_KEY, 'module' => 'lictest_solo'],
            ],
            array_map(static fn (array $request): array => ['key' => $request['key'], 'module' => $request['module']], self::$server->requests()),
            'one request per module, each with the key stored for that module and no other'
        );
    }

    public function testWithTwoKeysForOneModuleTheSecondIsTriedWhenTheFirstIsRefusedAndThenRemembered(): void
    {
        self::$server->know(['lictest-old-key-0009' => ['lictest_solo'], self::SOLO_KEY => ['lictest_solo']], ['lictest-old-key-0009']);

        $licenseManager = $this->licenseManager();
        $licenseManager->addKey('lictest-old-key-0009', 'lictest old', ['lictest_solo']);
        $current = $licenseManager->addKey(self::SOLO_KEY, 'lictest solo', ['lictest_solo']);

        $entitlement = $licenseManager->checkIn('lictest_solo');

        self::assertSame(LicenseManager::STATE_VALID, $entitlement['state']);
        self::assertSame((int) $current->id, $entitlement['licenseKeyId']);
        self::assertSame(['lictest-old-key-0009', self::SOLO_KEY], array_column(self::$server->requests(), 'key'));

        self::$server->forgetRequests();
        $licenseManager->checkIn('lictest_solo');

        self::assertSame([self::SOLO_KEY], array_column(self::$server->requests(), 'key'), 'the key that worked last time goes first');
    }

    /**
     * @param array{state: string, licensed: bool, daysLeft: ?int} $expected
     */
    #[DataProvider('graceBoundaries')]
    public function testTheGracePeriodIs120DaysFromTheLastSuccessfulCheckIn(int $secondsSinceSuccess, array $expected): void
    {
        $licenseManager = $this->validatedApp();

        // The licence server stops answering, and the instance keeps being used.
        self::$server->mode('error500');
        $this->now += $secondsSinceSuccess;

        $entitlement = $licenseManager->checkIn('lictest_app');

        self::assertSame($expected['state'], $entitlement['state']);
        self::assertSame($expected['licensed'], $entitlement['licensed']);
        self::assertSame($expected['daysLeft'], $entitlement['graceDaysLeft']);
        self::assertSame($expected['licensed'], $this->licenseManager()->isLicensed('lictest_app'));
    }

    /**
     * @return array<string, array{0: int, 1: array{state: string, licensed: bool, daysLeft: ?int}}>
     */
    public static function graceBoundaries(): array
    {
        $grace   = ['state' => LicenseManager::STATE_GRACE, 'licensed' => true];
        $expired = ['state' => LicenseManager::STATE_EXPIRED, 'licensed' => false, 'daysLeft' => null];

        return [
            'the next day'               => [1 * self::DAY, $grace + ['daysLeft' => 119]],
            '119 days'                   => [119 * self::DAY, $grace + ['daysLeft' => 1]],
            '120 days less a second'     => [120 * self::DAY - 1, $grace + ['daysLeft' => 1]],
            '120 days exactly'           => [120 * self::DAY, $grace + ['daysLeft' => 0]],
            '120 days and a second'      => [120 * self::DAY + 1, $expired],
            '121 days'                   => [121 * self::DAY, $expired],
        ];
    }

    public function testAnInstanceNobodyUsedStaysValidUntilDay120AndIsExpiredAfterIt(): void
    {
        // No failed check-in in between: nothing ran at all.
        $licenseManager = $this->validatedApp();

        $this->now += 119 * self::DAY;
        self::assertSame(LicenseManager::STATE_VALID, $licenseManager->entitlement('lictest_app')['state']);

        $this->now += 2 * self::DAY;
        self::assertSame(LicenseManager::STATE_EXPIRED, $licenseManager->entitlement('lictest_app')['state']);
        self::assertSame([], self::$server->requests(), 'expiry is worked out locally');
    }

    #[DataProvider('failures')]
    public function testAFailedCheckInNeverClearsAValidState(string $serverMode, string $expectedResult): void
    {
        $licenseManager = $this->validatedApp();
        $validatedAt    = date('Y-m-d H:i:s', $this->now);

        if ($serverMode === 'revoked') {
            self::$server->know([self::BUNDLE_KEY => ['lictest_app']], [self::BUNDLE_KEY]);
        } elseif ($serverMode === 'nothing-listening') {
            $licenseManager->useClient(new LicenseCheckinClient('http://127.0.0.1:' . FakeLicenseServer::freePort(), 1, 2));
        } else {
            self::$server->mode($serverMode);
        }

        $this->now += 3 * self::DAY;

        $entitlement = $licenseManager->checkIn('lictest_app');

        self::assertSame(LicenseManager::STATE_GRACE, $entitlement['state']);
        self::assertTrue($entitlement['licensed'], 'a check-in that failed took the licence away');
        self::assertSame($validatedAt, $entitlement['lastSuccessfulCheckinAt'], 'only a successful check-in may move this');
        self::assertSame(date('Y-m-d H:i:s', $this->now), $entitlement['lastAttemptAt']);
        self::assertSame($expectedResult, $entitlement['lastResult']);
        self::assertSame(117, $entitlement['graceDaysLeft']);

        // And it recovers by itself once the server says yes again.
        self::$server->waitUntilIdle();
        self::$server->know([self::BUNDLE_KEY => ['lictest_app', 'lictest_chat']]);
        $licenseManager = $this->licenseManager();
        $this->now += self::DAY;

        $entitlement = $licenseManager->checkIn('lictest_app');

        self::assertSame(LicenseManager::STATE_VALID, $entitlement['state']);
        self::assertSame(date('Y-m-d H:i:s', $this->now), $entitlement['lastSuccessfulCheckinAt']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function failures(): array
    {
        return [
            'the server refuses the key (revoked)' => ['revoked', 'rejected'],
            'the server errors'                    => ['error500', 'unreachable'],
            'a proxy answers instead'              => ['garbage', 'unreachable'],
            'the server redirects'                 => ['redirect', 'unreachable'],
            'the server times out'                 => ['slow', 'unreachable'],
            'nothing is listening'                 => ['nothing-listening', 'unreachable'],
        ];
    }

    public function testAnUnreachableServerIsAskedOnceNotOncePerModule(): void
    {
        $licenseManager = $this->licenseManager();
        $licenseManager->addKey(self::BUNDLE_KEY, 'lictest bundle', ['lictest_app']);
        $licenseManager->addKey(self::SOLO_KEY, 'lictest solo', ['lictest_solo']);
        $this->enable(['lictest_app', 'lictest_chat', 'lictest_solo']);

        self::$server->mode('error500');

        $entitlements = $licenseManager->checkInAll();

        self::assertCount(1, self::$server->requests());
        self::assertSame(
            ['unreachable', 'unreachable', 'unreachable'],
            array_column($entitlements, 'lastResult'),
            'the modules that were not asked about are still recorded as an attempt that did not get through'
        );
    }

    public function testCheckInAllAsksOnlyAboutEnabledModules(): void
    {
        $licenseManager = $this->licenseManager();
        $licenseManager->addKey(self::BUNDLE_KEY, 'lictest bundle', ['lictest_app']);
        $licenseManager->addKey(self::SOLO_KEY, 'lictest solo', ['lictest_solo']);
        $this->enable(['lictest_solo']);

        $entitlements = $licenseManager->checkInAll();

        self::assertSame(['lictest_solo'], array_column(self::$server->requests(), 'module'));
        self::assertSame(LicenseManager::STATE_VALID, $entitlements['lictest_solo']['state']);
        self::assertSame(LicenseManager::STATE_UNVALIDATED, $entitlements['lictest_app']['state']);
    }

    public function testEveryStateChangeIsAnnouncedOnceAfterItIsSavedWithIdsAndStatesOnly(): void
    {
        $licenseManager = $this->licenseManager();
        $seenInDatabase = [];

        self::$di->getShared('eventsBus')->attach(
            LicenseManager::EVENT_CHANGED,
            function ($event, $source, array $data) use (&$seenInDatabase): void {
                $row              = \LicenseEntitlements::findFirst(['conditions' => 'module_key = :key:', 'bind' => ['key' => $data['module']]]);
                $seenInDatabase[] = $row ? $row->state : null;
            }
        );

        $licenseKey = $licenseManager->addKey(self::BUNDLE_KEY, 'lictest bundle', ['lictest_app']);
        $licenseManager->checkIn('lictest_app');
        $licenseManager->checkIn('lictest_app');

        $forApp = array_values(array_filter($this->events, static fn (array $data): bool => $data['module'] === 'lictest_app'));

        self::assertSame(
            [
                ['module' => 'lictest_app', 'state' => 'unvalidated', 'previous' => null, 'licensed' => false, 'licenseKeyId' => null],
                ['module' => 'lictest_app', 'state' => 'valid', 'previous' => 'unvalidated', 'licensed' => true, 'licenseKeyId' => (int) $licenseKey->id],
            ],
            $forApp,
            'one event per change of state, none for a check-in that changed nothing'
        );
        self::assertSame(array_column($this->events, 'state'), $seenInDatabase, 'a listener must find the new state already saved');
        self::assertStringNotContainsString(self::BUNDLE_KEY, json_encode($this->events));

        // The grace period running out is a change too, though no check-in caused it.
        $this->events = [];
        $this->now   += 121 * self::DAY;
        $licenseManager->syncStates();

        self::assertSame(
            [['module' => 'lictest_app', 'state' => 'expired', 'previous' => 'valid', 'licensed' => false, 'licenseKeyId' => (int) $licenseKey->id]],
            array_values(array_filter($this->events, static fn (array $data): bool => $data['module'] === 'lictest_app'))
        );
    }

    public function testAListenerThatThrowsIsLoggedAndChangesNothing(): void
    {
        $licenseManager = $this->licenseManager();

        self::$di->getShared('eventsBus')->attach(LicenseManager::EVENT_CHANGED, function (): void {
            throw new \RuntimeException('lictest listener blew up');
        });

        $licenseManager->addKey(self::BUNDLE_KEY, 'lictest bundle', ['lictest_app']);

        $entitlement = $licenseManager->checkIn('lictest_app');

        self::assertSame(LicenseManager::STATE_VALID, $entitlement['state']);
        self::assertTrue($this->licenseManager()->isLicensed('lictest_app'), 'the state was saved before the listener ran');
        self::assertStringContainsString('lictest listener blew up', (string) file_get_contents($this->errorLog));
    }

    public function testRemovingAKeyBlanksItAndUnlicensesWhatItValidated(): void
    {
        $licenseManager = $this->validatedApp();
        $licenseKey     = \LicenseKeys::findFirst(['conditions' => "label = 'lictest bundle'"]);
        $this->events   = [];

        self::assertTrue($licenseManager->removeKey((int) $licenseKey->id));

        $entitlement = $licenseManager->entitlement('lictest_app');

        self::assertSame(LicenseManager::STATE_NO_KEY, $entitlement['state']);
        self::assertNull($entitlement['lastSuccessfulCheckinAt'], 'check-in history belongs to the key that earned it');
        self::assertContains(
            ['module' => 'lictest_app', 'state' => 'no_key', 'previous' => 'valid', 'licensed' => false, 'licenseKeyId' => null],
            $this->events
        );

        $row = self::$di->getShared('db')->fetchOne(
            'SELECT key_encrypted, deleted_at FROM license_keys WHERE id = :id',
            \Phalcon\Db\Enum::FETCH_ASSOC,
            ['id' => $licenseKey->id]
        );

        self::assertSame('', $row['key_encrypted'], 'a removed key must not stay in the database');
        self::assertNotNull($row['deleted_at']);

        // A different key entered afterwards starts from nothing.
        $licenseManager->addKey('lictest-junk-key-0404', 'lictest junk', ['lictest_app']);

        self::assertSame(LicenseManager::STATE_UNVALIDATED, $licenseManager->entitlement('lictest_app')['state']);
        self::assertSame(LicenseManager::STATE_UNVALIDATED, $licenseManager->checkIn('lictest_app')['state']);
    }

    public function testReplacingAKeyKeepsTheModuleLicensedAndSendsTheNewKeyFromThenOn(): void
    {
        $licenseManager = $this->validatedApp();
        $licenseKey     = \LicenseKeys::findFirst(['conditions' => "label = 'lictest bundle'"]);

        self::$server->know(['lictest-reissued-key-0005' => ['lictest_app']]);
        self::$server->forgetRequests();

        $licenseManager->updateKey((int) $licenseKey->id, 'lictest-reissued-key-0005', 'lictest bundle', ['lictest_app']);

        self::assertTrue($licenseManager->isLicensed('lictest_app'));

        $this->now += self::DAY;
        $entitlement = $licenseManager->checkIn('lictest_app');

        self::assertSame(LicenseManager::STATE_VALID, $entitlement['state']);
        self::assertSame(['lictest-reissued-key-0005'], array_column(self::$server->requests(), 'key'));
        self::assertSame('0005', \LicenseKeys::findFirst((int) $licenseKey->id)->key_hint);
    }

    public function testEditingAKeyWithNoNewValueKeepsTheStoredKey(): void
    {
        $licenseManager = $this->validatedApp();
        $licenseKey     = \LicenseKeys::findFirst(['conditions' => "label = 'lictest bundle'"]);

        $licenseManager->updateKey((int) $licenseKey->id, '', 'lictest renamed', ['lictest_app', 'lictest_solo', 'lictest_free', 'nonsense']);

        self::assertSame(self::BUNDLE_KEY, \LicenseKeys::findFirst((int) $licenseKey->id)->revealKey());
        self::assertSame(
            ['lictest_app', 'lictest_solo'],
            $licenseManager->assignments()[(int) $licenseKey->id],
            'a key can only be assigned to installed modules that need one'
        );
    }

    public function testTheDailyCheckInIsDueOncePerCalendarDay(): void
    {
        $licenseManager = $this->licenseManager();

        self::assertTrue($licenseManager->dailyCheckInDue());
        self::assertFalse($licenseManager->dailyCheckInDue(), 'second request of the same day');
        self::assertFalse($this->licenseManager()->dailyCheckInDue(), 'another request, another process');

        $this->now += 10 * 3600;
        self::assertFalse($this->licenseManager()->dailyCheckInDue(), 'later the same day');

        $this->now += 14 * 3600;
        self::assertTrue($this->licenseManager()->dailyCheckInDue(), 'the next calendar day');
        self::assertFalse($this->licenseManager()->dailyCheckInDue());
    }

    public function testNoDailyCheckInOnAnInstanceWithNoPaidModule(): void
    {
        $licenseManager = $this->licenseManager(['lictest_free' => ['keyRequired' => false], 'lictest_plain' => null]);

        self::assertFalse($licenseManager->dailyCheckInDue());
        self::assertSame(0, \Settings::count("setting_key = 'license_checkin_attempted_on'"), 'nothing to check, so nothing was claimed');
    }

    public function testAGuestsRequestNeverStartsACheckIn(): void
    {
        $licenseManager = $this->licenseManager();
        $licenseManager->addKey(self::BUNDLE_KEY, 'lictest bundle', ['lictest_app']);
        $this->enable(['lictest_app']);

        // No principal and no API key: what a guest's request looks like
        // to checkInAfterResponse().
        $licenseManager->checkInAfterResponse();

        self::assertSame([], self::$server->requests());
        self::assertNotSame(PHP_SESSION_ACTIVE, session_status(), 'looking for a principal must not start a session');
        self::assertTrue($licenseManager->dailyCheckInDue(), "the guest's request must not have used up the day");
    }

    public function testAnApiKeyThatDoesNotResolveIsAGuest(): void
    {
        $licenseManager = $this->licenseManager();
        $licenseManager->addKey(self::BUNDLE_KEY, 'lictest bundle', ['lictest_app']);
        $this->enable(['lictest_app']);

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer lictest-no-such-token';
        self::assertNull(self::$di->getShared('apiKeyAuth')->resolve('lictest-no-such-token'));

        $licenseManager->checkInAfterResponse();

        self::assertSame([], self::$server->requests());
        self::assertTrue($licenseManager->dailyCheckInDue());
    }

    public function testARequestAuthenticatedByAnApiKeyStartsTheDaysCheckInWithNoSession(): void
    {
        $licenseManager = $this->licenseManager();
        $licenseManager->addKey(self::BUNDLE_KEY, 'lictest bundle', ['lictest_app']);
        $this->enable(['lictest_app']);

        [$user, $apiKey, $token] = $this->apiKeyFixture();

        try {
            // What the api module's base controller does with a presented
            // key. Such a request has no session at all, so the principal
            // is the only thing that says it was authenticated.
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
            self::assertNotNull(self::$di->getShared('apiKeyAuth')->resolve($token));
            self::assertNotSame(PHP_SESSION_ACTIVE, session_status());

            $licenseManager->checkInAfterResponse();

            self::assertSame(
                [['method' => 'POST', 'path' => '/api/lice/checkin', 'key' => self::BUNDLE_KEY, 'module' => 'lictest_app']],
                self::$server->requests()
            );
            self::assertSame(LicenseManager::STATE_VALID, $licenseManager->entitlement('lictest_app')['state']);
            self::assertNotSame(PHP_SESSION_ACTIVE, session_status(), 'the check-in must not start a session for a keyed request');
            self::assertFalse($licenseManager->dailyCheckInDue(), 'the keyed request used up the day');

            // The check-in is the instance's doing, not the caller's.
            $audited = self::$di->getShared('db')->fetchOne(
                "SELECT actor_user_id, actor_api_key_id FROM audit_log WHERE entity_type = 'license_entitlements' AND action = 'update' ORDER BY id DESC",
                \Phalcon\Db\Enum::FETCH_ASSOC
            );

            self::assertSame(['actor_user_id' => null, 'actor_api_key_id' => null], $audited);

            self::$server->forgetRequests();
            self::$di->getShared('apiKeyAuth')->resolve($token);
            $licenseManager->checkInAfterResponse();

            self::assertSame([], self::$server->requests(), 'a second keyed request the same day');
        } finally {
            // Soft-deleted, like every other test's fixture user: the
            // audit log references the row.
            $apiKey->delete();
            $user->softDelete();
        }
    }

    public function testASignedInRequestStartsTheDaysCheckIn(): void
    {
        $licenseManager = $this->licenseManager();
        $licenseManager->addKey(self::BUNDLE_KEY, 'lictest bundle', ['lictest_app']);
        $this->enable(['lictest_app']);

        // What Auth::isLoggedIn() does when the session carries a user.
        self::$di->getShared('currentPrincipal')->set(1, 1);

        $licenseManager->checkInAfterResponse();

        self::assertCount(1, self::$server->requests());
        self::assertSame(LicenseManager::STATE_VALID, $licenseManager->entitlement('lictest_app')['state']);
    }

    public function testAnInstanceWithNoPaidModuleDoesNothingAtAll(): void
    {
        // Free modules only, enabled, and no licence key anywhere: what
        // every instance is until it installs a paid module.
        $licenseManager = $this->licenseManager(['lictest_free' => ['keyRequired' => false], 'lictest_plain' => null]);
        $this->enable(['lictest_free', 'lictest_plain']);

        $db      = self::$di->getShared('db');
        $queries = [];
        $watcher = new \Phalcon\Events\Manager();
        $watcher->attach('db:beforeQuery', function ($event, $connection) use (&$queries): void {
            $queries[] = $connection->getSQLStatement();
        });
        $db->setEventsManager($watcher);

        try {
            // An authenticated request, the kind that would check in.
            self::$di->getShared('currentPrincipal')->set(1, 1);

            $licenseManager->checkInAfterResponse();

            self::assertSame(['grace' => [], 'unlicensed' => [], 'expiring' => []], $licenseManager->adminNotice(), 'no notice and no modal');
            self::assertSame([], $licenseManager->entitlements());
            self::assertSame([], $licenseManager->modulesNeedingAKey());
            self::assertFalse($licenseManager->dailyCheckInDue());
            self::assertTrue($licenseManager->isLicensed('lictest_free'));
            self::assertTrue($licenseManager->isLicensed('lictest_plain'));
            self::assertNull($licenseManager->checkIn('lictest_free')['lastAttemptAt'], 'checking a free module asks nobody');
            self::assertSame(1, self::$di->getShared('currentPrincipal')->userId(), 'the request principal was left alone');
        } finally {
            $watcher->detachAll('db:beforeQuery');
        }

        self::assertSame([], $queries, 'an instance with no paid module must not touch the database for licensing');
        self::assertSame([], self::$server->requests(), 'nor the licence server');
        self::assertSame(0, \Settings::count("setting_key = 'license_checkin_attempted_on'"));
        self::assertSame(0, \LicenseEntitlements::count("module_key LIKE 'lictest%'"));
    }

    public function testTheAdminNoticeListsEnabledModulesInGraceAndEnabledModulesNotLicensed(): void
    {
        $licenseManager = $this->validatedApp();
        $licenseManager->addKey(self::SOLO_KEY, 'lictest solo', ['lictest_solo']);
        $this->enable(['lictest_app', 'lictest_chat']);

        // lictest_app valid, lictest_chat enabled with a key never confirmed
        // for it, lictest_solo not enabled.
        $notice = $licenseManager->adminNotice();

        self::assertSame([], array_keys($notice['grace']));
        self::assertSame(['lictest_chat'], array_keys($notice['unlicensed']), 'a disabled module is nobody\'s problem yet');

        self::$server->mode('error500');
        $this->now += self::DAY;
        $licenseManager->checkInAll();

        self::assertSame(['lictest_app'], array_keys($licenseManager->adminNotice()['grace']));

        $this->now += 120 * self::DAY;
        $notice = $licenseManager->adminNotice();

        self::assertSame([], array_keys($notice['grace']));
        self::assertSame(['lictest_app', 'lictest_chat'], array_keys($notice['unlicensed']));
    }

    /**
     * lictest_app with the bundle key stored and one successful check-in
     * at the current test time.
     */
    private function validatedApp(): LicenseManager
    {
        $licenseManager = $this->licenseManager();
        $licenseManager->addKey(self::BUNDLE_KEY, 'lictest bundle', ['lictest_app']);

        self::assertSame(LicenseManager::STATE_VALID, $licenseManager->checkIn('lictest_app')['state']);

        self::$server->forgetRequests();

        return $licenseManager;
    }

    /**
     * A real LicenseManager over fixture packages written to disk, wired
     * to the fake licence server and the test's clock. Each call is a new
     * instance with nothing cached, as a new request would be.
     *
     * @param array<string, array<string, mixed>|null>|null $licenses module key => its module.json 'license' (null: none)
     */
    private function licenseManager(?array $licenses = null): LicenseManager
    {
        $licenses ??= [
            'lictest_app'   => ['model' => 'per-instance', 'keyRequired' => true],
            'lictest_chat'  => ['sharesKeyWith' => 'lictest_app'],
            'lictest_solo'  => ['model' => 'per-instance', 'keyRequired' => true],
            'lictest_free'  => ['keyRequired' => false],
            'lictest_plain' => null,
        ];

        $packages = [];

        foreach ($licenses as $key => $license) {
            $dir = $this->packagesDir . '/' . $key;

            if (!is_dir($dir)) {
                mkdir($dir);
            }

            $manifest = ['key' => $key, 'tier' => 'plugin', 'className' => LicenseFixtureModule::class, 'routes' => false];

            if ($license !== null) {
                $manifest['license'] = $license;
            }

            file_put_contents($dir . '/module.json', json_encode($manifest, JSON_THROW_ON_ERROR));

            $packages['lictest/' . $key] = $dir;
        }

        $moduleManager = new class ($packages) extends ModuleManager {
            /**
             * @param array<string, string> $packages
             */
            public function __construct(private array $packages)
            {
            }

            protected function installedPackages(): array
            {
                return $this->packages;
            }
        };

        self::$di->remove('moduleManager');
        self::$di->setShared('moduleManager', $moduleManager);
        self::$di->remove('settings');
        self::$di->setShared('settings', function () {
            $settings = new \App_skeleton\SettingsRegistry();
            $settings->setDI($this);

            return $settings;
        });

        $eventsBus = self::$di->getShared('eventsBus');

        if (!$eventsBus->hasListeners(LicenseManager::EVENT_CHANGED)) {
            $eventsBus->attach(LicenseManager::EVENT_CHANGED, function ($event, $source, array $data): void {
                $this->events[] = $data;
            });
        }

        $licenseManager = new LicenseManager();
        $licenseManager->setDI(self::$di);
        $licenseManager->useClient(new LicenseCheckinClient(self::$server->url(), 1, 2));
        $licenseManager->useClock(fn (): int => $this->now);

        self::assertStringStartsWith('http://127.0.0.1:', $licenseManager->serverUrl(), 'tests must only ever talk to the fake licence server');

        return $licenseManager;
    }

    /**
     * A real user with a real API key, as the api module would find them.
     *
     * @return array{0: \Users, 1: \ApiKeys, 2: string} user, key row, raw token
     */
    private function apiKeyFixture(): array
    {
        $user                = new \Users();
        $user->email         = 'phpunit-lictest-' . bin2hex(random_bytes(6)) . '@example.invalid';
        $user->password_hash = password_hash('PhpunitTest123!', PASSWORD_DEFAULT);
        $user->first_name    = 'PHPUnit';
        $user->last_name     = 'LicenseManagerTest';
        $user->role_id       = 2;
        $user->is_active     = 1;
        self::assertTrue($user->save(), 'fixture user failed to save: ' . implode('; ', $user->getMessages()));

        $token = 'phpunit-lictest-' . bin2hex(random_bytes(16));

        $apiKey               = new \ApiKeys();
        $apiKey->user_id      = (int) $user->id;
        $apiKey->name         = 'phpunit-lictest-fixture-key';
        $apiKey->token_hash   = hash('sha256', $token);
        $apiKey->token_prefix = substr($token, 0, 10);
        self::assertTrue($apiKey->save(), 'fixture api key failed to save: ' . implode('; ', $apiKey->getMessages()));

        // Saving the fixtures is not part of the request under test.
        self::$di->getShared('currentPrincipal')->clear();

        return [$user, $apiKey, $token];
    }

    /**
     * @param string[] $moduleKeys
     */
    private function enable(array $moduleKeys): void
    {
        foreach ($moduleKeys as $key) {
            $row                = new \ModuleRegistry();
            $row->module_key    = $key;
            $row->tier          = 'plugin';
            $row->enabled       = true;
            $row->discovered_at = date('Y-m-d H:i:s');
            $row->updated_at    = date('Y-m-d H:i:s');

            self::assertTrue($row->save(), 'fixture module_registry row failed to save: ' . implode('; ', $row->getMessages()));
        }
    }
}

final class LicenseFixtureModule
{
    public function registerSharedServices(\Phalcon\Di\DiInterface $di): void
    {
    }
}
