<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * GET /api/docs/openapi: the generated OpenAPI description is served to
 * authenticated callers only, and what is served is a usable document.
 * Real HTTP against the running stack, like MissingIdNotFoundTest.
 */
final class ApiDocsTest extends TestCase
{
    private static int $userId;
    private static int $apiKeyId;
    private static string $apiToken;

    public static function setUpBeforeClass(): void
    {
        $di = new \Phalcon\Di\FactoryDefault();
        require APP_PATH . '/config/services.php';
        require APP_PATH . '/config/loader.php';
        \Phalcon\Di\Di::setDefault($di);

        $user                = new \Users();
        $user->email         = 'phpunit-apidocs-' . bin2hex(random_bytes(6)) . '@example.invalid';
        $user->password_hash = password_hash('PhpunitTest123!', PASSWORD_DEFAULT);
        $user->first_name    = 'PHPUnit';
        $user->last_name     = 'ApiDocs';
        $user->role_id       = 2; // member: any authenticated caller may read the description
        $user->is_active     = 1;
        self::assertTrue($user->save(), 'fixture user failed to save: ' . implode('; ', $user->getMessages()));
        self::$userId = (int) $user->id;

        self::$apiToken = 'phpunit-apidocs-' . bin2hex(random_bytes(16));

        $key               = new \ApiKeys();
        $key->user_id      = self::$userId;
        $key->name         = 'phpunit-apidocs-fixture-key';
        $key->token_hash   = hash('sha256', self::$apiToken);
        $key->token_prefix = substr(self::$apiToken, 0, 10);
        self::assertTrue($key->save(), 'fixture key failed to save: ' . implode('; ', $key->getMessages()));
        self::$apiKeyId = (int) $key->id;
    }

    public static function tearDownAfterClass(): void
    {
        \ApiKeys::findFirstById(self::$apiKeyId)?->delete();
        \Users::findFirstWithTrashed(['conditions' => 'id = :id:', 'bind' => ['id' => self::$userId]])?->softDelete();
    }

    public function testRequiresAuthentication(): void
    {
        $response = (new HttpClient())->get('/api/docs/openapi');

        $this->assertSame(401, $response['status']);
        $this->assertSame(['error' => 'Not authenticated'], json_decode($response['body'], true));
    }

    public function testServesAValidDocumentToAnApiKey(): void
    {
        $response = (new HttpClient())->exchange('GET', '/api/docs/openapi', ['X-Api-Key: ' . self::$apiToken]);

        $this->assertSame(200, $response['status']);

        $doc = json_decode($response['body'], true);
        $this->assertIsArray($doc, 'the description is not JSON');
        $this->assertStringStartsWith('3.0.', $doc['openapi']);
        $this->assertNotEmpty($doc['paths']);
        $this->assertArrayHasKey('ApiKeyAuth', $doc['components']['securitySchemes']);

        foreach ($doc['paths'] as $path => $item) {
            $this->assertStringStartsWith('/', $path);

            foreach ($item as $method => $op) {
                if (!in_array($method, ['get', 'post', 'put', 'patch', 'delete', 'options', 'head'], true)) {
                    continue;
                }

                $this->assertNotEmpty($op['operationId'] ?? null, strtoupper($method) . ' ' . $path . ' has no operationId');
                $this->assertNotEmpty($op['responses'] ?? null, strtoupper($method) . ' ' . $path . ' has no responses');
            }
        }
    }
}
