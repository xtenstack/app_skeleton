<?php
declare(strict_types=1);

/**
 * Starts tests/fixtures/license-server/router.php under PHP's built-in
 * server on a free loopback port, and lets a test set what it knows and
 * read back what it was sent. This is the only licence server a test
 * ever talks to: every client under test is built with url().
 */
final class FakeLicenseServer
{
    /** @var resource */
    private $process;

    private function __construct(private string $dir, private int $port, $process)
    {
        $this->process = $process;
    }

    public static function start(): self
    {
        $dir = sys_get_temp_dir() . '/app_skeleton_fake_license_' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/state.json', '{}');
        file_put_contents($dir . '/requests.log', '');

        $port = self::freePort();

        // One process and no workers, so stop() can end it with a single
        // signal and nothing is left listening after the test run.
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/router.php'],
            [['file', '/dev/null', 'r'], ['file', $dir . '/server.log', 'a'], ['file', $dir . '/server.log', 'a']],
            $pipes,
            null,
            ['FAKE_LICENSE_DIR' => $dir, 'PATH' => (string) getenv('PATH')]
        );

        if (!is_resource($process)) {
            throw new \RuntimeException('could not start the fake licence server');
        }

        $server = new self($dir, $port, $process);

        for ($i = 0; $i < 100; $i++) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);

            if ($connection !== false) {
                fclose($connection);

                return $server;
            }

            usleep(50000);
        }

        $server->stop();

        throw new \RuntimeException('the fake licence server did not start listening');
    }

    /**
     * A loopback port nothing is listening on (also what a test points a
     * client at to see a refused connection).
     */
    public static function freePort(): int
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $port  = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        return $port;
    }

    public function url(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }

    /**
     * @param array<string, string[]> $keys active key => module codes it covers
     * @param string[]                $revoked keys the server knows but has revoked
     */
    public function know(array $keys, array $revoked = [], string $mode = 'normal'): void
    {
        $state = ['mode' => $mode, 'keys' => []];

        foreach ($keys as $key => $modules) {
            $state['keys'][$key] = ['status' => in_array($key, $revoked, true) ? 'revoked' : 'active', 'modules' => $modules];
        }

        file_put_contents($this->dir . '/state.json', json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX);
    }

    public function mode(string $mode): void
    {
        $state         = json_decode((string) file_get_contents($this->dir . '/state.json'), true) ?: [];
        $state['mode'] = $mode;

        file_put_contents($this->dir . '/state.json', json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX);
    }

    /**
     * @return list<array{method: string, path: string, key: ?string, module: ?string}>
     */
    public function requests(): array
    {
        $lines = array_filter(explode("\n", (string) file_get_contents($this->dir . '/requests.log')));

        return array_values(array_map(static fn (string $line): array => json_decode($line, true), $lines));
    }

    /**
     * Blocks until the server is answering again. It handles one request
     * at a time, so after the "slow" mode has made a client give up, the
     * abandoned request is still sleeping and the next one would queue
     * behind it and time out too.
     */
    public function waitUntilIdle(): void
    {
        $ch = curl_init($this->url() . '/idle-probe');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
        curl_exec($ch);
        curl_close($ch);
    }

    public function forgetRequests(): void
    {
        file_put_contents($this->dir . '/requests.log', '', LOCK_EX);
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }

        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }
}
