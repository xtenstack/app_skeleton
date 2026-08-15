<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Project audit Fix Kit (2026-08-13, Tier 1) — direct coverage for
 * ExternalConnectionsController, which got real bulk-action logic
 * (bulkAction()'s combined "with selected" delete/active-toggle) via
 * the list-view convention rollout (1eb5b5b) but had zero automated
 * coverage before this. Covers create (credential round-trips through
 * Crypto encrypt/decrypt, never stored plaintext), single soft-delete,
 * both bulk-action branches (delete and is_active toggle), and the
 * admin-only revealAction() JSON endpoint. Real HTTP against the real
 * running stack — see HttpClient's own docblock.
 */
final class ExternalConnectionsControllerTest extends TestCase
{
    private static string $adminEmail;
    private static string $adminPassword = 'PhpunitTest123!';

    public static function setUpBeforeClass(): void
    {
        $di = new \Phalcon\Di\FactoryDefault();
        require APP_PATH . '/config/services.php';
        require APP_PATH . '/config/loader.php';
        \Phalcon\Di\Di::setDefault($di);

        self::$adminEmail = 'phpunit-extconn-' . bin2hex(random_bytes(6)) . '@example.invalid';

        $admin                = new \Users();
        $admin->email         = self::$adminEmail;
        $admin->password_hash = password_hash(self::$adminPassword, PASSWORD_DEFAULT);
        $admin->first_name    = 'PHPUnit';
        $admin->last_name     = 'ExternalConnectionsControllerTest';
        $admin->role_id       = 1; // admin
        $admin->is_active     = 1;

        self::assertTrue($admin->save(), 'fixture admin failed to save: ' . implode('; ', $admin->getMessages()));
    }

    public static function tearDownAfterClass(): void
    {
        foreach (\ExternalConnections::findWithTrashed(['conditions' => "name LIKE 'phpunit_extconn_fixture_%'"]) as $leftover) {
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

    public function testAdminCanCreateAConnectionAndTheCredentialIsEncryptedAtRest(): void
    {
        $client = $this->loggedInClient();

        $newPage = $client->get('/backend/external-connections/new');
        $csrf    = HttpClient::extractCsrf($newPage['body']);

        $name = 'phpunit_extconn_fixture_' . bin2hex(random_bytes(4));

        $client->post('/backend/external-connections/create', [
            'name'        => $name,
            'base_url'    => 'https://example.invalid',
            'auth_type'   => 'api_key',
            'credential'  => 'phpunit-fixture-secret',
            'is_active'   => '1',
            $csrf['key']  => $csrf['token'],
        ]);

        $saved = \ExternalConnections::findFirst(['conditions' => 'name = :name:', 'bind' => ['name' => $name]]);

        $this->assertNotNull($saved, 'external connection was not created');
        $this->assertNotSame('phpunit-fixture-secret', $saved->credential, 'credential was stored in plaintext instead of encrypted');
        $this->assertSame('phpunit-fixture-secret', $saved->revealCredential(), 'credential did not round-trip through encrypt/decrypt correctly');
    }

    public function testAdminCanSoftDeleteAConnection(): void
    {
        $client = $this->loggedInClient();

        $connection             = new \ExternalConnections();
        $connection->name       = 'phpunit_extconn_fixture_' . bin2hex(random_bytes(4));
        $connection->auth_type  = 'none';
        $connection->is_active  = 1;
        $connection->save();

        $client->get('/backend/external-connections/delete/' . $connection->id);

        $this->assertNull(\ExternalConnections::findFirstById($connection->id), 'soft-deleted connection still appears in a normal find()');

        $stillThere = \ExternalConnections::findFirstWithTrashed(['conditions' => 'id = :id:', 'bind' => ['id' => $connection->id]]);
        $this->assertNotNull($stillThere, 'deleteAction() hard-deleted the row instead of soft-deleting it');
        $this->assertNotNull($stillThere->deleted_at, 'deleted_at was not set');
    }

    public function testBulkActionDeleteBranchSoftDeletesSelectedConnections(): void
    {
        $client = $this->loggedInClient();

        $toDelete            = new \ExternalConnections();
        $toDelete->name      = 'phpunit_extconn_fixture_' . bin2hex(random_bytes(4));
        $toDelete->auth_type = 'none';
        $toDelete->is_active = 1;
        $toDelete->save();

        $toKeep            = new \ExternalConnections();
        $toKeep->name      = 'phpunit_extconn_fixture_' . bin2hex(random_bytes(4));
        $toKeep->auth_type = 'none';
        $toKeep->is_active = 1;
        $toKeep->save();

        $indexPage = $client->get('/backend/external-connections');
        $csrf      = HttpClient::extractCsrf($indexPage['body']);

        $client->post('/backend/external-connections/bulk', [
            'external_connection_ids' => [(string) $toDelete->id],
            'bulk_action'             => 'delete',
            $csrf['key']              => $csrf['token'],
        ]);

        $this->assertNull(\ExternalConnections::findFirstById($toDelete->id), 'bulkAction() delete branch did not soft-delete the selected connection');
        $this->assertNotNull(\ExternalConnections::findFirstById($toKeep->id), 'bulkAction() delete branch deleted a connection that was not selected');
    }

    public function testBulkActionActiveToggleBranchUpdatesSelectedConnectionsOnly(): void
    {
        $client = $this->loggedInClient();

        $toToggle            = new \ExternalConnections();
        $toToggle->name      = 'phpunit_extconn_fixture_' . bin2hex(random_bytes(4));
        $toToggle->auth_type = 'none';
        $toToggle->is_active = 1;
        $toToggle->save();

        $notSelected            = new \ExternalConnections();
        $notSelected->name      = 'phpunit_extconn_fixture_' . bin2hex(random_bytes(4));
        $notSelected->auth_type = 'none';
        $notSelected->is_active = 1;
        $notSelected->save();

        $indexPage = $client->get('/backend/external-connections');
        $csrf      = HttpClient::extractCsrf($indexPage['body']);

        $client->post('/backend/external-connections/bulk', [
            'external_connection_ids' => [(string) $toToggle->id],
            'is_active'               => '0',
            $csrf['key']              => $csrf['token'],
        ]);

        $toToggle->refresh();
        $notSelected->refresh();

        $this->assertSame(0, (int) $toToggle->is_active, 'bulkAction() active-toggle branch did not update the selected connection');
        $this->assertSame(1, (int) $notSelected->is_active, 'bulkAction() active-toggle branch changed a connection that was not selected');
    }

    public function testRevealActionReturnsTheDecryptedCredentialAsJson(): void
    {
        $client = $this->loggedInClient();

        $connection             = new \ExternalConnections();
        $connection->name       = 'phpunit_extconn_fixture_' . bin2hex(random_bytes(4));
        $connection->auth_type  = 'api_key';
        $connection->is_active  = 1;
        $connection->setCredential('phpunit-reveal-secret');
        $connection->save();

        $response = $client->get('/backend/external-connections/reveal/' . $connection->id);

        $this->assertSame(200, $response['status']);
        $payload = json_decode($response['body'], true);
        $this->assertSame('phpunit-reveal-secret', $payload['credential'] ?? null);
    }
}
