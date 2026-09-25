<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * REQ-225 (Knowledge-Base-Module-Plan.md v0.1 section 5/7):
 * api\KbArticlesController coverage. Read actions (index/view/match) are
 * reachable by any authenticated principal, agents included, but an
 * agent caller only ever sees status=published regardless of what it
 * asks for; create/update/publish are admin/operator only, gated
 * per-action the same way api\TicketsController::closeAction() is.
 *
 * Covers: agent read-OK, agent write-403, operator write-201, and that
 * matchAction() returns a published article for its enquiry type while
 * excluding a draft in the same type — not just that the endpoints
 * respond, but that the actual RBAC/status filtering lands. Real HTTP
 * against the real running stack — see HttpClient's own docblock for
 * why this isn't dispatched in-process.
 */
final class ApiKbArticlesControllerTest extends TestCase
{
    private static string $adminEmail;
    private static string $operatorEmail;
    private static string $agentEmail;
    private static string $password = 'PhpunitTest123!';

    private static int $adminId;
    private static int $operatorId;
    private static int $agentId;

    private static string $operatorApiKeyRawToken;
    private static int $operatorApiKeyId;
    private static string $agentApiKeyRawToken;
    private static int $agentApiKeyId;

    private static int $enquiryTypeId;

    /** @var int[] */
    private static array $articleIds = [];

    public static function setUpBeforeClass(): void
    {
        $di = new \Phalcon\Di\FactoryDefault();
        require APP_PATH . '/config/services.php';
        require APP_PATH . '/config/loader.php';
        \Phalcon\Di\Di::setDefault($di);

        $operatorRoleId = \Roles::idsByNames(['operator'])[0] ?? null;
        $agentRoleId    = \Roles::idsByNames(['agent'])[0] ?? null;
        self::assertNotNull($operatorRoleId, "fixture setup requires an 'operator' role to exist — run ./run seed");
        self::assertNotNull($agentRoleId, "fixture setup requires an 'agent' role to exist — run ./run seed");

        $enquiryType = \KbEnquiryTypes::findFirst(['conditions' => "name = 'qualifying-question'"]);
        self::assertNotNull($enquiryType, 'fixture setup requires the qualifying-question enquiry type to exist — run ./run migrate run (020_kb_enquiry_types.sql)');
        self::$enquiryTypeId = (int) $enquiryType->id;

        self::$adminEmail    = 'phpunit-apikb-admin-' . bin2hex(random_bytes(6)) . '@example.invalid';
        self::$operatorEmail = 'phpunit-apikb-operator-' . bin2hex(random_bytes(6)) . '@example.invalid';
        self::$agentEmail    = 'phpunit-apikb-agent-' . bin2hex(random_bytes(6)) . '@example.invalid';

        $admin                = new \Users();
        $admin->email         = self::$adminEmail;
        $admin->password_hash = password_hash(self::$password, PASSWORD_DEFAULT);
        $admin->first_name    = 'PHPUnit';
        $admin->last_name     = 'ApiKbAdmin';
        $admin->role_id       = 1; // admin
        $admin->is_active     = 1;
        self::assertTrue($admin->save(), 'fixture admin failed to save: ' . implode('; ', $admin->getMessages()));
        self::$adminId = (int) $admin->id;

        $operator                = new \Users();
        $operator->email         = self::$operatorEmail;
        $operator->password_hash = password_hash(self::$password, PASSWORD_DEFAULT);
        $operator->first_name    = 'PHPUnit';
        $operator->last_name     = 'ApiKbOperator';
        $operator->role_id       = $operatorRoleId;
        $operator->is_active     = 1;
        self::assertTrue($operator->save(), 'fixture operator failed to save: ' . implode('; ', $operator->getMessages()));
        self::$operatorId = (int) $operator->id;

        $agent                = new \Users();
        $agent->email         = self::$agentEmail;
        $agent->password_hash = password_hash(self::$password, PASSWORD_DEFAULT);
        $agent->first_name    = 'PHPUnit';
        $agent->last_name     = 'ApiKbAgent';
        $agent->role_id       = $agentRoleId;
        $agent->is_active     = 1;
        self::assertTrue($agent->save(), 'fixture agent failed to save: ' . implode('; ', $agent->getMessages()));
        self::$agentId = (int) $agent->id;

        self::$operatorApiKeyRawToken = 'phpunit-apikb-optoken-' . bin2hex(random_bytes(16));

        $operatorApiKey               = new \ApiKeys();
        $operatorApiKey->user_id      = self::$operatorId;
        $operatorApiKey->name         = 'phpunit-apikb-operator-fixture-key';
        $operatorApiKey->token_hash   = hash('sha256', self::$operatorApiKeyRawToken);
        $operatorApiKey->token_prefix = substr(self::$operatorApiKeyRawToken, 0, 10);
        self::assertTrue($operatorApiKey->save(), 'fixture operator api key failed to save: ' . implode('; ', $operatorApiKey->getMessages()));
        self::$operatorApiKeyId = (int) $operatorApiKey->id;

        self::$agentApiKeyRawToken = 'phpunit-apikb-agenttoken-' . bin2hex(random_bytes(16));

        $agentApiKey               = new \ApiKeys();
        $agentApiKey->user_id      = self::$agentId;
        $agentApiKey->name         = 'phpunit-apikb-agent-fixture-key';
        $agentApiKey->token_hash   = hash('sha256', self::$agentApiKeyRawToken);
        $agentApiKey->token_prefix = substr(self::$agentApiKeyRawToken, 0, 10);
        self::assertTrue($agentApiKey->save(), 'fixture agent api key failed to save: ' . implode('; ', $agentApiKey->getMessages()));
        self::$agentApiKeyId = (int) $agentApiKey->id;
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$articleIds as $articleId) {
            \KbArticles::findFirstById($articleId)?->delete();
        }

        \ApiKeys::findFirstById(self::$operatorApiKeyId)?->delete();
        \ApiKeys::findFirstById(self::$agentApiKeyId)?->delete();

        foreach ([self::$adminEmail, self::$operatorEmail, self::$agentEmail] as $email) {
            $user = \Users::findFirstWithTrashed(['conditions' => 'email = :email:', 'bind' => ['email' => $email]]);
            $user?->softDelete();
        }
    }

    private function newArticle(string $status, string $visibility = 'internal', ?string $titleWord = null): \KbArticles
    {
        $word = $titleWord ?? bin2hex(random_bytes(4));

        $article                     = new \KbArticles();
        $article->title              = 'phpunit-apikb-fixture-' . $word;
        $article->body               = 'Fixture body mentioning ' . $word . ' for ApiKbArticlesControllerTest.';
        $article->summary            = 'Fixture summary ' . $word;
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

    public function testUnauthenticatedCallerIsRejected(): void
    {
        $client   = new HttpClient();
        $response = $client->getWithHeaders('/api/kb-articles');

        $this->assertSame(401, $response['status']);
    }

    public function testAgentReadIsAllowedButAlwaysFilteredToPublished(): void
    {
        $published = $this->newArticle('published', 'internal', 'agentvisible');
        $draft     = $this->newArticle('draft', 'internal', 'agenthidden');

        $client  = new HttpClient();
        $headers = ['X-Api-Key: ' . self::$agentApiKeyRawToken];

        // Even asking for status=draft explicitly, an agent should only
        // ever get back published articles (KbArticlesController::
        // indexAction()'s isAgent() override).
        $response = $client->getWithHeaders('/api/kb-articles?status=draft', $headers);
        $this->assertSame(200, $response['status']);

        $ids = array_column(json_decode($response['body'], true)['articles'] ?? [], 'id');
        $this->assertContains((int) $published->id, $ids, 'agent should see the published fixture');
        $this->assertNotContains((int) $draft->id, $ids, 'agent must never see a draft article, regardless of the status filter it requested');

        $viewDraft = $client->getWithHeaders('/api/kb-articles/view/' . $draft->id, $headers);
        $this->assertSame(404, $viewDraft['status'], 'agent viewing a draft article by id should get 404, not the draft content');

        $viewPublished = $client->getWithHeaders('/api/kb-articles/view/' . $published->id, $headers);
        $this->assertSame(200, $viewPublished['status']);
    }

    public function testAgentCannotCreateArticle(): void
    {
        $client = new HttpClient();

        $response = $client->postJson('/api/kb-articles/create', [
            'title'           => 'phpunit-apikb-agent-should-fail',
            'body'            => 'Should not be created.',
            'enquiry_type_id' => self::$enquiryTypeId,
        ], ['X-Api-Key: ' . self::$agentApiKeyRawToken]);

        $this->assertSame(403, $response['status'], 'agent role should be forbidden from creating KB articles: ' . $response['body']);
    }

    public function testAgentCannotPublishOrUpdateArticle(): void
    {
        $article = $this->newArticle('draft');

        $client = new HttpClient();

        $publishResponse = $client->postJson(
            '/api/kb-articles/publish/' . $article->id,
            [],
            ['X-Api-Key: ' . self::$agentApiKeyRawToken]
        );
        $this->assertSame(403, $publishResponse['status']);

        $updateResponse = $client->postJson(
            '/api/kb-articles/update/' . $article->id,
            ['title' => 'should not apply'],
            ['X-Api-Key: ' . self::$agentApiKeyRawToken]
        );
        $this->assertSame(403, $updateResponse['status']);

        $article->refresh();
        $this->assertSame('draft', $article->status);
    }

    public function testOperatorCanCreateAndPublishArticleAndItBecomesVisibleToAgent(): void
    {
        $client = new HttpClient();

        $createResponse = $client->postJson('/api/kb-articles/create', [
            'title'           => 'phpunit-apikb-operator-created',
            'body'            => 'Operator-authored body.',
            'summary'         => 'Operator summary',
            'enquiry_type_id' => self::$enquiryTypeId,
            'visibility'      => 'internal',
        ], ['X-Api-Key: ' . self::$operatorApiKeyRawToken]);

        $this->assertSame(201, $createResponse['status'], 'operator should be able to create a KB article: ' . $createResponse['body']);

        $payload   = json_decode($createResponse['body'], true);
        $articleId = (int) $payload['article']['id'];
        self::$articleIds[] = $articleId;

        $this->assertSame('draft', $payload['article']['status'], 'a newly created article should start as draft, never pre-published');

        $publishResponse = $client->postJson(
            '/api/kb-articles/publish/' . $articleId,
            [],
            ['X-Api-Key: ' . self::$operatorApiKeyRawToken]
        );
        $this->assertSame(200, $publishResponse['status'], 'operator should be able to publish: ' . $publishResponse['body']);

        $publishedPayload = json_decode($publishResponse['body'], true);
        $this->assertSame('published', $publishedPayload['article']['status']);
        $this->assertNotNull($publishedPayload['article']['published_at']);

        $agentView = $client->getWithHeaders('/api/kb-articles/view/' . $articleId, ['X-Api-Key: ' . self::$agentApiKeyRawToken]);
        $this->assertSame(200, $agentView['status'], 'once published, an agent should be able to read the article');
    }

    public function testMatchReturnsPublishedArticleForItsEnquiryTypeAndNotDraft(): void
    {
        $keyword = 'zzqualifyzz' . bin2hex(random_bytes(3));

        $published        = $this->newArticle('published', 'internal', $keyword . '-published');
        $published->title = 'Published match fixture ' . $keyword;
        self::assertTrue($published->save());

        $draft        = $this->newArticle('draft', 'internal', $keyword . '-draft');
        $draft->title = 'Draft match fixture ' . $keyword;
        self::assertTrue($draft->save());

        $client   = new HttpClient();
        $response = $client->getWithHeaders(
            '/api/kb-articles/match?enquiry_type=qualifying-question&q=' . urlencode($keyword),
            ['X-Api-Key: ' . self::$operatorApiKeyRawToken]
        );

        $this->assertSame(200, $response['status'], $response['body']);

        $ids = array_column(json_decode($response['body'], true)['articles'] ?? [], 'id');
        $this->assertContains((int) $published->id, $ids, 'match should return the published article matching the keyword and type');
        $this->assertNotContains((int) $draft->id, $ids, 'match must never return a draft article');
    }

    public function testMatchFallsBackToTypeArticlesWhenNoKeywordHits(): void
    {
        $marker    = 'zzfallbackzz' . bin2hex(random_bytes(3));
        $published = $this->newArticle('published', 'internal', $marker);

        $client = new HttpClient();

        // A q that can't match anything (random token nowhere in any
        // fixture) must still return the type's published articles,
        // flagged keyword_match=false, rather than an empty list.
        $noHit = $client->getWithHeaders(
            '/api/kb-articles/match?enquiry_type=qualifying-question&q=' . urlencode('qqnohitqq' . bin2hex(random_bytes(6))),
            ['X-Api-Key: ' . self::$operatorApiKeyRawToken]
        );
        $this->assertSame(200, $noHit['status'], $noHit['body']);

        $payload = json_decode($noHit['body'], true);
        $this->assertFalse($payload['keyword_match'], 'no term hit anything, so keyword_match must be false');
        $this->assertContains((int) $published->id, array_column($payload['articles'] ?? [], 'id'), 'fallback should still return the type\'s published article');

        // And a real hit reports keyword_match=true.
        $hit = $client->getWithHeaders(
            '/api/kb-articles/match?enquiry_type=qualifying-question&q=' . urlencode($marker),
            ['X-Api-Key: ' . self::$operatorApiKeyRawToken]
        );
        $this->assertSame(200, $hit['status'], $hit['body']);
        $this->assertTrue(json_decode($hit['body'], true)['keyword_match']);
    }

    public function testMatchRequiresAnEnquiryType(): void
    {
        $client   = new HttpClient();
        $response = $client->getWithHeaders('/api/kb-articles/match?q=anything', ['X-Api-Key: ' . self::$operatorApiKeyRawToken]);

        $this->assertSame(422, $response['status']);
    }
}
