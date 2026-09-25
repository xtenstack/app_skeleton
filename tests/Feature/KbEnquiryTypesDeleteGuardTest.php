<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * REQ-225 review fix: KbEnquiryTypesController refuses to soft-delete an
 * enquiry type that any kb_articles row (enquiry_type_id, NOT NULL) or
 * tickets row (kb_enquiry_type_id) still references — single delete and
 * bulk delete alike, and bulk is all-or-nothing. Without the guard the
 * API's name lookup (trait-filtered findFirst) would stop resolving the
 * type and every published article under it would silently vanish from
 * index/match rather than error. Real HTTP against the running stack
 * with an admin session (this controller is $allowedRoles = [1]) — see
 * HttpClient's own docblock for why this isn't dispatched in-process.
 */
final class KbEnquiryTypesDeleteGuardTest extends TestCase
{
    private static string $adminEmail;
    private static string $adminPassword = 'PhpunitTest123!';
    private static int $adminId;

    /** @var int[] */
    private static array $typeIds = [];

    /** @var int[] */
    private static array $articleIds = [];

    public static function setUpBeforeClass(): void
    {
        $di = new \Phalcon\Di\FactoryDefault();
        require APP_PATH . '/config/services.php';
        require APP_PATH . '/config/loader.php';
        \Phalcon\Di\Di::setDefault($di);

        self::$adminEmail = 'phpunit-kbtypes-admin-' . bin2hex(random_bytes(6)) . '@example.invalid';

        $admin                = new \Users();
        $admin->email         = self::$adminEmail;
        $admin->password_hash = password_hash(self::$adminPassword, PASSWORD_DEFAULT);
        $admin->first_name    = 'PHPUnit';
        $admin->last_name     = 'KbTypesAdmin';
        $admin->role_id       = 1; // admin — KbEnquiryTypesController is admin-only
        $admin->is_active     = 1;
        self::assertTrue($admin->save(), 'fixture admin failed to save: ' . implode('; ', $admin->getMessages()));
        self::$adminId = (int) $admin->id;
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$articleIds as $articleId) {
            \KbArticles::findFirstWithTrashed(['conditions' => 'id = :id:', 'bind' => ['id' => $articleId]])?->delete();
        }

        foreach (self::$typeIds as $typeId) {
            \KbEnquiryTypes::findFirstWithTrashed(['conditions' => 'id = :id:', 'bind' => ['id' => $typeId]])?->delete();
        }

        $user = \Users::findFirstWithTrashed(['conditions' => 'email = :email:', 'bind' => ['email' => self::$adminEmail]]);
        $user?->softDelete();
    }

    private function newType(): \KbEnquiryTypes
    {
        $type              = new \KbEnquiryTypes();
        $type->name        = 'phpunit-type-' . bin2hex(random_bytes(4));
        $type->description = 'KbEnquiryTypesDeleteGuardTest fixture';
        self::assertTrue($type->save(), 'fixture type failed to save: ' . implode('; ', $type->getMessages()));

        self::$typeIds[] = (int) $type->id;

        return $type;
    }

    private function newArticleOfType(\KbEnquiryTypes $type): \KbArticles
    {
        $article                     = new \KbArticles();
        $article->title              = 'phpunit-kbtypes-fixture-' . bin2hex(random_bytes(4));
        $article->body               = 'Fixture body for KbEnquiryTypesDeleteGuardTest.';
        $article->enquiry_type_id    = (int) $type->id;
        $article->visibility         = 'internal';
        $article->status             = 'draft';
        $article->created_by_user_id = self::$adminId;
        $article->updated_by_user_id = self::$adminId;
        self::assertTrue($article->save(), 'fixture article failed to save: ' . implode('; ', $article->getMessages()));

        self::$articleIds[] = (int) $article->id;

        return $article;
    }

    private function loggedInAdmin(): HttpClient
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
            'fixture admin failed to log in — can\'t test the delete guard without a real authenticated session'
        );

        return $client;
    }

    /** @return array{key: string, token: string} */
    private function csrfFor(HttpClient $client): array
    {
        return HttpClient::extractCsrf($client->get('/backend/kb-enquiry-types')['body']);
    }

    private function isLive(int $typeId): bool
    {
        return \KbEnquiryTypes::findFirstById($typeId) !== null;
    }

    public function testReferencedTypeCannotBeDeletedSingly(): void
    {
        $type = $this->newType();
        $this->newArticleOfType($type);

        $client = $this->loggedInAdmin();
        $csrf   = $this->csrfFor($client);

        $response = $client->post('/backend/kb-enquiry-types/delete/' . $type->id, [$csrf['key'] => $csrf['token']]);

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('still referenced by 1 article(s)', $response['body'], 'expected the in-use refusal flash naming the article count');
        $this->assertTrue($this->isLive((int) $type->id), 'a referenced enquiry type must not be soft-deleted');
    }

    public function testBulkDeleteIsAllOrNothingWhenAnySelectedTypeIsReferenced(): void
    {
        $referenced   = $this->newType();
        $unreferenced = $this->newType();
        $this->newArticleOfType($referenced);

        $client = $this->loggedInAdmin();
        $csrf   = $this->csrfFor($client);

        $response = $client->post('/backend/kb-enquiry-types/bulk', [
            'bulk_action'            => 'delete',
            'kb_enquiry_type_ids'    => [(string) $referenced->id, (string) $unreferenced->id],
            $csrf['key']             => $csrf['token'],
        ]);

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('Nothing was deleted', $response['body']);
        $this->assertTrue($this->isLive((int) $referenced->id), 'the referenced type must survive a bulk delete');
        $this->assertTrue($this->isLive((int) $unreferenced->id), 'bulk delete must be all-or-nothing — the unreferenced type must survive too');
    }

    public function testUnreferencedTypeStillDeletes(): void
    {
        $type = $this->newType();

        $client = $this->loggedInAdmin();
        $csrf   = $this->csrfFor($client);

        $response = $client->post('/backend/kb-enquiry-types/delete/' . $type->id, [$csrf['key'] => $csrf['token']]);

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('Enquiry type deleted', $response['body']);
        $this->assertFalse($this->isLive((int) $type->id), 'an unreferenced type should soft-delete normally — the guard must not block everything');
    }
}
