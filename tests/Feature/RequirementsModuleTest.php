<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Phalcon\Di\FactoryDefault;

/**
 * Zero automated coverage existed for requirements-module before this
 * (project audit, Tier 3 — "changed the most this period with the least
 * coverage of any surface in the app," and that got *more* true in
 * Session 15: a new `project` field and the new search/sort/pagination
 * convention both landed with only manual verification). Covers the
 * module's own $allowedRoles gate (admin/operator only — no self-service
 * signup reaches those roles, so the fixture account is created directly
 * via the ORM, same minimal-DI pattern SoftDeleteTest/RbacTest's cleanup
 * already use), a real create round-trip including the new `project`
 * field, and App_skeleton\ListView's search/sort behavior against real
 * data — that helper has no coverage of its own anywhere else either.
 * Real HTTP against the real running stack — see HttpClient's own
 * docblock for why this isn't dispatched in-process.
 *
 * requirements-module is a private, optional module (docs/INTERNAL-MODULES.md)
 * — CI and a genuine public clone have no composer.local.json and never
 * install it, so this whole class skips itself when the module's models
 * directory isn't present rather than failing. Only meaningful on an
 * instance that actually has the module installed (prod, dev droplet, or
 * a local dev machine with composer.local.json set up).
 */
final class RequirementsModuleTest extends TestCase
{
    private const MODELS_DIR = BASE_PATH . '/vendor/xtenstack/requirements-module/src/models/';

    private static string $adminEmail;
    private static string $adminPassword = 'PhpunitTest123!';

    public static function setUpBeforeClass(): void
    {
        if (!is_dir(self::MODELS_DIR)) {
            self::markTestSkipped('requirements-module is not installed on this instance (no composer.local.json) — skipping, not failing.');
        }

        $di = new FactoryDefault();
        require APP_PATH . '/config/services.php';
        require APP_PATH . '/config/loader.php';
        \Phalcon\Di\Di::setDefault($di);

        // \Requirement is registered dynamically by ModuleManager when the
        // requirements module dispatches a real request — this test's own
        // minimal bootstrap (same as SoftDeleteTest/RbacTest's own DI setup)
        // never triggers that, so the bare/global model class needs its own
        // directory registered directly, same technique app/config/loader.php
        // already uses for this app's own core models.
        (new \Phalcon\Autoload\Loader())
            ->setDirectories([self::MODELS_DIR])
            ->register();

        self::$adminEmail = 'phpunit-reqs-' . bin2hex(random_bytes(6)) . '@example.invalid';

        $admin                = new \Users();
        $admin->email         = self::$adminEmail;
        $admin->password_hash = password_hash(self::$adminPassword, PASSWORD_DEFAULT);
        $admin->first_name    = 'PHPUnit';
        $admin->last_name     = 'RequirementsTest';
        $admin->role_id       = 1; // admin — see RbacTest's own comment on this mapping
        $admin->is_active     = 1;

        self::assertTrue($admin->save(), 'fixture admin failed to save: ' . implode('; ', $admin->getMessages()));
    }

    public static function tearDownAfterClass(): void
    {
        if (!is_dir(self::MODELS_DIR)) {
            return; // setUpBeforeClass skipped — nothing was created to clean up
        }

        // Fixture requirements first (FK-free, plain hard delete), then
        // the fixture user — same order-of-operations reasoning as
        // RbacTest's own cleanup (a user with real audit_log rows can't
        // be hard-deleted, softDelete() is the safe path).
        foreach (\Requirement::findWithTrashed(['conditions' => "title LIKE 'PHPUnit RequirementsModuleTest fixture%'"]) as $leftover) {
            $leftover->delete();
        }

        $user = \Users::findFirstWithTrashed(['conditions' => 'email = :email:', 'bind' => ['email' => self::$adminEmail]]);
        $user?->softDelete();
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
            'fixture admin failed to log in — can\'t test the module without a real authenticated session'
        );

        return $client;
    }

    public function testMemberRoleIsDeniedTheModule(): void
    {
        // Mirrors RbacTest's own pattern but against requirements-module's
        // own $allowedRoles gate specifically — a separate module, a
        // separate onConstruct() check, worth confirming independently
        // rather than assuming ControllerBase's mechanism covers every
        // module just because it covers one.
        $client    = new HttpClient();
        $email     = 'phpunit-reqs-member-' . bin2hex(random_bytes(6)) . '@example.invalid';
        $signupPage = $client->get('/backend/signup');
        $csrf       = HttpClient::extractCsrf($signupPage['body']);

        $client->post('/backend/signup/create', [
            'email'      => $email,
            'password'   => 'PhpunitTest123!',
            'first_name' => 'PHPUnit',
            'last_name'  => 'ReqsMemberTest',
            $csrf['key'] => $csrf['token'],
        ]);

        $loginPage = $client->get('/backend/session');
        $csrf      = HttpClient::extractCsrf($loginPage['body']);
        $client->post('/backend/session/login', [
            'email'      => $email,
            'password'   => 'PhpunitTest123!',
            $csrf['key'] => $csrf['token'],
        ]);

        $response = $client->get('/requirements/requirements');
        $this->assertSame(403, $response['status'], 'a member-role account reached requirements-module\'s admin/operator-only controller');

        $user = \Users::findFirstWithTrashed(['conditions' => 'email = :email:', 'bind' => ['email' => $email]]);
        $user?->softDelete();
    }

    public function testAdminCanCreateARequirementWithProjectField(): void
    {
        $client = $this->loggedInClient();

        $newPage = $client->get('/requirements/requirements/new');
        $csrf    = HttpClient::extractCsrf($newPage['body']);

        $title = 'PHPUnit RequirementsModuleTest fixture — ' . bin2hex(random_bytes(4));

        $response = $client->post('/requirements/requirements/create', [
            'title'        => $title,
            'description'  => 'Created by RequirementsModuleTest',
            'type'         => 'internal',
            'project'      => 'PHPUnit-Project-42',
            $csrf['key']   => $csrf['token'],
        ]);

        $this->assertNotSame(403, $response['status']);

        $saved = \Requirement::findFirst(['conditions' => 'title = :title:', 'bind' => ['title' => $title]]);

        $this->assertNotNull($saved, 'requirement was not created — create() either failed or was rejected');
        $this->assertSame('open', $saved->status, 'a new requirement should start as open');
        $this->assertMatchesRegularExpression('/^REQ-\d+$/', $saved->display_id, 'display_id was not generated in the REQ-NNN shape');
        $this->assertSame('PHPUnit-Project-42', $saved->project, 'the project field (Session 15) did not round-trip through create');
    }

    public function testSearchFiltersToMatchingTitleOnly(): void
    {
        $client = $this->loggedInClient();

        $needle = 'Zzyzx' . bin2hex(random_bytes(4));
        $newPage = $client->get('/requirements/requirements/new');
        $csrf    = HttpClient::extractCsrf($newPage['body']);

        $client->post('/requirements/requirements/create', [
            'title'      => 'PHPUnit RequirementsModuleTest fixture — ' . $needle,
            'type'       => 'internal',
            $csrf['key'] => $csrf['token'],
        ]);

        // App_skeleton\ListView::paginate() — no dedicated test anywhere
        // else for this helper, added Session 15 alongside this module's
        // own search/sort UI.
        $matching = $client->get('/requirements/requirements?q=' . urlencode($needle));
        $this->assertStringContainsString($needle, $matching['body'], 'search for the fixture\'s own unique token found nothing');

        $nonMatching = $client->get('/requirements/requirements?q=' . urlencode('DefinitelyNotPresent' . bin2hex(random_bytes(4))));
        $this->assertStringNotContainsString($needle, $nonMatching['body'], 'a search for an unrelated term still returned the fixture — search is not filtering');
    }
}
