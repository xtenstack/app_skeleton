<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Cli\Tasks;

/**
 * Usage: ./run create-agent run --email=tim.claude.cowork@xten.au --first-name=Tim --last-name="(SSA Agent)"
 *
 * Provisions a service account (human or AI) with an API key — pulled
 * out of a one-off script written for Tim, the SSA agent (2026-09-05,
 * first case), on Travis's note that this will likely grow to cover
 * provisioning across other systems too. This task only covers
 * app_skeleton's own Users/ApiKeys tables; it does not touch Dolibarr,
 * SSH, or anything else — a natural place to extend if/when that's
 * needed, not something this task assumes today.
 *
 * Idempotent on email: re-running reuses the existing user (same
 * pattern as SeedTask) but always issues a fresh API key, matching
 * ApiKeysController::createAction() — keys are never silently reused,
 * only ever added or revoked.
 */
class CreateAgentTask extends \Phalcon\Cli\Task
{
    public function mainAction(): void
    {
        echo 'Usage: ./run create-agent run --email=... --first-name=... --last-name=... [--role=agent]' . PHP_EOL;
    }

    public function runAction(...$params): void
    {
        $args = $this->parseArgs($params);

        $email     = $args['email'] ?? null;
        $firstName = $args['first-name'] ?? null;
        $lastName  = $args['last-name'] ?? null;
        $roleName  = $args['role'] ?? 'agent';

        if (!$email || !$firstName || !$lastName) {
            fwrite(STDERR, 'ERROR: --email, --first-name, and --last-name are all required.' . PHP_EOL);
            exit(1);
        }

        $roleId = \Roles::idsByNames([$roleName])[0] ?? null;

        if ($roleId === null) {
            fwrite(STDERR, "ERROR: role '{$roleName}' does not exist — run ./run seed run first, or check spelling." . PHP_EOL);
            exit(1);
        }

        $user = \Users::findFirst(['conditions' => 'email = :email:', 'bind' => ['email' => $email]]);

        // \Users::findFirst() is typed against Phalcon's own ModelInterface,
        // not the concrete Users class — narrow explicitly so ->id/->role_id
        // below resolve as real properties, not an interface access (same
        // pattern already used in PublicIntakeController).
        if ($user instanceof \Users) {
            echo "User already exists: id={$user->id}, role_id={$user->role_id}" . PHP_EOL;
        } else {
            $user             = new \Users();
            $user->email      = $email;
            $user->first_name = $firstName;
            $user->last_name  = $lastName;
            $user->role_id    = $roleId;
            $user->is_active  = 1;
            // Service account — auth is via API key only, so this is a
            // random value nobody knows, same spirit as an OAuth-only
            // account with no usable password.
            $user->password_hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);

            if (!$user->save()) {
                fwrite(STDERR, 'ERROR creating user: ' . implode(', ', $user->getMessages()) . PHP_EOL);
                exit(1);
            }
            echo "Created user: id={$user->id}" . PHP_EOL;
        }

        $raw = 'sk_' . bin2hex(random_bytes(24));

        $apiKey               = new \ApiKeys();
        $apiKey->user_id      = $user->id;
        $apiKey->name         = $firstName . ' ' . $lastName . ' key';
        $apiKey->token_hash   = hash('sha256', $raw);
        $apiKey->token_prefix = substr($raw, 0, 10);

        if (!$apiKey->save()) {
            fwrite(STDERR, 'ERROR creating API key: ' . implode(', ', $apiKey->getMessages()) . PHP_EOL);
            exit(1);
        }

        echo "Created API key: id={$apiKey->id}" . PHP_EOL;
        echo "RAW_TOKEN={$raw}" . PHP_EOL;
        echo '(shown once — only the hash is stored, same as ApiKeysController::createAction())' . PHP_EOL;
    }

    /**
     * Phalcon CLI params arrive as a flat list ('--email=foo@bar.com',
     * ...), not pre-parsed — this is the same shape every other
     * multi-flag CLI task in this codebase would need, just not
     * factored out anywhere reusable yet.
     */
    private function parseArgs(array $params): array
    {
        $args = [];

        foreach ($params as $param) {
            if (str_starts_with($param, '--') && str_contains($param, '=')) {
                [$key, $value] = explode('=', substr($param, 2), 2);
                $args[$key]    = $value;
            }
        }

        return $args;
    }
}
