<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Project audit Fix Kit (2026-08-13, Tier 1) — direct coverage for
 * ApiKeysController, which got real bulk-action logic (bulkAction()'s
 * "with selected" bulk revoke, scoped to the logged-in user's own keys)
 * via the list-view convention rollout (1eb5b5b) but had zero automated
 * coverage before this. Covers create (raw token shown once, only the
 * hash persisted), single revoke, the user-scoping guard (one user
 * can't revoke another's key), and a successful bulk revoke. Real HTTP
 * against the real running stack — see HttpClient's own docblock.
 */
final class ApiKeysControllerTest extends TestCase
{
    private static string $userEmail;
    private static string $userPassword = 'PhpunitTest123!';
    private static int $userId;

    private static string $otherEmail;
    private static int $otherUserId;

    public static function setUpBeforeClass(): void
    {
        $di = new \Phalcon\Di\FactoryDefault();
        require APP_PATH . '/config/services.php';
        require APP_PATH . '/config/loader.php';
        \Phalcon\Di\Di::setDefault($di);

        self::$userEmail = 'phpunit-apikeys-' . bin2hex(random_bytes(6)) . '@example.invalid';

        $user                = new \Users();
        $user->email         = self::$userEmail;
        $user->password_hash = password_hash(self::$userPassword, PASSWORD_DEFAULT);
        $user->first_name    = 'PHPUnit';
        $user->last_name     = 'ApiKeysControllerTest';
        $user->role_id       = 1; // admin — role is irrelevant to this controller, only user_id scoping matters
        $user->is_active     = 1;

        self::assertTrue($user->save(), 'fixture user failed to save: ' . implode('; ', $user->getMessages()));
        self::$userId = (int) $user->id;

        self::$otherEmail = 'phpunit-apikeys-other-' . bin2hex(random_bytes(6)) . '@example.invalid';

        $other                = new \Users();
        $other->email         = self::$otherEmail;
        $other->password_hash = password_hash(self::$userPassword, PASSWORD_DEFAULT);
        $other->first_name    = 'PHPUnit';
        $other->last_name     = 'ApiKeysControllerOther';
        $other->role_id       = 1;
        $other->is_active     = 1;

        self::assertTrue($other->save(), 'fixture other-user failed to save: ' . implode('; ', $other->getMessages()));
        self::$otherUserId = (int) $other->id;
    }

    public static function tearDownAfterClass(): void
    {
        foreach (\ApiKeys::find(['conditions' => 'user_id IN ({ids:array})', 'bind' => ['ids' => [self::$userId, self::$otherUserId]]]) as $leftover) {
            $leftover->delete();
        }

        foreach ([self::$userEmail, self::$otherEmail] as $email) {
            $user = \Users::findFirstWithTrashed(['conditions' => 'email = :email:', 'bind' => ['email' => $email]]);
            $user?->softDelete();
        }
    }

    private function loggedInClient(string $email): HttpClient
    {
        $client    = new HttpClient();
        $loginPage = $client->get('/backend/session');
        $csrf      = HttpClient::extractCsrf($loginPage['body']);

        $response = $client->post('/backend/session/login', [
            'email'      => $email,
            'password'   => self::$userPassword,
            $csrf['key'] => $csrf['token'],
        ]);

        $this->assertStringNotContainsString(
            'Invalid email or password',
            $response['body'],
            'fixture user failed to log in — can\'t test the controller without a real authenticated session'
        );

        return $client;
    }

    public function testUserCanCreateAnApiKeyAndOnlyTheHashPersists(): void
    {
        $client = $this->loggedInClient(self::$userEmail);

        $indexPage = $client->get('/backend/api-keys');
        $csrf      = HttpClient::extractCsrf($indexPage['body']);

        $name = 'phpunit-apikeys-fixture-' . bin2hex(random_bytes(4));

        $response = $client->post('/backend/api-keys/create', [
            'name'       => $name,
            $csrf['key'] => $csrf['token'],
        ]);

        $saved = \ApiKeys::findFirst(['conditions' => 'name = :name: AND user_id = :uid:', 'bind' => ['name' => $name, 'uid' => self::$userId]]);

        $this->assertNotNull($saved, 'API key was not created');
        $this->assertStringContainsString($saved->token_prefix, $response['body'], 'the raw token/prefix is not shown once on create');
        $this->assertNotSame('sk_', substr((string) $saved->token_hash, 0, 3), 'the raw token was persisted instead of a hash');
    }

    public function testUserCanRevokeTheirOwnKey(): void
    {
        $client = $this->loggedInClient(self::$userEmail);

        $apiKey               = new \ApiKeys();
        $apiKey->user_id      = self::$userId;
        $apiKey->name         = 'phpunit-apikeys-fixture-' . bin2hex(random_bytes(4));
        $apiKey->token_hash   = hash('sha256', 'phpunit-fixture-token');
        $apiKey->token_prefix = 'sk_fixture';
        $apiKey->save();

        $client->get('/backend/api-keys/revoke/' . $apiKey->id);

        $apiKey->refresh();
        $this->assertNotNull($apiKey->revoked_at, 'revokeAction() did not set revoked_at');
    }

    public function testUserCannotRevokeAnotherUsersKey(): void
    {
        $client = $this->loggedInClient(self::$userEmail);

        $otherKey               = new \ApiKeys();
        $otherKey->user_id      = self::$otherUserId;
        $otherKey->name         = 'phpunit-apikeys-fixture-' . bin2hex(random_bytes(4));
        $otherKey->token_hash   = hash('sha256', 'phpunit-fixture-other-token');
        $otherKey->token_prefix = 'sk_other';
        $otherKey->save();

        $client->get('/backend/api-keys/revoke/' . $otherKey->id);

        $otherKey->refresh();
        $this->assertNull($otherKey->revoked_at, 'a user was able to revoke another user\'s API key');
    }

    public function testBulkActionRevokesOnlySelectedKeysScopedToTheLoggedInUser(): void
    {
        $client = $this->loggedInClient(self::$userEmail);

        $keyOne               = new \ApiKeys();
        $keyOne->user_id      = self::$userId;
        $keyOne->name         = 'phpunit-apikeys-fixture-' . bin2hex(random_bytes(4));
        $keyOne->token_hash   = hash('sha256', 'phpunit-fixture-bulk-1');
        $keyOne->token_prefix = 'sk_bulk1';
        $keyOne->save();

        $keyTwo               = new \ApiKeys();
        $keyTwo->user_id      = self::$userId;
        $keyTwo->name         = 'phpunit-apikeys-fixture-' . bin2hex(random_bytes(4));
        $keyTwo->token_hash   = hash('sha256', 'phpunit-fixture-bulk-2');
        $keyTwo->token_prefix = 'sk_bulk2';
        $keyTwo->save();

        $notSelected               = new \ApiKeys();
        $notSelected->user_id      = self::$userId;
        $notSelected->name         = 'phpunit-apikeys-fixture-' . bin2hex(random_bytes(4));
        $notSelected->token_hash   = hash('sha256', 'phpunit-fixture-bulk-3');
        $notSelected->token_prefix = 'sk_bulk3';
        $notSelected->save();

        $indexPage = $client->get('/backend/api-keys');
        $csrf      = HttpClient::extractCsrf($indexPage['body']);

        $client->post('/backend/api-keys/bulk', [
            'api_key_ids' => [(string) $keyOne->id, (string) $keyTwo->id],
            $csrf['key']  => $csrf['token'],
        ]);

        $keyOne->refresh();
        $keyTwo->refresh();
        $notSelected->refresh();

        $this->assertNotNull($keyOne->revoked_at, 'bulkAction() did not revoke the first selected key');
        $this->assertNotNull($keyTwo->revoked_at, 'bulkAction() did not revoke the second selected key');
        $this->assertNull($notSelected->revoked_at, 'bulkAction() revoked a key that was not selected');
    }
}
