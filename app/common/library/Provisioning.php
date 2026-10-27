<?php
declare(strict_types=1);

namespace App_skeleton;

/**
 * The pure rules behind the admin-only provisioning API (MAA-20261027-008,
 * plan section 14): payload validation, key and password material, and the
 * rotation grace window. No Phalcon, no database, so the rules are
 * unit-testable alone; ProvisionController does the reading and writing.
 */
final class Provisioning
{
    public const KINDS = ['agent', 'person'];

    /** Roles the API will never grant: a new admin needs a human at the backend. */
    public const FORBIDDEN_ROLES = ['admin'];

    public const GRACE_DEFAULT = 600;

    public const GRACE_MIN = 60;

    public const GRACE_MAX = 3600;

    public const MAX_EXPIRY_DAYS = 3650;

    /** Users allowed to rotate ANY user's keys (Travis, 27 Oct 2026: Code and Travis only; Travis signs in as travissaron@ and stack@). */
    public const ROTATE_ANY_DEFAULT = ['claude.code@xten.au', 'travissaron@xten.au', 'stack@xten.au'];

    /**
     * @param array<string, mixed> $body
     * @return array{errors: string[], data: array{email: string, first_name: string, last_name: string, kind: string, role_names: string[], active: bool}}
     */
    public static function userPayload(array $body): array
    {
        $errors = [];

        $email = strtolower(trim((string) ($body['email'] ?? '')));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 150) {
            $errors[] = 'email must be a valid address of at most 150 characters';
        }

        $first = trim((string) ($body['first_name'] ?? ''));
        $last  = trim((string) ($body['last_name'] ?? ''));

        if ($first === '' || strlen($first) > 100) {
            $errors[] = 'first_name is required (at most 100 characters)';
        }

        if ($last === '' || strlen($last) > 100) {
            $errors[] = 'last_name is required (at most 100 characters)';
        }

        $kind = (string) ($body['kind'] ?? '');

        if (!in_array($kind, self::KINDS, true)) {
            $errors[] = "kind must be 'agent' or 'person'";
        }

        $names = [];

        foreach ((array) ($body['role_names'] ?? []) as $name) {
            $name = trim((string) $name);

            if ($name !== '' && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        if ($names === []) {
            $errors[] = 'role_names must name at least one role';
        }

        foreach ($names as $name) {
            if (in_array(strtolower($name), self::FORBIDDEN_ROLES, true)) {
                $errors[] = "role '{$name}' cannot be granted through the provisioning API";
            }
        }

        $active = array_key_exists('active', $body) ? filter_var($body['active'], FILTER_VALIDATE_BOOLEAN) : true;

        return [
            'errors' => $errors,
            'data'   => ['email' => $email, 'first_name' => $first, 'last_name' => $last, 'kind' => $kind, 'role_names' => $names, 'active' => $active],
        ];
    }

    /** A new API key in the existing `sk_` format: 48 hex characters after the prefix. */
    /**
     * Emails allowed to use rotate-keys: PROVISION_ROTATE_ANY_EMAILS (comma
     * separated) when set, else ROTATE_ANY_DEFAULT. Lowercased.
     *
     * @return list<string>
     */
    public static function rotateAnyEmails(?string $configured = null): array
    {
        $configured ??= (getenv('PROVISION_ROTATE_ANY_EMAILS') ?: '');
        $list = array_values(array_filter(array_map(fn ($e) => strtolower(trim($e)), explode(',', $configured))));

        return $list !== [] ? $list : self::ROTATE_ANY_DEFAULT;
    }

    public static function newToken(): string
    {
        return 'sk_' . bin2hex(random_bytes(24));
    }

    /**
     * A value that no password verifies against, for users who never log in
     * interactively (agents) or who set their own password later (people).
     * password_verify() returns false for anything that is not a hash.
     */
    public static function unusablePasswordHash(): string
    {
        return '!unusable-' . bin2hex(random_bytes(16));
    }

    /** Grace window in seconds for a rotation: the default, or the caller's value within bounds; null if out of bounds. */
    public static function graceSeconds(mixed $requested): ?int
    {
        if ($requested === null || $requested === '') {
            return self::GRACE_DEFAULT;
        }

        $seconds = filter_var($requested, FILTER_VALIDATE_INT);

        if ($seconds === false || $seconds < self::GRACE_MIN || $seconds > self::GRACE_MAX) {
            return null;
        }

        return $seconds;
    }

    /** expires_in_days as whole days within bounds, or null when absent; false when invalid. */
    public static function expiryDays(mixed $requested): int|false|null
    {
        if ($requested === null || $requested === '') {
            return null;
        }

        $days = filter_var($requested, FILTER_VALIDATE_INT);

        if ($days === false || $days < 1 || $days > self::MAX_EXPIRY_DAYS) {
            return false;
        }

        return $days;
    }
}
