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
 */
class Crypto
{
    private const CIPHER = 'aes-256-gcm';
    private const KEY_FILE = BASE_PATH . '/.encryption_key';

    public static function encrypt(string $plaintext): string
    {
        $key   = self::key();
        $iv    = random_bytes(openssl_cipher_iv_length(self::CIPHER));
        $tag   = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);

        return base64_encode($iv . $tag . $ciphertext);
    }

    public static function decrypt(string $encoded): ?string
    {
        $raw = base64_decode($encoded, true);

        if ($raw === false) {
            return null;
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv         = substr($raw, 0, $ivLength);
        $tag        = substr($raw, $ivLength, 16);
        $ciphertext = substr($raw, $ivLength + 16);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);

        return $plaintext === false ? null : $plaintext;
    }

    private static function key(): string
    {
        if (!file_exists(self::KEY_FILE)) {
            file_put_contents(self::KEY_FILE, random_bytes(32));
            chmod(self::KEY_FILE, 0600);
        }

        return file_get_contents(self::KEY_FILE);
    }
}
