import { test, expect } from '@playwright/test';

// Signs up a fresh throwaway account rather than depending on a
// pre-seeded fixture user — CI's SeedTask only seeds roles/settings/cron
// jobs, no users (see app/modules/cli/tasks/SeedTask.php), and this
// keeps the test self-contained the same way RbacTest.php's own
// self-service signup does for the equivalent PHPUnit coverage.
test('a new member can sign up, log in, and land on the dashboard', async ({ page }) => {
  const email = `playwright-${Date.now()}-${Math.random().toString(16).slice(2)}@example.invalid`;
  const password = 'PlaywrightTest123!';

  await page.goto('/backend/signup');
  await page.fill('input[name="first_name"]', 'Playwright');
  await page.fill('input[name="last_name"]', 'E2E');
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', password);
  await page.click('button[type="submit"]');

  // Signup doesn't auto-authenticate (same behavior RbacTest.php's own
  // comment documents against the real running app) -- log in explicitly.
  await page.goto('/backend/session');
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', password);
  await page.click('button[type="submit"]');

  // Self-service signup creates a 'member'-role account (SignupController
  // hardcodes role_id 2, see RbacTest.php's own PHPUnit coverage of this)
  // -- member lands on the frontend module's own dashboard, not backend
  // (SessionController::loginAction's explicit role check, REQ-020/031).
  await expect(page).toHaveURL(/\/frontend\/dashboard\/?$/);
  await expect(page.locator('h1').first()).toContainText(/welcome/i);
});
