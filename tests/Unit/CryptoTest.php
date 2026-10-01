<?php
declare(strict_types=1);

use App_skeleton\Crypto;
use PHPUnit\Framework\TestCase;

/**
 * Key handling after the 2026-10-01 empty-key finding: Crypto must never
 * silently encrypt with an empty or wrong-sized key, and must still be
 * able to read values written under a legacy empty key (that's what
 * `./run crypto rekey` relies on).
 */
final class CryptoTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir      = sys_get_temp_dir() . '/crypto-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        Crypto::$keyPath = $this->dir . '/.encryption_key';
    }

    protected function tearDown(): void
    {
        Crypto::$keyPath = null;
        @unlink($this->dir . '/.encryption_key');
        @rmdir($this->dir);
    }

    public function testMissingKeyIsGenerated32BytesAndPrivate(): void
    {
        $this->assertSame('secret', Crypto::decrypt(Crypto::encrypt('secret')));
        $this->assertSame(32, filesize(Crypto::$keyPath));
        $this->assertSame('0600', substr(sprintf('%o', fileperms(Crypto::$keyPath)), -4));
    }

    public function testEmptyKeyFileIsRefused(): void
    {
        touch(Crypto::$keyPath);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('0 bytes');
        Crypto::encrypt('secret');
    }

    public function testWrongLengthKeyIsRefusedOnDecryptToo(): void
    {
        file_put_contents(Crypto::$keyPath, str_repeat('k', 16));

        $this->expectException(\RuntimeException::class);
        Crypto::decrypt('anything');
    }

    public function testLegacyEmptyKeyValuesCanStillBeReadForRekey(): void
    {
        $legacy = Crypto::encryptWithKey('old secret', '');
        $newKey = random_bytes(Crypto::KEY_BYTES);

        $this->assertSame('old secret', Crypto::decryptWithKey($legacy, ''));
        $this->assertNull(Crypto::decryptWithKey($legacy, $newKey));

        $moved = Crypto::encryptWithKey((string) Crypto::decryptWithKey($legacy, ''), $newKey);
        $this->assertSame('old secret', Crypto::decryptWithKey($moved, $newKey));
        $this->assertNull(Crypto::decryptWithKey($moved, ''));
    }

    public function testReadRawKeyReportsTheFileAsIs(): void
    {
        $this->assertNull(Crypto::readRawKey());
        touch(Crypto::$keyPath);
        $this->assertSame('', Crypto::readRawKey());
    }
}
