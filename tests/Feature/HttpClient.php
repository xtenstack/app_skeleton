<?php
declare(strict_types=1);

/**
 * A tiny cookie-jar-aware HTTP client for the Feature tests — these hit
 * the actually-running stack (same instance the CI smoke test already
 * boots via `docker compose up -d --build`) rather than dispatching
 * in-process, deliberately: several controllers here call `exit;` after
 * an early-return response (ControllerBase's role/CSRF checks among
 * them, a normal and correct Phalcon pattern for a real request) — that
 * would kill the PHPUnit process itself if dispatched in-process instead
 * of over real HTTP, where `exit;` only ends that one PHP-FPM worker's
 * request the way it does in production. Base URL defaults to the
 * Docker Compose internal hostname (these tests are meant to run via
 * `docker compose exec app vendor/bin/phpunit`, see docs/CONTRIBUTING.md),
 * overridable via APP_TEST_BASE_URL for any other setup.
 */
final class HttpClient
{
    private string $baseUrl;
    private string $cookieJar;

    public function __construct()
    {
        $this->baseUrl   = rtrim(getenv('APP_TEST_BASE_URL') ?: 'http://caddy', '/');
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'app_skeleton_test_cookies_');
    }

    public function __destruct()
    {
        @unlink($this->cookieJar);
    }

    /** @return array{status: int, body: string} */
    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    /** @param array<string, string> $fields */
    public function post(string $path, array $fields): array
    {
        return $this->request('POST', $path, http_build_query($fields));
    }

    private function request(string $method, string $path, ?string $body = null): array
    {
        $ch = curl_init($this->baseUrl . $path);

        // CURLOPT_POST, not CURLOPT_CUSTOMREQUEST — confirmed directly
        // (a redirect loop, "Maximum (5) redirects followed"): CUSTOMREQUEST
        // forces that verb on every hop of a followed redirect chain, not
        // just the first request, unlike a real browser's (and CURLOPT_POST's)
        // standard 302-downgrades-to-GET behavior. A CSRF-rejected POST
        // here redirects to /backend, which redirects to /backend/session
        // — forced back to POST, enforceCsrf() runs again with still no
        // token, redirects again, forever.
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_COOKIEJAR      => $this->cookieJar,
            CURLOPT_COOKIEFILE     => $this->cookieJar,
            // Caddy routes on the Host header (docker/Caddyfile's site
            // block matches SITE_DOMAIN, "localhost" by default — see
            // the Session 13 CI fix) — the Docker Compose service name
            // "caddy" in $this->baseUrl gets the connection to the right
            // container, but without this override Caddy itself doesn't
            // recognize that Host and returns an empty response instead
            // of routing to the app (confirmed directly: Content-Length:
            // 0, not a connection failure — curl_exec() alone can't
            // distinguish that from a real empty page).
            CURLOPT_HTTPHEADER     => ['Host: ' . (getenv('APP_TEST_HOST') ?: 'localhost')],
        ];

        if ($method === 'POST') {
            $options[CURLOPT_POST]       = true;
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $options);

        $responseBody = curl_exec($ch);

        if ($responseBody === false) {
            throw new \RuntimeException('HTTP request failed: ' . curl_error($ch));
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => $responseBody];
    }

    /**
     * @return array{key: string, token: string}
     */
    public static function extractCsrf(string $html): array
    {
        preg_match('/csrf-key" content="([^"]*)"/', $html, $keyMatch);
        preg_match('/csrf-token" content="([^"]*)"/', $html, $tokenMatch);

        return ['key' => $keyMatch[1] ?? '', 'token' => $tokenMatch[1] ?? ''];
    }
}
