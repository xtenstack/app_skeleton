<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * REQ-225: api\PublicKbController::faqAction() coverage — the one
 * unauthenticated read surface this feature adds (Knowledge-Base-
 * Module-Plan.md v0.1 section 5, mirroring PublicIntakeController's
 * "registered directly, no principal required" pattern). The query is
 * hardcoded to visibility=public AND status=published (not just a
 * default a caller could widen), so this covers that no combination of
 * fixtures — internal+published, public+draft — ever leaks through,
 * only the one that's actually both. Real HTTP against the real
 * running stack — see HttpClient's own docblock for why this isn't
 * dispatched in-process.
 */
final class PublicKbControllerTest extends TestCase
{
    private static int $adminId;
    private static string $adminEmail;
    private static int $enquiryTypeId;

    /** @var int[] */
    private static array $articleIds = [];

    public static function setUpBeforeClass(): void
    {
        $di = new \Phalcon\Di\FactoryDefault();
        require APP_PATH . '/config/services.php';
        require APP_PATH . '/config/loader.php';
        \Phalcon\Di\Di::setDefault($di);

        $enquiryType = \KbEnquiryTypes::findFirst(['conditions' => "name = 'qualifying-question'"]);
        self::assertNotNull($enquiryType, 'fixture setup requires the qualifying-question enquiry type to exist — run ./run migrate run (020_kb_enquiry_types.sql)');
        self::$enquiryTypeId = (int) $enquiryType->id;

        self::$adminEmail = 'phpunit-publickb-admin-' . bin2hex(random_bytes(6)) . '@example.invalid';

        $admin                = new \Users();
        $admin->email         = self::$adminEmail;
        $admin->password_hash = password_hash('PhpunitTest123!', PASSWORD_DEFAULT);
        $admin->first_name    = 'PHPUnit';
        $admin->last_name     = 'PublicKbAdmin';
        $admin->role_id       = 1; // admin
        $admin->is_active     = 1;
        self::assertTrue($admin->save(), 'fixture admin failed to save: ' . implode('; ', $admin->getMessages()));
        self::$adminId = (int) $admin->id;
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$articleIds as $articleId) {
            \KbArticles::findFirstById($articleId)?->delete();
        }

        $user = \Users::findFirstWithTrashed(['conditions' => 'email = :email:', 'bind' => ['email' => self::$adminEmail]]);
        $user?->softDelete();
    }

    private function newArticle(string $status, string $visibility, string $titleWord): \KbArticles
    {
        $article                     = new \KbArticles();
        $article->title              = 'phpunit-publickb-fixture-' . $titleWord;
        $article->body               = 'Fixture body for PublicKbControllerTest ' . $titleWord;
        $article->summary            = 'Fixture summary ' . $titleWord;
        $article->enquiry_type_id    = self::$enquiryTypeId;
        $article->visibility         = $visibility;
        $article->status             = $status;
        $article->published_at       = $status === 'published' ? date('Y-m-d H:i:s') : null;
        $article->created_by_user_id = self::$adminId;
        $article->updated_by_user_id = self::$adminId;
        self::assertTrue($article->save(), 'fixture article failed to save: ' . implode('; ', $article->getMessages()));

        self::$articleIds[] = (int) $article->id;

        return $article;
    }

    public function testFaqOnlyEverReturnsPublicPublishedArticles(): void
    {
        $marker = bin2hex(random_bytes(4));

        $publicPublished  = $this->newArticle('published', 'public', $marker . '-public-published');
        $publicDraft      = $this->newArticle('draft', 'public', $marker . '-public-draft');
        $internalPublished = $this->newArticle('published', 'internal', $marker . '-internal-published');
        $internalDraft    = $this->newArticle('draft', 'internal', $marker . '-internal-draft');

        $client   = new HttpClient();
        $response = $client->get('/api/public-kb/faq?enquiry_type=qualifying-question');

        $this->assertSame(200, $response['status'], $response['body']);

        $ids = array_column(json_decode($response['body'], true)['articles'] ?? [], 'id');

        $this->assertContains((int) $publicPublished->id, $ids, 'the one public+published fixture should be returned');
        $this->assertNotContains((int) $publicDraft->id, $ids, 'a draft article must never be returned, even if public');
        $this->assertNotContains((int) $internalPublished->id, $ids, 'an internal article must never be returned, even if published');
        $this->assertNotContains((int) $internalDraft->id, $ids, 'an internal draft article must never be returned');
    }

    public function testFaqRequiresNoAuthentication(): void
    {
        $client   = new HttpClient();
        $response = $client->get('/api/public-kb/faq');

        $this->assertSame(200, $response['status'], 'the public FAQ endpoint should not require any authentication');
    }
}
