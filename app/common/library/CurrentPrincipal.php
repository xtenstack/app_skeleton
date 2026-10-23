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

    /** @var int[] */
    private array $roleIds = [];

    private ?int $apiKeyId = null;

    private bool $resolved = false;

    /**
     * $roleIds is every role the user holds (Users::roleIds()); $roleId is
     * the primary. An empty $roleIds means "just the primary", so a caller
     * that knows only the one role (an older module) still sets a usable set.
     *
     * @param int[] $roleIds
     */
    public function set(int $userId, int $roleId, ?int $apiKeyId = null, array $roleIds = []): void
    {
        $this->userId   = $userId;
        $this->roleId   = $roleId;
        $this->roleIds  = array_values(array_unique(array_map('intval', $roleIds ?: [$roleId])));
        $this->apiKeyId = $apiKeyId;
        $this->resolved = true;
    }

    public function clear(): void
    {
        $this->userId   = null;
        $this->roleId   = null;
        $this->roleIds  = [];
        $this->apiKeyId = null;
        $this->resolved = true;
    }

    public function userId(): ?int
    {
        $this->resolve();

        return $this->userId;
    }

    /**
     * The primary role (users.role_id). Gates want roleIds()/hasAnyRole();
     * this is for what still needs one role, such as the Users list.
     */
    public function roleId(): ?int
    {
        $this->resolve();

        return $this->roleId;
    }

    /**
     * Every role the principal holds, or [] with no principal.
     *
     * @return int[]
     */
    public function roleIds(): array
    {
        $this->resolve();

        return $this->roleIds;
    }

    /**
     * Whether the principal holds at least one of $roleIds — the one
     * question every $allowedRoles gate, menu item and tile asks.
     *
     * @param int[] $roleIds
     */
    public function hasAnyRole(array $roleIds): bool
    {
        return array_intersect(array_map('intval', $roleIds), $this->roleIds()) !== [];
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
