<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The Licences admin screen (LicensesController) over real HTTP: every
 * action is admin-only, every state change is a POST that carries the
 * CSRF token, and a key entered through the form is stored encrypted and
 * never shown again.
 *
 * Nothing here causes a check-in. The keys these tests store are ticked
 * for no module, so there is nothing to send, and the two "check now"
 * actions are only ever called as a user who is refused before they run.
 * What a check-in does is covered in-process, against a fake licence
 * server, by tests/Unit/LicenseManagerTest.php.
 */
final class LicensesControllerTest extends TestCase
{
    private const PASSWORD = 'PhpunitTest123!';

    private static string $adminEmail;

    private static string $memberEmail;

    public static function setUpBeforeClass(): void
    {
        $di = new \Phalcon\Di\FactoryDefault();
        require APP_PATH . '/config/services.php';
        require APP_PATH . '/config/loader.php';
        \Phalcon\Di\Di::setDefault($di);

        self::$adminEmail  = 'phpunit-licenses-admin-' . bin2hex(random_bytes(6)) . '@example.invalid';
        self::$memberEmail = 'phpunit-licenses-member-' . bin2hex(random_bytes(6)) . '@example.invalid';

        $memberRoleId = \Roles::idsByNames(['member'])[0];

        foreach ([[self::$adminEmail, 1], [self::$memberEmail, $memberRoleId]] as [$email, $roleId]) {
            $user                = new \Users();
            $user->email         = $email;
            $user->password_hash = password_hash(self::PASSWORD, PASSWORD_DEFAULT);
            $user->first_name    = 'PHPUnit';
            $user->last_name     = 'LicensesControllerTest';
            $user->role_id       = $roleId;
            $user->is_active     = 1;

            self::assertTrue($user->save(), 'fixture user failed to save: ' . implode('; ', $user->getMessages()));
        }
    }

    public static function tearDownAfterClass(): void
    {
        $db = \Phalcon\Di\Di::getDefault()->getShared('db');

        $db->execute("DELETE FROM license_key_modules WHERE license_key_id IN (SELECT id FROM license_keys WHERE label LIKE 'phpunit-license-fixture%')");
        $db->execute("DELETE FROM license_keys WHERE label LIKE 'phpunit-license-fixture%'");

        foreach ([self::$adminEmail, self::$memberEmail] as $email) {
            \Users::findFirstWithTrashed(['conditions' => 'email = :email:', 'bind' => ['email' => $email]])?->softDelete();
        }
    }

    public function testAGuestIsSentToTheLoginPage(): void
    {
        $response = (new HttpClient())->get('/backend/licenses');

        $this->assertStringNotContainsString('Licence keys on this instance', $response['body']);
        $this->assertStringContainsString('name="password"', $response['body'], 'a guest should have landed on the login form');
    }

    public function testANonAdminIsRefusedEveryLicenceActionAndNothingChanges(): void
    {
        $existing = $this->fixtureKey();
        $client   = $this->loggedInClient(self::$memberEmail);
        $csrf     = HttpClient::extractCsrf($client->get('/backend')['body']);
        $label    = 'phpunit-license-fixture-member-' . bin2hex(random_bytes(4));

        $this->assertSame(403, $client->get('/backend/licenses')['status']);
        $this->assertSame(403, $client->get('/backend/licenses/edit-key/' . $existing->id)['status']);

        $posts = [
            '/backend/licenses/add-key'                      => ['license_key' => 'phpunit-member-key-' . bin2hex(random_bytes(8)), 'label' => $label],
            '/backend/licenses/save-key/' . $existing->id    => ['license_key' => '', 'label' => $label],
            '/backend/licenses/remove-key/' . $existing->id  => [],
            '/backend/licenses/check/anything'               => [],
            '/backend/licenses/check-all'                    => [],
        ];

        foreach ($posts as $path => $fields) {
            $response = $client->post($path, $fields + [$csrf['key'] => $csrf['token']]);

            $this->assertSame(403, $response['status'], $path . ' was not refused for a non-admin');
        }

        $this->assertSame(0, \LicenseKeys::count(['conditions' => 'label = :label:', 'bind' => ['label' => $label]]), 'a non-admin stored or renamed a licence key');
        $this->assertNotNull(\LicenseKeys::findFirst((int) $existing->id), 'a non-admin removed a licence key');
    }

    public function testANonAdminNeverSeesTheLicenceNoticeOrModal(): void
    {
        // Vacuous on a stack with no unlicensed paid module enabled, real
        // on one that has: the notice is for the people who can fix it.
        $body = $this->loggedInClient(self::$memberEmail)->get('/backend')['body'];

        $this->assertStringNotContainsString('id="license-modal"', $body);
        $this->assertStringNotContainsString('id="license-alert"', $body);
        $this->assertStringNotContainsString('id="license-grace-notice"', $body);
    }

    public function testOnAnInstanceWithNoPaidModuleAnAdminSeesNoLicenceNoticeOrModal(): void
    {
        if (\Phalcon\Di\Di::getDefault()->getShared('licenseManager')->modulesNeedingAKey()) {
            $this->markTestSkipped('a paid module is installed on this instance, so a licence notice can be legitimate here');
        }

        $client = $this->loggedInClient(self::$adminEmail);

        foreach (['/backend', '/backend/configuration'] as $path) {
            $body = $client->get($path)['body'];

            $this->assertStringNotContainsString('id="license-modal"', $body, $path);
            $this->assertStringNotContainsString('id="license-alert"', $body, $path);
            $this->assertStringNotContainsString('id="license-grace-notice"', $body, $path);
            $this->assertStringNotContainsString('license-notice.js', $body, $path);
        }

        $this->assertStringContainsString('No installed module needs a licence key', $client->get('/backend/licenses')['body']);
    }

    public function testAnAdminSeesTheScreenAndItsFormsCarryTheirOwnCsrfField(): void
    {
        $response = $this->loggedInClient(self::$adminEmail)->get('/backend/licenses');
        $csrf     = HttpClient::extractCsrf($response['body']);

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('Licence keys on this instance', $response['body']);
        $this->assertStringContainsString('Add a licence key', $response['body']);

        // The forms have to submit with JavaScript off, so the token is in
        // the form itself rather than left for app.js to inject.
        $this->assertStringContainsString(
            '<input type="hidden" name="' . $csrf['key'] . '" value="' . $csrf['token'] . '">',
            $response['body']
        );
    }

    public function testAddingAKeyNeedsTheCsrfToken(): void
    {
        $client = $this->loggedInClient(self::$adminEmail);
        $label  = 'phpunit-license-fixture-nocsrf-' . bin2hex(random_bytes(4));

        $client->get('/backend/licenses');
        $client->post('/backend/licenses/add-key', ['license_key' => 'phpunit-key-' . bin2hex(random_bytes(8)), 'label' => $label]);

        $this->assertSame(0, \LicenseKeys::count(['conditions' => 'label = :label:', 'bind' => ['label' => $label]]));
    }

    public function testAnAdminCanAddAKeyWhichIsEncryptedAtRestAndMaskedOnScreen(): void
    {
        $client = $this->loggedInClient(self::$adminEmail);
        $csrf   = HttpClient::extractCsrf($client->get('/backend/licenses')['body']);
        $label  = 'phpunit-license-fixture-add-' . bin2hex(random_bytes(4));
        $key    = 'phpunit-key-' . bin2hex(random_bytes(16));

        $response = $client->post('/backend/licenses/add-key', [
            'license_key' => $key,
            'label'       => $label,
            $csrf['key']  => $csrf['token'],
        ]);

        $saved = \LicenseKeys::findFirst(['conditions' => 'label = :label:', 'bind' => ['label' => $label]]);

        $this->assertNotNull($saved, 'the licence key was not stored');
        $this->assertNotSame($key, $saved->key_encrypted);
        $this->assertStringNotContainsString($key, (string) $saved->key_encrypted, 'the key was stored in the clear');
        $this->assertSame($key, $saved->revealKey(), 'the key did not survive encrypt/decrypt');

        $this->assertStringContainsString('Licence key saved.', $response['body']);
        $this->assertStringContainsString($label, $response['body']);
        $this->assertStringContainsString(substr($key, -4), $response['body'], 'the masked key should end in its last four characters');
        $this->assertStringNotContainsString($key, $response['body'], 'the key was sent back to the browser');
        $this->assertStringNotContainsString($key, $client->get('/backend/licenses/edit-key/' . $saved->id)['body'], 'the edit form reveals the key');
    }

    public function testEditingAKeyWithTheKeyFieldLeftBlankKeepsTheKey(): void
    {
        $existing = $this->fixtureKey();
        $key      = $existing->revealKey();
        $client   = $this->loggedInClient(self::$adminEmail);
        $csrf     = HttpClient::extractCsrf($client->get('/backend/licenses/edit-key/' . $existing->id)['body']);
        $label    = 'phpunit-license-fixture-renamed-' . bin2hex(random_bytes(4));

        $client->post('/backend/licenses/save-key/' . $existing->id, [
            'license_key' => '',
            'label'       => $label,
            $csrf['key']  => $csrf['token'],
        ]);

        $saved = \LicenseKeys::findFirst((int) $existing->id);

        $this->assertSame($label, $saved->label);
        $this->assertSame($key, $saved->revealKey());
    }

    public function testRemovingAKeyIsPostOnlyAndBlanksTheStoredKey(): void
    {
        $existing = $this->fixtureKey();
        $client   = $this->loggedInClient(self::$adminEmail);

        $client->get('/backend/licenses/remove-key/' . $existing->id);

        $this->assertNotNull(\LicenseKeys::findFirst((int) $existing->id), 'a GET removed a licence key');

        $csrf = HttpClient::extractCsrf($client->get('/backend/licenses')['body']);

        $response = $client->post('/backend/licenses/remove-key/' . $existing->id, [$csrf['key'] => $csrf['token']]);

        $this->assertStringContainsString('Licence key removed', $response['body']);
        $this->assertNull(\LicenseKeys::findFirst((int) $existing->id));

        $removed = \LicenseKeys::findFirstWithTrashed(['conditions' => 'id = :id:', 'bind' => ['id' => $existing->id]]);

        $this->assertNotNull($removed, 'the row should be soft-deleted, not gone');
        $this->assertSame('', (string) $removed->key_encrypted, 'a removed key must not stay in the database');
    }

    /**
     * A key stored directly, ticked for no module (so storing it, editing
     * it or removing it sends nothing anywhere).
     */
    private function fixtureKey(): \LicenseKeys
    {
        $licenseKey        = new \LicenseKeys();
        $licenseKey->label = 'phpunit-license-fixture-' . bin2hex(random_bytes(4));
        $licenseKey->setKey('phpunit-key-' . bin2hex(random_bytes(16)));

        $this->assertTrue($licenseKey->save(), 'fixture licence key failed to save: ' . implode('; ', $licenseKey->getMessages()));

        return $licenseKey;
    }

    private function loggedInClient(string $email): HttpClient
    {
        $client = new HttpClient();
        $csrf   = HttpClient::extractCsrf($client->get('/backend/session')['body']);

        $response = $client->post('/backend/session/login', [
            'email'      => $email,
            'password'   => self::PASSWORD,
            $csrf['key'] => $csrf['token'],
        ]);

        $this->assertStringNotContainsString(
            'Invalid email or password',
            $response['body'],
            'fixture account failed to log in — can\'t test the controller without a real authenticated session'
        );

        return $client;
    }
}
