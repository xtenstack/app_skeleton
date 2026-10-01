<?php
declare(strict_types=1);

namespace App_skeleton;

/**
 * Symmetric encryption for secrets that (unlike api_keys' one-way hash)
 * have to be recoverable later — e.g. external_connections.credential,
 * which we need in plaintext to actually call the third-party API.
 *
 * The key lives in a project-local, gitignored file rather than requiring
 * an env-file mechanism this skeleton doesn't have yet (see
 * project-app-skeleton-architecture memory re: settings-in-lifecycle).
 * Generated on first use with 0600 permissions. Losing this file makes
 * every encrypted value permanently unrecoverable — back it up separately
 * from the database if that data matters.
 *
 * The key must be exactly 32 bytes. An empty file used to be accepted
 * silently — and the install docs said `touch data/encryption_key`, so
 * every Docker install that followed them encrypted with an empty key
 * (OpenSSL zero-pads it: a key anyone can reproduce). Found on prod
 * 2026-10-01. A wrong-length key now throws; `./run crypto rekey` moves an
 * instance from its current key (empty included) to a fresh one.
 */
class Crypto
{
    private const CIPHER = 'aes-256-gcm';
    public const KEY_BYTES = 32;

    /** Overridable for tests and for CryptoTask; null = BASE_PATH/.encryption_key. */
    public static ?string $keyPath = null;

    public static function encrypt(string $plaintext): string
    {
        return self::encryptWithKey($plaintext, self::key());
    }

    public static function decrypt(string $encoded): ?string
    {
        return self::decryptWithKey($encoded, self::key());
    }

    public static function encryptWithKey(string $plaintext, string $key): string
    {
        $iv         = random_bytes(openssl_cipher_iv_length(self::CIPHER));
        $tag        = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);

        return base64_encode($iv . $tag . $ciphertext);
    }

    public static function decryptWithKey(string $encoded, string $key): ?string
    {
        $raw = base64_decode($encoded, true);

        if ($raw === false) {
            return null;
        }

        $ivLength   = openssl_cipher_iv_length(self::CIPHER);
        $iv         = substr($raw, 0, $ivLength);
        $tag        = substr($raw, $ivLength, 16);
        $ciphertext = substr($raw, $ivLength + 16);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);

        return $plaintext === false ? null : $plaintext;
    }

    public static function keyPath(): string
    {
        return self::$keyPath ?? BASE_PATH . '/.encryption_key';
    }

    /**
     * The key file's raw contents with no validation — only for
     * CryptoTask, which has to read a legacy empty key in order to
     * replace it. Null if the file is missing or unreadable.
     */
    public static function readRawKey(): ?string
    {
        $path = self::keyPath();

        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);

        return $raw === false ? null : $raw;
    }

    private static function key(): string
    {
        $path = self::keyPath();

        if (!file_exists($path)) {
            file_put_contents($path, random_bytes(self::KEY_BYTES));
            chmod($path, 0600);
        }

        $key = is_file($path) ? file_get_contents($path) : false;

        if ($key === false || strlen($key) !== self::KEY_BYTES) {
            throw new \RuntimeException(sprintf(
                'Encryption key %s is %s — it must be exactly %d bytes. Run `./run crypto rekey` (see docs/INSTALL.md).',
                $path,
                $key === false ? 'not a readable file' : strlen($key) . ' bytes',
                self::KEY_BYTES
            ));
        }

        return $key;
    }
}
