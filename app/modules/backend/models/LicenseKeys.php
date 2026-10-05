<?php
declare(strict_types=1);

use App_skeleton\Crypto;
use App_skeleton\Models\SoftDeletes;

/**
 * A licence key an admin has entered on this instance. key_encrypted is
 * ciphertext (App_skeleton\Crypto) and is only decrypted to send the key
 * to the licence server; key_hint (its last four characters) is all the
 * admin screens ever show.
 */
class LicenseKeys extends \Phalcon\Mvc\Model
{
    use SoftDeletes;

    private const HINT_LENGTH = 4;

    public $id;
    public $label;
    public $key_encrypted;
    public $key_hint;
    public $deleted_at;
    public $created_at;
    public $updated_at;

    public function initialize(): void
    {
        $this->setSource('license_keys');
        $this->keepSnapshots(true);
        // remove() blanks the key; without this the ORM reads '' in a
        // NOT NULL column as missing and refuses the save.
        $this->allowEmptyStringValues(['key_encrypted', 'key_hint']);
    }

    public function beforeSave(): void
    {
        $this->updated_at = date('Y-m-d H:i:s');
    }

    public function setKey(string $plaintext): void
    {
        $this->key_encrypted = Crypto::encrypt($plaintext);
        $this->key_hint      = substr($plaintext, -self::HINT_LENGTH);
    }

    public function revealKey(): ?string
    {
        return $this->key_encrypted ? Crypto::decrypt((string) $this->key_encrypted) : null;
    }

    public function masked(): string
    {
        return str_repeat('•', 8) . $this->key_hint;
    }

    /**
     * Kept out of audit_log (App_skeleton\Audit): a history row holding
     * the ciphertext would outlive remove() and be missed by
     * `./run crypto rekey`.
     *
     * @return string[]
     */
    public function auditRedactedFields(): array
    {
        return ['key_encrypted'];
    }

    /**
     * Soft-deleted like every other row, but the secret itself goes: a
     * removed key has no further use on this instance.
     */
    public function remove(): bool
    {
        $this->key_encrypted = '';

        return $this->softDelete();
    }
}
