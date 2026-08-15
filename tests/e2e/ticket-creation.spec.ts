import { test, expect } from '@playwright/test';

// Project audit Fix Kit (2026-08-13, Tier 2) — second E2E flow, alongside
// login.spec.ts's login -> dashboard coverage: a frontend member raising a
// support request through the real browser form, including the Project ID
// field (frontend\TicketsController::createAction()'s `project` column,
// ticket #17 fix -- required to qualify for SLA response timing per the
// form's own copy in tests/e2e/../../app/modules/frontend/views/tickets/new.phtml).
// Same self-contained signup pattern as login.spec.ts (no pre-seeded
// fixture users in CI) and the same 'playwright-*@example.invalid' email
// convention, so global-teardown.ts's existing psql cleanup already covers
// this test's fixture account without any changes there -- the ticket row
// itself is left owned by that soft-deleted user, same as any other
// historical record tied to a deactivated account elsewhere in the app,
// not something this test needs to separately delete.
test('a frontend member creates a support request with a Project ID', async ({ page }) => {
  const email = `playwright-${Date.now()}-${Math.random().toString(16).slice(2)}@example.invalid`;
  const password = 'PlaywrightTest123!';

  await page.goto('/backend/signup');
  await page.fill('input[name="first_name"]', 'Playwright');
  await page.fill('input[name="last_name"]', 'Ticketing');
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', password);
  await page.click('button[type="submit"]');

  // Signup doesn't auto-authenticate (see login.spec.ts's own comment) --
  // log in explicitly.
  await page.goto('/backend/session');
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', password);
  await page.click('button[type="submit"]');

  await expect(page).toHaveURL(/\/frontend\/dashboard\/?$/);

  await page.goto('/frontend/tickets/new');

  const title = `Playwright ticket ${Date.now()}`;
  const projectId = `PROJ-${Math.random().toString(16).slice(2, 8)}`;

  await page.fill('#title', title);
  await page.check('#ticket_type_bug');
  await page.fill('#project', projectId);
  await page.selectOption('#severity', 'high');
  await page.fill('#description', 'Filed by the Playwright E2E ticket-creation test.');
  await page.click('button[type="submit"]');

  // createAction() redirects straight to the new ticket's own view page.
  await expect(page).toHaveURL(/\/frontend\/tickets\/view\/\d+\/?$/);
  await expect(page.locator('h1').first()).toContainText(title);
  await expect(page.getByText('Project ID')).toBeVisible();
  await expect(page.locator('dd', { hasText: projectId })).toBeVisible();
});
