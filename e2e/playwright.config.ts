import { defineConfig, devices } from '@playwright/test'

/**
 * End-to-end suite.
 *
 * Exists to cover what 2000+ PHPUnit tests structurally cannot: anything that
 * needs JavaScript to actually run. Three things in particular — that the
 * tracking scripts stay silent until the visitor consents (wave 3.3), that a
 * purchase completes with JavaScript switched off (§16.3), and that the key
 * pages have no serious accessibility violations.
 */

/**
 * The host the shop is reached on.
 *
 * `droidshop`, the same one the PHPUnit suite and the demo already use —
 * verified to work: Chromium resolves it from /etc/hosts and does not upgrade
 * an explicit http:// URL with a port to HTTPS.
 *
 * Wave 3.4 was planned around a separate `droidshop.test` (RFC 6761) on the
 * theory that a browser would not leave a bare TLD alone, and before that
 * around a certificate limitation recorded in STATUS.md. Neither turned out
 * to apply: this suite speaks plain HTTP, so there is no certificate, and the
 * existing host works as it stands. A separate domain would have meant a
 * sudo-edited /etc/hosts on every machine for no gain.
 *
 * Override with E2E_HOST if a machine ever does need it.
 */
const HOST = process.env.E2E_HOST ?? 'droidshop'
const PORT = Number(process.env.E2E_PORT ?? 8001)

/**
 * Environment for the served app.
 *
 * PLATFORM_DOMAIN — the suite runs on `.test` (RFC 6761), which browsers are
 *   required to leave alone. A bare `droidshop` gets upgraded to HTTPS or sent
 *   to a search engine by Chromium, which is the real reason for the separate
 *   domain; the certificate limitation recorded in STATUS.md concerns `curl`
 *   over HTTPS and has nothing to do with this suite, which speaks plain HTTP.
 *
 * PAGE_CACHE_ENABLED=false — otherwise a scenario would be served HTML stored
 *   by the previous one and the first red test would be unexplainable. The
 *   cache has its own 108 PHPUnit tests; it is not what this suite is for.
 *
 * CACHE_STORE=array — sidesteps the broken cache serialisation on the dev
 *   machine (see docs/DEMO-LOCAL.md).
 */
const SERVER_ENV = [
  `PLATFORM_DOMAIN=${HOST}`,
  // Its own database, never the developer's — global-setup runs
  // migrate:fresh, and a test suite has no business wiping the data someone
  // was clicking through in the demo.
  `DB_DATABASE=${process.env.E2E_DATABASE ?? 'droidshop_e2e'}`,
  'APP_ENV=local',
  'CACHE_STORE=array',
  'SESSION_DRIVER=file',
  'QUEUE_CONNECTION=sync',
  'PAGE_CACHE_ENABLED=false',
  'MAIL_MAILER=array',
].join(' ')

export default defineConfig({
  testDir: './tests',
  // Serial. The scenarios share one database, and a purchase running beside a
  // stock assertion is a race nobody wants to debug at 2 a.m.
  fullyParallel: false,
  workers: 1,
  // One retry in CI: a single network hiccup is not a reason for a red build,
  // two in a row is. Locally none — a flaky test has to be visible to whoever
  // just wrote it.
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : [['list']],
  timeout: 30_000,
  expect: { timeout: 10_000 },

  use: {
    baseURL: `http://obchod.${HOST}:${PORT}`,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    // Chromium only until the suite has proven it is stable. A second browser
    // doubles the runtime and the flakiness before it catches anything.
    ...devices['Desktop Chrome'],
  },

  projects: [
    {
      name: 'chromium',
      testIgnore: /no-js/,
    },
    {
      // Its own project rather than a per-test option: "the checkout works
      // without JavaScript" is a binding acceptance criterion (§16.3), and a
      // criterion buried in one test's options is one nobody sees.
      name: 'no-js',
      testMatch: /no-js/,
      use: { javaScriptEnabled: false },
    },
  ],

  webServer: {
    command: `${SERVER_ENV} php artisan serve --host=127.0.0.1 --port=${PORT} --no-reload`,
    url: `http://127.0.0.1:${PORT}/up`,
    // Port 8001, not the demo's 8000: the suite must not depend on whether a
    // developer left a server running, nor kill the one they are using.
    reuseExistingServer: false,
    timeout: 60_000,
    cwd: '..',
    stdout: 'pipe',
    stderr: 'pipe',
  },

  globalSetup: './support/global-setup.ts',
})
