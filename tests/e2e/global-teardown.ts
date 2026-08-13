import { execSync } from 'child_process';

// No self-service account-deletion endpoint exists (deliberately --
// see RbacTest.php's own PHPUnit coverage comment), so fixture cleanup
// can't go through HTTP the way the tests themselves do. Same pattern
// the PHPUnit Feature tests use (direct DB access in tearDownAfterClass),
// adapted here since Playwright has no PHP/ORM access -- shells out to
// psql, matching how this project already verifies state throughout.
// Soft-deletes, not a hard DELETE, consistent with SoftDeletes being
// the only sanctioned removal path for a `users` row (CODING-STANDARDS.md).
export default async function globalTeardown() {
  try {
    execSync(
      `psql -U app_skeleton -d app_skeleton -h localhost -c "UPDATE users SET deleted_at = NOW() WHERE email LIKE 'playwright-%@example.invalid' AND deleted_at IS NULL;"`,
      { stdio: 'pipe' },
    );
  } catch {
    // Best-effort — CI's ephemeral DB is torn down with the whole stack
    // regardless (docker compose down -v), so a failure here isn't fatal,
    // just leaves local fixture rows for the next run to catch instead.
  }
}
