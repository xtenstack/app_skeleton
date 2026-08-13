import { defineConfig } from '@playwright/test';

// Project audit Fix Kit (2026-08-11, Tier 3) — the one truly critical
// multi-step flow (login -> dashboard) as a real browser test, on top
// of (not replacing) the PHPUnit Feature suite's real-HTTP-no-mocking
// approach, which already covers most of what matters minus actual
// browser rendering. Base URL matches what CI's build.yml already
// waits on (localhost:8080) and what local dev serves via
// `php83 -S localhost:8080 -t public bin/dev-router.php` (.claude/launch.json).
export default defineConfig({
  testDir: './tests/e2e',
  globalTeardown: './tests/e2e/global-teardown.ts',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  // 'github' alone annotates the Actions run but writes no report
  // directory — pairing it with 'html' gives build.yml's failure-only
  // artifact upload something real to attach.
  reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : 'list',
  use: {
    baseURL: process.env.E2E_BASE_URL || 'http://localhost:8080',
    trace: 'on-first-retry',
  },
});
