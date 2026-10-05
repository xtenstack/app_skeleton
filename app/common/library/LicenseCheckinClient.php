<?php
declare(strict_types=1);

namespace App_skeleton;

/**
 * The one outbound call licensing makes: POST <server>/api/lice/checkin
 * with {"key", "module"}, one request per module. The server answers
 * 200 {"valid": true} or 403 {"valid": false}, and deliberately gives
 * the same 403 for an unknown key, a revoked key and a key that does not
 * cover the module, so REJECTED carries no more detail than that.
 *
 * Never throws. Anything that is not one of those two answers (timeout,
 * DNS failure, a 5xx, a proxy's HTML error page, a redirect) is
 * UNREACHABLE: the server did not answer the question, which is not the
 * same as it answering no.
 */
class LicenseCheckinClient
{
    public const VALID       = 'valid';
    public const REJECTED    = 'rejected';
    public const UNREACHABLE = 'unreachable';

    public const DEFAULT_SERVER_URL = 'https://stack-internal.xten.au';

    private const CHECKIN_PATH = '/api/lice/checkin';

    private string $serverUrl;

    /**
     * Short timeouts on purpose: a check-in that cannot get through is
     * simply tried again another day.
     */
    public function __construct(
        string $serverUrl = '',
        private int $connectTimeoutSeconds = 3,
        private int $timeoutSeconds = 6
    ) {
        $serverUrl = rtrim(trim($serverUrl), '/');

        $this->serverUrl = $serverUrl !== '' ? $serverUrl : self::DEFAULT_SERVER_URL;
    }

    public function serverUrl(): string
    {
        return $this->serverUrl;
    }

    /**
     * HTTPS only, since the request body is the licence key. Plain HTTP
     * is accepted for a loopback address alone, which is what a test's
     * fake licence server listens on.
     */
    public function serverUrlIsAllowed(): bool
    {
        $parts  = parse_url($this->serverUrl);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host   = strtolower($parts['host'] ?? '');

        if ($host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        if ($scheme === 'https') {
            return true;
        }

        return $scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
    }

    /**
     * @return string one of VALID, REJECTED, UNREACHABLE
     */
    public function checkIn(string $key, string $moduleKey): string
    {
        if (!$this->serverUrlIsAllowed()) {
            error_log("LicenseCheckinClient: licence server URL '{$this->serverUrl}' is not HTTPS, not checking in");

            return self::UNREACHABLE;
        }

        if (!function_exists('curl_init')) {
            error_log('LicenseCheckinClient: the PHP curl extension is not installed, cannot check in');

            return self::UNREACHABLE;
        }

        $ch = curl_init($this->serverUrl . self::CHECKIN_PATH);

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['key' => $key, 'module' => $moduleKey]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_USERAGENT      => 'app_skeleton-license-checkin/1',
            CURLOPT_RETURNTRANSFER => true,
            // A redirect could hand the key to another host, or to plain HTTP.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
        ]);

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            error_log("LicenseCheckinClient: check-in for {$moduleKey} did not complete: {$error}");

            return self::UNREACHABLE;
        }

        $answer = json_decode((string) $body, true);
        $valid  = is_array($answer) ? ($answer['valid'] ?? null) : null;

        if ($status === 200 && $valid === true) {
            return self::VALID;
        }

        if ($status === 403 && $valid === false) {
            return self::REJECTED;
        }

        error_log("LicenseCheckinClient: check-in for {$moduleKey} got an unexpected answer (HTTP {$status})");

        return self::UNREACHABLE;
    }
}
