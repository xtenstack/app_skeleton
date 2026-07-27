<?php
declare(strict_types=1);

namespace App_skeleton;

/**
 * Debug-level messages, collected per-request for the debug bar and mirrored
 * to app.log — but only while debug_mode is on. Plain static class (no DI):
 * configure() is called once from bootstrap_web.php right after the
 * `settings` service is available, same shape as Audit::recordEvent()'s
 * static usage elsewhere in this codebase.
 */
class Debug
{
    private static bool $enabled = false;

    /** @var array<int, array{level: string, message: string}> */
    private static array $messages = [];

    public static function configure(bool $enabled): void
    {
        self::$enabled = $enabled;
    }

    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    public static function log(string $message, string $level = 'debug'): void
    {
        if (!self::$enabled) {
            return;
        }

        self::$messages[] = ['level' => $level, 'message' => $message];

        error_log(sprintf('[%s] %s', strtoupper($level), $message));
    }

    /**
     * @return array<int, array{level: string, message: string}>
     */
    public static function messages(): array
    {
        return self::$messages;
    }
}
