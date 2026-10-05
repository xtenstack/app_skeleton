<?php
declare(strict_types=1);

namespace App_skeleton;

use Phalcon\Di\Injectable;

/**
 * Who this request is acting as, however it authenticated. Two things set
 * it: Auth, for a browser session's user, and ApiKeyAuth, for the user an
 * API key belongs to. Everything that needs "the acting user" outside a
 * controller (Audit, the error log) reads it from here and never from the
 * session, so an API-key request needs no session at all.
 *
 * A CLI task has no principal: nothing sets one, and the session is never
 * consulted there.
 */
class CurrentPrincipal extends Injectable
{
    private ?int $userId = null;

    private ?int $roleId = null;

    private ?int $apiKeyId = null;

    private bool $resolved = false;

    public function set(int $userId, int $roleId, ?int $apiKeyId = null): void
    {
        $this->userId   = $userId;
        $this->roleId   = $roleId;
        $this->apiKeyId = $apiKeyId;
        $this->resolved = true;
    }

    public function clear(): void
    {
        $this->userId   = null;
        $this->roleId   = null;
        $this->apiKeyId = null;
        $this->resolved = true;
    }

    public function userId(): ?int
    {
        $this->resolve();

        return $this->userId;
    }

    public function roleId(): ?int
    {
        $this->resolve();

        return $this->roleId;
    }

    /**
     * The key this request authenticated with, or null for a browser
     * session (and for no principal at all).
     */
    public function apiKeyId(): ?int
    {
        $this->resolve();

        return $this->apiKeyId;
    }

    /**
     * Nothing has set a principal yet: give the session path its one
     * chance. Auth::isLoggedIn() sets it when the session has a live user.
     * Controllers that gate on a login have already done this; it matters
     * for the ones that don't (signup, a public page) but can still be
     * visited by someone who is logged in.
     */
    private function resolve(): void
    {
        if ($this->resolved) {
            return;
        }

        $this->resolved = true;

        if (PHP_SAPI !== 'cli') {
            $this->getDI()->getShared('auth')->isLoggedIn();
        }
    }
}
