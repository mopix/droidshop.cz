import { test } from '@playwright/test'
import { artisanEval, shopUrl } from '../support/shop'
import { auditPage } from '../support/a11y'

/**
 * Accessibility is not a property of the platform, it is a property of what a
 * shop actually renders — and what a shop renders is its theme's markup. WCAG
 * 2.2 AA is a legal obligation here (EAA), so every theme gets the same audit
 * on the three pages a customer spends their time on.
 *
 * axe catches roughly a third of real problems. It does not replace the manual
 * check or the a11y-checker agent; it catches regressions in the third it
 * knows, every time.
 */
const THEMES = ['editorial', 'retail']

function useTheme(key: string): void {
  artisanEval(
    `App\\Models\\TenantTheme::query()->updateOrCreate(` +
      `['tenant_id' => App\\Models\\Tenant::query()->value('id')], ['template' => '${key}']` +
      `);`,
  )
}

test.describe('themes', () => {
  test.afterAll(() => useTheme('base'))

  for (const theme of THEMES) {
    test(`homepage has no accessibility violations — ${theme}`, async ({ page }) => {
      useTheme(theme)
      await page.goto(shopUrl('/'))
      await auditPage(page, `homepage (${theme})`)
    })

    // The search results page rather than a category: the e2e seed has no
    // categories, and this renders the same grid and the same cards a listing
    // does, which is the markup being audited.
    test(`product listing has no accessibility violations — ${theme}`, async ({ page }) => {
      useTheme(theme)
      await page.goto(shopUrl('/hledani?q=e'))
      await auditPage(page, `product listing (${theme})`)
    })

    test(`product detail has no accessibility violations — ${theme}`, async ({ page }) => {
      useTheme(theme)
      await page.goto(shopUrl('/'))
      await page.locator('a[href*="/produkt/"]').first().click()
      await auditPage(page, `product detail (${theme})`)
    })
  }
})
