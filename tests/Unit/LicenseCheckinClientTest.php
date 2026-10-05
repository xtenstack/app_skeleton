<?php
declare(strict_types=1);

use App_skeleton\LicenseCheckinClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../fixtures/license-server/FakeLicenseServer.php';

/**
 * The check-in call itself (docs/MODULE-SPEC.md, Licensing), as real HTTP
 * against a fake licence server on a loopback port: what is sent, how the
 * two real answers are read, and that everything else is "unreachable"
 * rather than an exception or a false yes. No test here, or anywhere,
 * calls the real licence server.
 */
final class LicenseCheckinClientTest extends TestCase
{
    private static FakeLicenseServer $server;

    private string $previousErrorLog;

    private string $errorLog;

    public static function setUpBeforeClass(): void
    {
        self::$server = FakeLicenseServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    protected function setUp(): void
    {
        self::$server->know(['good-key' => ['lictest_app', 'lictest_chat'], 'revoked-key' => ['lictest_app']], ['revoked-key']);
        self::$server->waitUntilIdle();
        self::$server->forgetRequests();

        $this->errorLog         = (string) tempnam(sys_get_temp_dir(), 'app_skeleton_lictest_log_');
        $this->previousErrorLog = (string) ini_set('error_log', $this->errorLog);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog);
        unlink($this->errorLog);
    }

    public function testAValidAnswerCarriesTheLastDayCoveredWhenTheServerGivesOne(): void
    {
        $client = $this->client();

        self::assertSame(LicenseCheckinClient::VALID, $client->checkIn('good-key', 'lictest_app'));
        self::assertNull($client->lastExpiresOn(), 'no expiry on the key');

        $lastDay = gmdate('Y-m-d', time() + 20 * 86400);
        self::$server->know(['good-key' => ['lictest_app']], [], 'normal', ['good-key' => $lastDay]);

        self::assertSame(LicenseCheckinClient::VALID, $client->checkIn('good-key', 'lictest_app'));
        self::assertSame($lastDay, $client->lastExpiresOn());

        // Past its last day the server answers as for an unknown key, and
        // the date of an earlier answer is not kept.
        self::$server->know(['good-key' => ['lictest_app']], [], 'normal', ['good-key' => gmdate('Y-m-d', time() - 2 * 86400)]);

        self::assertSame(LicenseCheckinClient::REJECTED, $client->checkIn('good-key', 'lictest_app'));
        self::assertNull($client->lastExpiresOn());
    }

    public function testAnActiveKeyThatCoversTheModuleIsValidAndOneRequestIsSent(): void
    {
        self::assertSame(LicenseCheckinClient::VALID, $this->client()->checkIn('good-key', 'lictest_chat'));

        self::assertSame(
            [['method' => 'POST', 'path' => '/api/lice/checkin', 'key' => 'good-key', 'module' => 'lictest_chat']],
            self::$server->requests()
        );
    }

    #[DataProvider('refusals')]
    public function testEveryKindOfRefusalReadsTheSame(string $key, string $module): void
    {
        self::assertSame(LicenseCheckinClient::REJECTED, $this->client()->checkIn($key, $module));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function refusals(): array
    {
        return [
            'a key the server has never heard of' => ['no-such-key', 'lictest_app'],
            'a revoked key'                       => ['revoked-key', 'lictest_app'],
            'a module the key does not cover'     => ['good-key', 'lictest_solo'],
        ];
    }

    #[DataProvider('nonAnswers')]
    public function testAnythingThatIsNotAnAnswerIsUnreachableNotAnException(string $mode): void
    {
        self::$server->mode($mode);

        self::assertSame(LicenseCheckinClient::UNREACHABLE, $this->client()->checkIn('good-key', 'lictest_app'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonAnswers(): array
    {
        return [
            'a 500'                               => ['error500'],
            'a proxy error page with status 200'  => ['garbage'],
            'a redirect (not followed)'           => ['redirect'],
            'a slow server (timeout)'             => ['slow'],
            '"valid": true on a failed status'    => ['valid-with-500'],
        ];
    }

    public function testARedirectIsNotFollowedSoTheKeyGoesNowhereElse(): void
    {
        self::$server->mode('redirect');

        $this->client()->checkIn('good-key', 'lictest_app');

        self::assertSame(['/api/lice/checkin'], array_column(self::$server->requests(), 'path'));
    }

    public function testNothingListeningIsUnreachable(): void
    {
        $client = new LicenseCheckinClient('http://127.0.0.1:' . FakeLicenseServer::freePort(), 1, 2);

        self::assertSame(LicenseCheckinClient::UNREACHABLE, $client->checkIn('good-key', 'lictest_app'));
    }

    #[DataProvider('serverUrls')]
    public function testOnlyHttpsOrLoopbackHttpIsAnAllowedServer(string $url, bool $allowed): void
    {
        self::assertSame($allowed, (new LicenseCheckinClient($url))->serverUrlIsAllowed());
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function serverUrls(): array
    {
        return [
            'https'                         => ['https://licences.example.invalid', true],
            'https with a path'             => ['https://example.invalid/licensing/', true],
            'http on localhost'             => ['http://localhost:8080', true],
            'http on 127.0.0.1'             => ['http://127.0.0.1:18126', true],
            'http on ::1'                   => ['http://[::1]:8080', true],
            'http on a public host'         => ['http://licences.example.invalid', false],
            'http on a localhost lookalike' => ['http://localhost.example.invalid', false],
            'credentials in the URL'        => ['https://user:secret@example.invalid', false],
            'another scheme'                => ['ftp://example.invalid', false],
            'not a URL'                     => ['licences.example.invalid', false],
        ];
    }

    public function testAPlainHttpServerIsRefusedBeforeAnythingIsSent(): void
    {
        $client = new LicenseCheckinClient('http://lictest.invalid', 1, 2);

        self::assertSame(LicenseCheckinClient::UNREACHABLE, $client->checkIn('good-key', 'lictest_app'));
        self::assertStringContainsString('is not HTTPS', (string) file_get_contents($this->errorLog));
        self::assertStringNotContainsString('good-key', (string) file_get_contents($this->errorLog));
    }

    public function testAnEmptyConfiguredUrlMeansTheDefaultServer(): void
    {
        self::assertSame(LicenseCheckinClient::DEFAULT_SERVER_URL, (new LicenseCheckinClient(''))->serverUrl());
        self::assertStringStartsWith('https://', LicenseCheckinClient::DEFAULT_SERVER_URL);
        self::assertSame('https://example.invalid', (new LicenseCheckinClient(' https://example.invalid/ '))->serverUrl());
    }

    private function client(): LicenseCheckinClient
    {
        return new LicenseCheckinClient(self::$server->url(), 1, 2);
    }
}
