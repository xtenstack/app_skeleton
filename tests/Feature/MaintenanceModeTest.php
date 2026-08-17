<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * REQ-172 — admin-settable maintenance mode. Real HTTP against the real
 * running stack (see HttpClient's own docblock for why). Covers:
 *   - off: normal traffic unaffected
 *   - on: non-admin guest sees the maintenance view instead of the real page
 *   - on: admin can still reach login, and the toggle, to turn it back off
 *   - on: the countdown/absolute-datetime reflect maintenance_mode_until
 *   - on: api traffic gets a JSON 503, not the HTML view
 *
 * Every test that turns maintenance mode on turns it back off in the same
 * test (or tearDownAfterClass does, as a backstop) — this suite must never
 * leave the shared dev stack maintenance-locked for whoever runs it next.
 */
final class MaintenanceModeTest extends TestCase
{
    private static string $adminEmail;
    private static string $adminPassword = 'PhpunitTest123!';

    public static function setUpBeforeClass(): void
    {
        $di = new \Phalcon\Di\FactoryDefault();
        require APP_PATH . '/config/services.php';
        require APP_PATH . '/config/loader.php';
        \Phalcon\Di\Di::setDefault($di);

        self::$adminEmail = 'phpunit-maint-' . bin2hex(random_bytes(6)) . '@example.invalid';

        $admin                = new \Users();
        $admin->email         = self::$adminEmail;
        $admin->password_hash = password_hash(self::$adminPassword, PASSWORD_DEFAULT);
        $admin->first_name    = 'PHPUnit';
        $admin->last_name     = 'MaintenanceModeTest';
        $admin->role_id       = 1; // admin
        $admin->is_active     = 1;

        self::assertTrue($admin->save(), 'fixture admin failed to save: ' . implode('; ', $admin->getMessages()));
    }

    public static function tearDownAfterClass(): void
    {
        // Backstop — every test also turns maintenance back off itself,
        // but this guarantees the shared dev stack is never left locked
        // out even if a test fails partway through.
        self::setSetting('maintenance_mode', '0');
        self::setSetting('maintenance_mode_until', '');

        $admin = \Users::findFirstWithTrashed(['conditions' => 'email = :email:', 'bind' => ['email' => self::$adminEmail]]);
        $admin?->softDelete();
    }

    private static function setSetting(string $key, string $value): void
    {
        $setting = \Settings::findFirst(['conditions' => 'setting_key = :key:', 'bind' => ['key' => $key]]);

        if (!$setting) {
            $setting              = new \Settings();
            $setting->setting_key = $key;
        }

        $setting->setting_value = $value;
        self::assertTrue($setting->save(), "failed to set {$key}: " . implode('; ', $setting->getMessages()));
    }

    private function loggedInAdminClient(): HttpClient
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
            'fixture admin failed to log in'
        );

        return $client;
    }

    public function testMaintenanceModeOffLeavesNormalTrafficUnaffected(): void
    {
        self::setSetting('maintenance_mode', '0');

        $client   = new HttpClient();
        $response = $client->get('/');

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('You have successfully installed', $response['body']);
        $this->assertStringNotContainsString('down for maintenance', $response['body']);
    }

    public function testMaintenanceModeOnShowsMaintenanceViewToGuest(): void
    {
        self::setSetting('maintenance_mode_until', date('Y-m-d H:i:s', strtotime('+2 hours')));
        self::setSetting('maintenance_mode', '1');

        try {
            $client   = new HttpClient();
            $response = $client->get('/');

            $this->assertSame(200, $response['status']);
            $this->assertStringContainsString('down for maintenance', $response['body']);
            $this->assertStringNotContainsString('You have successfully installed', $response['body']);
            $this->assertStringContainsString('Admin login', $response['body']);

            // A random backend URL should also get the maintenance view,
            // not the normal redirect-to-login — "all non-admin traffic".
            $backendResponse = $client->get('/backend');
            $this->assertStringContainsString('down for maintenance', $backendResponse['body']);
        } finally {
            self::setSetting('maintenance_mode', '0');
        }
    }

    public function testMaintenanceModeOnStillAllowsAdminLoginAndToggleOff(): void
    {
        self::setSetting('maintenance_mode_until', date('Y-m-d H:i:s', strtotime('+2 hours')));
        self::setSetting('maintenance_mode', '1');

        try {
            // Login page itself must stay reachable and un-maintenance'd.
            $anonClient = new HttpClient();
            $loginPage  = $anonClient->get('/backend/session');
            $this->assertStringContainsString('Sign in to the backend', $loginPage['body']);
            $this->assertStringNotContainsString('down for maintenance', $loginPage['body']);

            // Logging in as admin bypasses maintenance mode entirely.
            $client           = $this->loggedInAdminClient();
            $dashboardResponse = $client->get('/backend');
            $this->assertStringContainsString('Dashboard', $dashboardResponse['body']);
            $this->assertStringNotContainsString('down for maintenance', $dashboardResponse['body']);

            // Admin flips it back off via the real toggle action.
            $configPage = $client->get('/backend/configuration');
            $this->assertStringNotContainsString('down for maintenance', $configPage['body']);
            $csrf = HttpClient::extractCsrf($configPage['body']);

            $client->post('/backend/configuration/maintenance', [
                'maintenance_mode' => '0',
                $csrf['key']       => $csrf['token'],
            ]);

            $setting = \Settings::findFirst(['conditions' => "setting_key = 'maintenance_mode'"]);
            $this->assertSame('0', $setting->setting_value, 'toggle action did not turn maintenance mode off');

            // And a guest is back to normal traffic immediately.
            $guestResponse = (new HttpClient())->get('/');
            $this->assertStringContainsString('You have successfully installed', $guestResponse['body']);
        } finally {
            self::setSetting('maintenance_mode', '0');
        }
    }

    public function testMaintenanceViewShowsCountdownAndAbsoluteDatetime(): void
    {
        $until = date('Y-m-d H:i:s', strtotime('+3 hours +15 minutes'));
        self::setSetting('maintenance_mode_until', $until);
        self::setSetting('maintenance_mode', '1');

        try {
            $client   = new HttpClient();
            $response = $client->get('/');

            $expectedIso  = date('c', strtotime($until));
            $expectedText = date('j M Y, g:i A', strtotime($until));

            $this->assertStringContainsString(
                'data-until="' . $expectedIso . '"',
                $response['body'],
                'countdown target (data-until) does not match the configured maintenance_mode_until'
            );
            $this->assertStringContainsString(
                $expectedText,
                $response['body'],
                'absolute datetime display does not match the configured maintenance_mode_until'
            );
            // Both forms present simultaneously, per the spec.
            $this->assertStringContainsString('maintenance-countdown', $response['body']);
        } finally {
            self::setSetting('maintenance_mode', '0');
        }
    }

    public function testMaintenanceModeOnReturnsJsonServiceUnavailableForApiTraffic(): void
    {
        self::setSetting('maintenance_mode_until', date('Y-m-d H:i:s', strtotime('+2 hours')));
        self::setSetting('maintenance_mode', '1');

        try {
            $client   = new HttpClient();
            $response = $client->get('/api/tickets');

            $this->assertSame(503, $response['status']);
            $decoded = json_decode($response['body'], true);
            $this->assertIsArray($decoded);
            $this->assertArrayHasKey('error', $decoded);
        } finally {
            self::setSetting('maintenance_mode', '0');
        }
    }
}
