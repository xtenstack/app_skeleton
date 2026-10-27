<?php
declare(strict_types=1);

use App_skeleton\Provisioning;
use PHPUnit\Framework\TestCase;

require_once APP_PATH . '/common/library/Provisioning.php';

/** MAA-20261027-008: the pure rules behind the provisioning API. */
final class ProvisioningTest extends TestCase
{
    private function body(array $over = []): array
    {
        return $over + ['email' => ' Tim.Agent@Xten.AU ', 'first_name' => 'Tim', 'last_name' => 'Agent', 'kind' => 'agent', 'role_names' => ['agent']];
    }

    public function testValidPayloadIsNormalised(): void
    {
        $r = Provisioning::userPayload($this->body(['role_names' => ['agent', 'operator', 'agent', ' ']]));

        self::assertSame([], $r['errors']);
        self::assertSame('tim.agent@xten.au', $r['data']['email']);
        self::assertSame(['agent', 'operator'], $r['data']['role_names'], 'order kept, blanks and duplicates dropped');
        self::assertTrue($r['data']['active'], 'active defaults to true');
    }

    public function testActiveCanBeSwitchedOff(): void
    {
        self::assertFalse(Provisioning::userPayload($this->body(['active' => false]))['data']['active']);
        self::assertFalse(Provisioning::userPayload($this->body(['active' => 'false']))['data']['active']);
    }

    /** @dataProvider badPayloads */
    public function testBadPayloadsAreRefused(array $over, string $needle): void
    {
        $r = Provisioning::userPayload($this->body($over));

        self::assertNotSame([], $r['errors']);
        self::assertStringContainsString($needle, implode(' | ', $r['errors']));
    }

    public static function badPayloads(): array
    {
        return [
            'no email'      => [['email' => ''], 'email'],
            'bad email'     => [['email' => 'not-an-email'], 'email'],
            'no first name' => [['first_name' => ''], 'first_name'],
            'no last name'  => [['last_name' => ' '], 'last_name'],
            'bad kind'      => [['kind' => 'robot'], 'kind'],
            'no roles'      => [['role_names' => []], 'role_names'],
            'admin role'    => [['role_names' => ['agent', 'admin']], "role 'admin' cannot be granted"],
            'Admin case'    => [['role_names' => ['Admin']], 'cannot be granted'],
        ];
    }

    public function testTokenFormatMatchesExistingKeys(): void
    {
        $a = Provisioning::newToken();

        self::assertMatchesRegularExpression('/^sk_[0-9a-f]{48}$/', $a);
        self::assertNotSame($a, Provisioning::newToken());
    }

    public function testUnusablePasswordNeverVerifies(): void
    {
        $hash = Provisioning::unusablePasswordHash();

        self::assertFalse(password_verify('', $hash));
        self::assertFalse(password_verify($hash, $hash));
        self::assertNotSame($hash, Provisioning::unusablePasswordHash());
    }

    public function testGraceWindow(): void
    {
        self::assertSame(600, Provisioning::graceSeconds(null));
        self::assertSame(600, Provisioning::graceSeconds(''));
        self::assertSame(60, Provisioning::graceSeconds(60));
        self::assertSame(3600, Provisioning::graceSeconds('3600'));
        self::assertNull(Provisioning::graceSeconds(59));
        self::assertNull(Provisioning::graceSeconds(3601));
        self::assertNull(Provisioning::graceSeconds('soon'));
    }

    public function testExpiryDays(): void
    {
        self::assertNull(Provisioning::expiryDays(null));
        self::assertSame(30, Provisioning::expiryDays('30'));
        self::assertFalse(Provisioning::expiryDays(0));
        self::assertFalse(Provisioning::expiryDays(3651));
        self::assertFalse(Provisioning::expiryDays('x'));
    }

    public function testRotateAnyListDefaultsToCodeAndTravisAndCanBeOverridden(): void
    {
        self::assertSame(['claude.code@xten.au', 'travissaron@xten.au'], Provisioning::rotateAnyEmails(''));
        self::assertSame(['a@x.y', 'b@x.y'], Provisioning::rotateAnyEmails(' A@x.y, b@X.y ,'));
    }
}
