<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The session cookie must carry HttpOnly and SameSite. (It also carries
 * Secure over HTTPS; the test stack is plain HTTP and the proxy replaces
 * any forwarded-protocol header a client sends, so that half is checked
 * on a deployed instance, not here.) PHP's own defaults set none of them, and nothing fails when
 * they are missing, so this is the only thing that would notice a
 * regression in app/config/services.php. Real HTTP, like the other
 * Feature tests; HttpClient doesn't expose response headers, so this
 * makes its own request the same way.
 */
final class SessionCookieTest extends TestCase
{
    private function sessionCookie(): string
    {
        $ch = curl_init((getenv('APP_TEST_BASE_URL') ?: 'http://caddy') . '/backend/session');

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_HTTPHEADER     => ['Host: ' . (getenv('APP_TEST_HOST') ?: 'localhost')],
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $this->assertIsString($response, 'the login page did not answer');

        foreach (preg_split('/\r?\n/', $response) as $line) {
            if (stripos($line, 'Set-Cookie: PHPSESSID=') === 0) {
                return $line;
            }
        }

        $this->fail('the login page set no PHPSESSID cookie');
    }

    public function testSessionCookieIsHttpOnlyAndSameSiteLax(): void
    {
        $cookie = $this->sessionCookie();

        $this->assertMatchesRegularExpression('/;\s*HttpOnly/i', $cookie);
        $this->assertMatchesRegularExpression('/;\s*SameSite=Lax/i', $cookie);
    }
}
