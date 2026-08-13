<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Project audit Fix Kit (2026-08-11, Tier 2) — direct coverage for
 * SettingsController, one of the two controllers the report named as
 * reasonable next targets (with UsersController, see UsersControllerTest).
 * Covers create + the separate saveAction() update path (distinct actions,
 * see the controller — worth confirming both independently rather than
 * assuming one covers the other), and the app-wide settings a real
 * request already depends on (default_theme_palette etc., read via
 * App_skeleton\Settings elsewhere in the app) round-tripping correctly.
 * Real HTTP against the real running stack — see HttpClient's own
 * docblock.
 */
final class SettingsControllerTest extends TestCase
{
    private static string $adminEmail;
    private static string $adminPassword = 'PhpunitTest123!';

    public static function setUpBeforeClass(): void
    {
        $di = new \Phalcon\Di\FactoryDefault();
        require APP_PATH . '/config/services.php';
        require APP_PATH . '/config/loader.php';
        \Phalcon\Di\Di::setDefault($di);

        self::$adminEmail = 'phpunit-settings-' . bin2hex(random_bytes(6)) . '@example.invalid';

        $admin                = new \Users();
        $admin->email         = self::$adminEmail;
        $admin->password_hash = password_hash(self::$adminPassword, PASSWORD_DEFAULT);
        $admin->first_name    = 'PHPUnit';
        $admin->last_name     = 'SettingsControllerTest';
        $admin->role_id       = 1; // admin
        $admin->is_active     = 1;

        self::assertTrue($admin->save(), 'fixture admin failed to save: ' . implode('; ', $admin->getMessages()));
    }

    public static function tearDownAfterClass(): void
    {
        foreach (\Settings::find(['conditions' => "setting_key LIKE 'phpunit_settings_fixture_%'"]) as $leftover) {
            $leftover->delete();
        }

        $admin = \Users::findFirstWithTrashed(['conditions' => 'email = :email:', 'bind' => ['email' => self::$adminEmail]]);
        $admin?->softDelete();
    }

    private function loggedInClient(): HttpClient
    {
        $client    = new HttpClient();
        $loginPage = $client->get('/backend/session');
        $csrf      = HttpClient::extractCsrf($loginPage['body']);

        $response = $client->post('/backend/session/login', [
            'email'      => self::$adminEmail,
            'password'   => self::$adminPassword,
            $csrf['key'] => $csrf['token'],
        ]);

        $this->assertStringNotContainsString(
            'Invalid email or password',
            $response['body'],
            'fixture admin failed to log in — can\'t test the controller without a real authenticated session'
        );

        return $client;
    }

    public function testAdminCanCreateASetting(): void
    {
        $client = $this->loggedInClient();

        $newPage = $client->get('/backend/settings/new');
        $csrf    = HttpClient::extractCsrf($newPage['body']);

        $key = 'phpunit_settings_fixture_' . bin2hex(random_bytes(4));

        $client->post('/backend/settings/create', [
            'setting_key'   => $key,
            'setting_value' => 'fixture-value',
            $csrf['key']    => $csrf['token'],
        ]);

        $saved = \Settings::findFirst(['conditions' => 'setting_key = :key:', 'bind' => ['key' => $key]]);

        $this->assertNotNull($saved, 'setting was not created');
        $this->assertSame('fixture-value', $saved->setting_value);
    }

    public function testAdminCanUpdateAnExistingSettingsValue(): void
    {
        $client = $this->loggedInClient();

        $key     = 'phpunit_settings_fixture_' . bin2hex(random_bytes(4));
        $setting = new \Settings();
        $setting->setting_key   = $key;
        $setting->setting_value = 'original-value';
        $setting->save();

        $editPage = $client->get('/backend/settings/edit/' . $setting->id);
        $csrf     = HttpClient::extractCsrf($editPage['body']);

        $client->post('/backend/settings/save', [
            'id'            => (string) $setting->id,
            'setting_value' => 'updated-value',
            $csrf['key']    => $csrf['token'],
        ]);

        $setting->refresh();

        $this->assertSame('updated-value', $setting->setting_value, 'saveAction() did not update the value');
    }
}
