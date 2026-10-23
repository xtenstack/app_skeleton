<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A user holds a set of roles: users.role_id (the primary) plus the
 * user_roles rows. Users::roleIds() is the union, so a row written by a
 * path that only sets role_id still holds that role, and
 * CurrentPrincipal::hasAnyRole() is the one question every gate asks.
 * Real Postgres through the ORM, same as SoftDeleteTest.
 */
final class UserRolesTest extends TestCase
{
    private static string $email;

    public static function setUpBeforeClass(): void
    {
        $di = new \Phalcon\Di\FactoryDefault();
        require APP_PATH . '/config/services.php';
        require APP_PATH . '/config/loader.php';
        \Phalcon\Di\Di::setDefault($di);

        self::$email = 'phpunit-userroles-' . bin2hex(random_bytes(6)) . '@example.invalid';
    }

    public static function tearDownAfterClass(): void
    {
        $user = \Users::findFirstWithTrashed(['conditions' => 'email = :email:', 'bind' => ['email' => self::$email]]);

        if ($user instanceof \Users) {
            foreach (\UserRoles::find(['conditions' => 'user_id = :id:', 'bind' => ['id' => (int) $user->id]]) as $row) {
                $row->delete();
            }

            $user->softDelete();
        }
    }

    public function testRoleIdsIsTheUnionOfThePrimaryAndUserRoles(): void
    {
        [$member, $operator] = [\Roles::idsByNames(['member'])[0], \Roles::idsByNames(['operator'])[0]];

        $user                = new \Users();
        $user->email         = self::$email;
        $user->password_hash = password_hash('PhpunitTest123!', PASSWORD_DEFAULT);
        $user->first_name    = 'PHPUnit';
        $user->last_name     = 'UserRoles';
        $user->role_id       = $member;
        $user->is_active     = 1;
        $this->assertTrue($user->save(), implode('; ', $user->getMessages()));

        $this->assertSame([$member], $user->roleIds(), 'a user with only role_id set holds exactly that role');

        $this->assertTrue(\UserRoles::syncForUser((int) $user->id, [$member, $operator]));
        $expected = [$member, $operator];
        sort($expected);
        $this->assertSame($expected, $user->roleIds());

        // Removing the primary from user_roles does not remove the role:
        // role_id itself still grants it.
        $this->assertTrue(\UserRoles::syncForUser((int) $user->id, [$operator]));
        $this->assertSame($expected, $user->roleIds());
        $this->assertSame([$operator], \UserRoles::roleIdsForUser((int) $user->id));

        $this->assertTrue(\UserRoles::syncForUser((int) $user->id, []));
        $this->assertSame([$member], $user->roleIds());
    }

    public function testCurrentPrincipalFallsBackToThePrimaryRole(): void
    {
        $principal = new \App_skeleton\CurrentPrincipal();

        $principal->set(1, 2);
        $this->assertSame([2], $principal->roleIds());
        $this->assertTrue($principal->hasAnyRole([2, 3]));
        $this->assertFalse($principal->hasAnyRole([1]));
        $this->assertFalse($principal->hasAnyRole([]));

        $principal->set(1, 2, null, [2, 4, 4]);
        $this->assertSame([2, 4], $principal->roleIds());
        $this->assertTrue($principal->hasAnyRole([4]));
        $this->assertSame(2, $principal->roleId());

        $principal->clear();
        $this->assertSame([], $principal->roleIds());
        $this->assertFalse($principal->hasAnyRole([2]));
    }

    public function testRoleIdsFromAnOlderSessionIsTheOneRole(): void
    {
        $this->assertSame([3], \App_skeleton\Auth::roleIdsFrom(['id' => 1, 'role_id' => 3]));
        $this->assertSame([3, 5], \App_skeleton\Auth::roleIdsFrom(['id' => 1, 'role_id' => 3, 'role_ids' => ['3', 5]]));
        $this->assertSame([], \App_skeleton\Auth::roleIdsFrom([]));
    }
}
