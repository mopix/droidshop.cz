import { execFileSync } from 'node:child_process'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import type { Page } from '@playwright/test'

// package.json declares "type": "module", so __dirname does not exist.
const here = path.dirname(fileURLToPath(import.meta.url))

// Kept in step with playwright.config.ts — see the comment there for why this
// is `droidshop` and not a separate test-only domain.
const HOST = process.env.E2E_HOST ?? 'droidshop'
const PORT = process.env.E2E_PORT ?? '8001'

export const shopUrl = (pathname = '/'): string => `http://obchod.${HOST}:${PORT}${pathname}`
export const platformUrl = (pathname = '/'): string => `http://${HOST}:${PORT}${pathname}`

/**
 * Runs a snippet of PHP inside the application, for setup a scenario cannot
 * do through the UI — switching a module on, storing a measurement id.
 *
 * Deliberately not a test-only HTTP endpoint: an endpoint that mutates a shop
 * would have to exist in the deployed app, and something that exists can be
 * called. This runs on the same machine as the suite and ships with nothing.
 */
export function artisanEval(php: string): string {
  const root = path.resolve(here, '../..')

  return execFileSync('php', ['artisan', 'tinker', '--execute', php], {
    cwd: root,
    env: {
      ...process.env,
      APP_ENV: 'local',
      DB_DATABASE: process.env.E2E_DATABASE ?? 'droidshop_e2e',
      CACHE_STORE: 'array',
      PLATFORM_DOMAIN: HOST,
    },
    encoding: 'utf8',
  }).trim()
}

/** Switches a module on for the demo shop. */
export function enableModule(key: string): void {
  artisanEval(`
    $t = App\\Models\\Tenant::whereHas('domains', fn($q) => $q->where('domain', 'obchod.${HOST}'))->firstOrFail();
    app(App\\Core\\Modules\\ModuleRegistry::class)->activate($t, '${key}');
  `)
}

/**
 * Stores module settings for the demo shop.
 *
 * @param values JSON object, e.g. {"ga4_measurement_id":"G-E2E123456"}
 */
export function setSettings(module: string, values: Record<string, unknown>): void {
  const json = JSON.stringify(values).replace(/'/g, "\\'")

  artisanEval(`
    $t = App\\Models\\Tenant::whereHas('domains', fn($q) => $q->where('domain', 'obchod.${HOST}'))->firstOrFail();
    app(App\\Core\\Tenancy\\TenantContext::class)->runAs($t, function () {
      app(App\\Core\\Settings\\SettingsService::class)->setMany('${module}', json_decode('${json}', true));
    });
  `)
}

/**
 * The consent decision, written the way the browser would hold it.
 *
 * The cookie is deliberately outside Laravel's encryption and not httpOnly —
 * the storefront's own script reads it to hide the banner before paint — so a
 * test can set it directly, exactly as a returning visitor would arrive.
 */
export async function setConsent(page: Page, categories: string[]): Promise<void> {
  await page.context().addCookies([
    {
      name: 'cookie_consent',
      value: JSON.stringify({ v: '1', c: categories, t: Math.floor(Date.now() / 1000) }),
      domain: `obchod.${HOST}`,
      path: '/',
    },
  ])
}

/** The slug of a product the demo shop is seeded with. */
export async function firstProductSlug(page: Page): Promise<string> {
  await page.goto(shopUrl('/'))

  const href = await page.locator('a[href*="/produkt/"]').first().getAttribute('href')

  if (!href) {
    throw new Error('the demo shop rendered no product links')
  }

  return href.replace(/^.*\/produkt\//, '')
}
