<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Project audit Fix Kit (2026-08-15, Tier 2) — direct coverage for
 * ErrorLogController, the last backend list-view controller without
 * dedicated tests now that Roles/ApiKeys/AuditLog/Cron/
 * ExternalConnections all have one. Admin-only ($allowedRoles = [1]),
 * uses the ListView convention (RB-03: search/sort/pagination) plus its
 * own status filter (unresolved/resolved) layered on top via
 * indexAction()'s $conditions, and two other actions: viewAction() (a
 * single entry, forward-to-index-with-flash for an unknown id) and
 * resolveAction() (POST-only toggle of resolved_at that forwards to
 * viewAction() either way — including on a GET, which this covers as
 * the "doesn't run on GET" guard). Real HTTP against the real running
 * stack — see HttpClient's own docblock.
 */
final class ErrorLogControllerTest extends TestCase
{
    private static string $adminEmail;
    private static string $adminPassword = 'PhpunitTest123!';

    private static string $memberEmail;

    public static function setUpBeforeClass(): void
    {
        $di = new \Phalcon\Di\FactoryDefault();
        require APP_PATH . '/config/services.php';
        require APP_PATH . '/config/loader.php';
        \Phalcon\Di\Di::setDefault($di);

        self::$adminEmail = 'phpunit-errorlog-' . bin2hex(random_bytes(6)) . '@example.invalid';

        $admin                = new \Users();
        $admin->email         = self::$adminEmail;
        $admin->password_hash = password_hash(self::$adminPassword, PASSWORD_DEFAULT);
        $admin->first_name    = 'PHPUnit';
        $admin->last_name     = 'ErrorLogControllerTest';
        $admin->role_id       = 1; // admin
        $admin->is_active     = 1;

        self::assertTrue($admin->save(), 'fixture admin failed to save: ' . implode('; ', $admin->getMessages()));

        self::$memberEmail = 'phpunit-errorlog-member-' . bin2hex(random_bytes(6)) . '@example.invalid';

        $member                = new \Users();
        $member->email         = self::$memberEmail;
        $member->password_hash = password_hash(self::$adminPassword, PASSWORD_DEFAULT);
        $member->first_name    = 'PHPUnit';
        $member->last_name     = 'ErrorLogControllerRbac';
        $member->role_id       = 2; // member -- db/migrations/postgresql/001_initial_schema.sql
        $member->is_active     = 1;

        self::assertTrue($member->save(), 'fixture member failed to save: ' . implode('; ', $member->getMessages()));
    }

    public static function tearDownAfterClass(): void
    {
        foreach (\ErrorLog::find(['conditions' => "exception_class LIKE 'PhpunitErrorLogFixture%'"]) as $leftover) {
            $leftover->delete();
        }

        foreach ([self::$adminEmail, self::$memberEmail] as $email) {
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
            'password'   => self::$adminPassword,
            $csrf['key'] => $csrf['token'],
        ]);

        $this->assertStringNotContainsString(
            'Invalid email or password',
            $response['body'],
            'fixture account failed to log in — can\'t test the controller without a real authenticated session'
        );

        return $client;
    }

    private function makeEntry(string $marker, bool $resolved = false): \ErrorLog
    {
        $entry                  = new \ErrorLog();
        $entry->exception_class = $marker;
        $entry->message         = 'phpunit fixture message ' . $marker;
        $entry->file            = '/tmp/phpunit-fixture.php';
        $entry->line            = 42;
        $entry->request_method  = 'GET';
        $entry->request_uri     = '/phpunit-fixture';
        $entry->resolved_at     = $resolved ? date('Y-m-d H:i:s') : null;

        $this->assertTrue($entry->save(), 'fixture ErrorLog entry failed to save: ' . implode('; ', $entry->getMessages()));

        return $entry;
    }

    public function testAdminCanViewTheIndexList(): void
    {
        $client = $this->loggedInClient(self::$adminEmail);
        $marker = 'PhpunitErrorLogFixtureIndex' . bin2hex(random_bytes(4));
        $this->makeEntry($marker);

        $response = $client->get('/backend/error-log');

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('Error Log', $response['body']);
        // Default sort is created('id') DESC (ListView's first $sortable
        // entry / defaultDir), so a just-inserted row — highest id —
        // lands on page 1 regardless of how many other rows exist.
        $this->assertStringContainsString($marker, $response['body'], 'the fixture entry did not appear in the index list');
    }

    public function testMemberRoleIsDeniedTheIndex(): void
    {
        $client = $this->loggedInClient(self::$memberEmail);

        $response = $client->get('/backend/error-log');

        $this->assertSame(403, $response['status'], 'a member-role account reached an admin-only ($allowedRoles = [1]) controller');
    }

    public function testSearchFiltersToTheMatchingEntryOnly(): void
    {
        $client = $this->loggedInClient(self::$adminEmail);

        $matching    = 'PhpunitErrorLogFixtureSearch' . bin2hex(random_bytes(4));
        $nonMatching = 'PhpunitErrorLogFixtureOther' . bin2hex(random_bytes(4));
        $this->makeEntry($matching);
        $this->makeEntry($nonMatching);

        $response = $client->get('/backend/error-log?q=' . urlencode($matching));

        $this->assertStringContainsString($matching, $response['body']);
        $this->assertStringNotContainsString($nonMatching, $response['body'], 'search (ListView::paginate, ILIKE against exception_class/message/file/request_uri) returned an entry that did not match the query');
    }

    public function testStatusFilterSeparatesResolvedFromUnresolved(): void
    {
        $client = $this->loggedInClient(self::$adminEmail);

        $unresolvedMarker = 'PhpunitErrorLogFixtureUnresolved' . bin2hex(random_bytes(4));
        $resolvedMarker   = 'PhpunitErrorLogFixtureResolved' . bin2hex(random_bytes(4));
        $this->makeEntry($unresolvedMarker, false);
        $this->makeEntry($resolvedMarker, true);

        $unresolvedView = $client->get('/backend/error-log?status=unresolved');
        $this->assertStringContainsString($unresolvedMarker, $unresolvedView['body']);
        $this->assertStringNotContainsString($resolvedMarker, $unresolvedView['body'], 'status=unresolved (resolved_at IS NULL) included a resolved entry');

        $resolvedView = $client->get('/backend/error-log?status=resolved');
        $this->assertStringContainsString($resolvedMarker, $resolvedView['body']);
        $this->assertStringNotContainsString($unresolvedMarker, $resolvedView['body'], 'status=resolved (resolved_at IS NOT NULL) included an unresolved entry');
    }

    public function testAdminCanViewASingleEntry(): void
    {
        $client = $this->loggedInClient(self::$adminEmail);
        $marker = 'PhpunitErrorLogFixtureView' . bin2hex(random_bytes(4));
        $entry  = $this->makeEntry($marker);

        $response = $client->get('/backend/error-log/view/' . $entry->id);

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString($marker, $response['body']);
        $this->assertStringContainsString('Error #' . $entry->id, $response['body']);
    }

    public function testViewingAnUnknownEntryForwardsToIndexWithAFlashError(): void
    {
        $client = $this->loggedInClient(self::$adminEmail);

        $response = $client->get('/backend/error-log/view/999999999');

        $this->assertSame(200, $response['status'], 'expected the dispatcher->forward() to index to resolve to a real page');
        $this->assertStringContainsString('Error log entry was not found', $response['body']);
        $this->assertStringContainsString('Error Log', $response['body'], 'expected to land back on the index view');
    }

    public function testResolveActionTogglesResolvedAtOnAndOff(): void
    {
        $client = $this->loggedInClient(self::$adminEmail);
        $marker = 'PhpunitErrorLogFixtureResolve' . bin2hex(random_bytes(4));
        $entry  = $this->makeEntry($marker);

        $viewPage = $client->get('/backend/error-log/view/' . $entry->id);
        $csrf     = HttpClient::extractCsrf($viewPage['body']);

        $firstResolve = $client->post('/backend/error-log/resolve/' . $entry->id, [
            $csrf['key'] => $csrf['token'],
        ]);

        $entry->refresh();
        $this->assertNotNull($entry->resolved_at, 'resolveAction() did not mark the entry resolved on the first call');

        // resolveAction() forwards back to viewAction(), and that render
        // embeds a freshly rotated token (confirmed directly — reusing the
        // original $csrf here 302s to enforceCsrf()'s rejection path, not
        // a controller bug), so pull a new one from the first POST's own
        // response body before the second call, same as every other
        // multi-POST test in this suite does per-request.
        $csrf2 = HttpClient::extractCsrf($firstResolve['body']);

        $client->post('/backend/error-log/resolve/' . $entry->id, [
            $csrf2['key'] => $csrf2['token'],
        ]);

        $entry->refresh();
        $this->assertNull($entry->resolved_at, 'resolveAction() did not toggle the entry back to unresolved on a second call');
    }

    public function testResolveActionDoesNotRunOnAGetRequest(): void
    {
        $client = $this->loggedInClient(self::$adminEmail);
        $marker = 'PhpunitErrorLogFixtureResolveGet' . bin2hex(random_bytes(4));
        $entry  = $this->makeEntry($marker);

        $client->get('/backend/error-log/resolve/' . $entry->id);

        $entry->refresh();
        $this->assertNull($entry->resolved_at, 'resolveAction() ran on a GET request instead of forwarding to viewAction() unchanged');
    }
}
