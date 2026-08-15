<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Project audit Fix Kit (2026-08-13, Tier 1) — direct coverage for
 * AuditLogController. Unlike the other four controllers named in the
 * same Fix Kit item, this one got real *list-view* behavior (search/
 * sort/pagination, RB-03) rather than a bulk action — the audit trail
 * is immutable by design, no bulkAction() exists here (see the
 * controller's own indexAction() docblock). What this controller does
 * uniquely is viewAction() + reverseAction() (Audit::reverse()), so
 * that's what's covered: viewing an entry, reversing a reversible one,
 * and confirming an already-reversed entry can't be reversed again.
 * Real HTTP against the real running stack — see HttpClient's own
 * docblock.
 */
final class AuditLogControllerTest extends TestCase
{
    private static string $adminEmail;
    private static string $adminPassword = 'PhpunitTest123!';

    public static function setUpBeforeClass(): void
    {
        $di = new \Phalcon\Di\FactoryDefault();
        require APP_PATH . '/config/services.php';
        require APP_PATH . '/config/loader.php';
        \Phalcon\Di\Di::setDefault($di);

        self::$adminEmail = 'phpunit-auditlog-' . bin2hex(random_bytes(6)) . '@example.invalid';

        $admin                = new \Users();
        $admin->email         = self::$adminEmail;
        $admin->password_hash = password_hash(self::$adminPassword, PASSWORD_DEFAULT);
        $admin->first_name    = 'PHPUnit';
        $admin->last_name     = 'AuditLogControllerTest';
        $admin->role_id       = 1; // admin
        $admin->is_active     = 1;

        self::assertTrue($admin->save(), 'fixture admin failed to save: ' . implode('; ', $admin->getMessages()));
    }

    public static function tearDownAfterClass(): void
    {
        foreach (\Settings::find(['conditions' => "setting_key LIKE 'phpunit_auditlog_fixture_%'"]) as $leftover) {
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

    /**
     * Settings is one of the REVERSIBLE_TABLES (Audit.php) and
     * keepSnapshots(true) means creating one through the real Settings
     * model (not the HTTP endpoint — the point here is exercising
     * AuditLogController, not re-testing SettingsController) writes a
     * real audit_log 'insert' row this test can then view/reverse.
     */
    private function makeReversibleAuditEntry(): \AuditLog
    {
        $key            = 'phpunit_auditlog_fixture_' . bin2hex(random_bytes(4));
        $setting        = new \Settings();
        $setting->setting_key   = $key;
        $setting->setting_value = 'fixture-value';
        $setting->save();

        $entry = \AuditLog::findFirst([
            'conditions' => "entity_type = 'settings' AND action = 'insert' AND entity_id = :id:",
            'bind'       => ['id' => $setting->id],
            'order'      => 'id DESC',
        ]);

        $this->assertNotNull($entry, 'expected an insert audit_log row for the fixture Settings record');

        return $entry;
    }

    public function testAdminCanViewAnAuditLogEntry(): void
    {
        $client = $this->loggedInClient();
        $entry  = $this->makeReversibleAuditEntry();

        $response = $client->get('/backend/audit-log/view/' . $entry->id);

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('settings', $response['body']);
    }

    public function testAdminCanReverseAReversibleEntry(): void
    {
        $client = $this->loggedInClient();
        $entry  = $this->makeReversibleAuditEntry();

        $client->get('/backend/audit-log/reverse/' . $entry->id);

        $reversal = \AuditLog::findFirst([
            'conditions' => 'reversed_audit_log_id = :id:',
            'bind'       => ['id' => $entry->id],
        ]);

        $this->assertNotNull($reversal, 'reverseAction() did not write a reversal row pointing back at the original entry');
        $this->assertSame('reversal', $reversal->action);
    }

    public function testAnAlreadyReversedEntryCannotBeReversedAgain(): void
    {
        $client = $this->loggedInClient();
        $entry  = $this->makeReversibleAuditEntry();

        $client->get('/backend/audit-log/reverse/' . $entry->id);
        $firstReversalCount = \AuditLog::count(['conditions' => 'reversed_audit_log_id = :id:', 'bind' => ['id' => $entry->id]]);
        $this->assertSame(1, $firstReversalCount, 'expected exactly one reversal row after the first reverseAction() call');

        $client->get('/backend/audit-log/reverse/' . $entry->id);
        $secondReversalCount = \AuditLog::count(['conditions' => 'reversed_audit_log_id = :id:', 'bind' => ['id' => $entry->id]]);

        $this->assertSame(1, $secondReversalCount, 'reverseAction() reversed an already-reversed entry a second time');
    }
}
