<?php
declare(strict_types=1);

namespace App_skeleton;

use Phalcon\Session\Manager;
use Phalcon\Session\ManagerInterface;

/**
 * A session that starts only when something needs it. Starting writes a
 * file (and sends a cookie) even if nothing is ever stored, so starting on
 * every request left one file behind for each crawler hit and each call to
 * a public endpoint (ticket 75).
 *
 * - A write (set, remove, regenerateId, setId, an explicit start) starts
 *   the session. That covers login, a CSRF token for a form, and flash
 *   messages.
 * - A read starts it only when the request brought a session cookie. With
 *   no cookie there is nothing to read, so it answers as an empty session
 *   without touching the disk.
 * - A stateless session (an API-key request) never starts: reads are
 *   empty and writes are dropped, as before.
 *
 * Once started it behaves exactly like Phalcon's own manager.
 *
 * @psalm-suppress MethodSignatureMismatch Phalcon's stubs type these
 * parameters as mixed; the real signatures are the narrower ones used here.
 */
class LazySession extends Manager
{
    private bool $stateless = false;

    /** Where session files live (bind-mounted from the host in docker-compose.yml). */
    /** @psalm-suppress UndefinedConstant BASE_PATH is defined by the bootstrap, not by a file Psalm reads. */
    public static function savePath(): string
    {
        return BASE_PATH . '/sessions';
    }

    /**
     * Refuse to ever start this session (see services.php: a request that
     * presents an API key has no session).
     */
    public function setStateless(bool $stateless): void
    {
        $this->stateless = $stateless;
    }

    public function start(): bool
    {
        if ($this->exists()) {
            return true;
        }

        if ($this->stateless) {
            return false;
        }

        // PHP's defaults send the session cookie bare: "PHPSESSID=…; path=/",
        // with no HttpOnly, Secure or SameSite attribute (seen on the live
        // instance 2026-10-05). HttpOnly keeps page script away from it,
        // SameSite=Lax keeps it off cross-site POSTs, and Secure keeps it off
        // plain HTTP. Secure is only set when this request arrived over HTTPS
        // (directly, or as the reverse proxy reports it), so a local
        // http://localhost install can still log in.
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            $https = ($_SERVER['HTTPS'] ?? '') === 'on'
                || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'secure'   => $https,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        return parent::start();
    }

    /** True when the session is open, starting it first if a read has something to find. */
    private function openForRead(): bool
    {
        if ($this->exists()) {
            return true;
        }

        return isset($_COOKIE[session_name()]) && $this->start();
    }

    /** True when the session is open, starting it for a write. */
    private function openForWrite(): bool
    {
        return $this->exists() || $this->start();
    }

    public function get(string $key, $defaultValue = null, bool $remove = false)
    {
        return $this->openForRead() ? parent::get($key, $defaultValue, $remove) : $defaultValue;
    }

    public function has(string $key): bool
    {
        return $this->openForRead() && parent::has($key);
    }

    public function set(string $key, $value): void
    {
        if ($this->openForWrite()) {
            parent::set($key, $value);
        }
    }

    public function remove(string $key): void
    {
        if ($this->openForRead()) {
            parent::remove($key);
        }
    }

    public function destroy(): void
    {
        if ($this->openForRead()) {
            parent::destroy();
        }
    }

    public function regenerateId(bool $deleteOldSession = true): ManagerInterface
    {
        if ($this->openForWrite()) {
            parent::regenerateId($deleteOldSession);
        }

        return $this;
    }

    public function setId(string $sessionId): ManagerInterface
    {
        if ($this->openForWrite()) {
            parent::setId($sessionId);
        }

        return $this;
    }

    public function __get(string $key)
    {
        return $this->get($key);
    }

    public function __set(string $key, $value): void
    {
        $this->set($key, $value);
    }

    public function __isset(string $key): bool
    {
        return $this->has($key);
    }

    public function __unset(string $key): void
    {
        $this->remove($key);
    }
}
