<?php
declare(strict_types=1);

use App_skeleton\Crypto;
use App_skeleton\Models\SoftDeletes;

/**
 * An outbound connection to a third-party API — distinct from ApiKeys,
 * which are credentials *other* systems use to call us. credential is
 * encrypted at rest via App_skeleton\Crypto and only ever decrypted
 * server-side when explicitly requested (see revealCredential()).
 */
class ExternalConnections extends \Phalcon\Mvc\Model
{
    use SoftDeletes;

    public const AUTH_TYPES = ['none', 'api_key', 'basic', 'oauth2'];

    public $id;
    public $name;
    public $base_url;
    public $auth_type;
    public $credential;
    public $config;
    public $is_active;
    public $deleted_at;
    public $created_at;
    public $updated_at;

    public function initialize(): void
    {
        $this->setSource('external_connections');
        $this->keepSnapshots(true);
    }

    public function beforeSave(): void
    {
        $this->updated_at = date('Y-m-d H:i:s');
    }

    /**
     * Call with a plaintext secret from a form submission; stores it
     * encrypted. Pass null/empty to leave the existing credential
     * untouched (e.g. editing other fields without re-entering the secret).
     */
    public function setCredential(?string $plaintext): void
    {
        if ($plaintext === null || $plaintext === '') {
            return;
        }

        $this->credential = Crypto::encrypt($plaintext);
    }

    public function revealCredential(): ?string
    {
        return $this->credential ? Crypto::decrypt($this->credential) : null;
    }

    /**
     * The standard lookup every module/library is meant to use for a
     * third-party credential (see MODULE-SPEC.md "External Credentials")
     * — case-insensitive on `name` (migration 019 enforces uniqueness on
     * that), and only ever returns an active row. Returns null rather
     * than throwing when nothing's configured yet — every caller of this
     * already has to handle "not configured" gracefully (matches
     * Mailer's own pre-existing not-configured posture), not a state
     * worth a hard failure over.
     */
    public static function findActiveByName(string $name): ?self
    {
        return self::findFirst([
            'conditions' => 'LOWER(name) = :name: AND is_active = 1',
            'bind'       => ['name' => strtolower($name)],
        ]);
    }
}
