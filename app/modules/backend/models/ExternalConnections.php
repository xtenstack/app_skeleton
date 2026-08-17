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
}
