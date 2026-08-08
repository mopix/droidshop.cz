import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'
import { shopUrl, signInAsOwner } from '../support/shop'

/**
 * The four settings screens from wave 3.6, in a browser.
 *
 * The PHPUnit suite proves what the server does with the values. This walks
 * the screens the way a merchant does — including one save that has to show
 * up on the storefront — and runs axe over every one of the four new forms,
 * which is around forty new fields.
 */
const SCREENS = [
  { path: '/admin/nastaveni/obchod', heading: 'Obchod' },
  { path: '/admin/nastaveni/kontakty', heading: 'Kontakty' },
  { path: '/admin/nastaveni/seo', heading: 'Vyhledávače a sdílení' },
  { path: '/admin/nastaveni/zobrazeni', heading: 'Zobrazení' },
]

test.describe('shop settings', () => {
  test.beforeEach(async ({ page }) => {
    await signInAsOwner(page)
  })

  for (const screen of SCREENS) {
    test(`${screen.heading} opens and has no accessibility violations`, async ({ page }) => {
      await page.goto(shopUrl(screen.path))

      await expect(page.getByRole('heading', { name: screen.heading, level: 1 })).toBeVisible()

      const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
        .analyze()

      expect(results.violations).toEqual([])
    })
  }

  test('all five entries are reachable from the menu', async ({ page }) => {
    await page.goto(shopUrl('/dashboard'))

    await page.getByRole('button', { name: 'Nastavení', exact: true }).click()

    for (const label of ['Obchod', 'Kontakty', 'SEO', 'Zobrazení', 'Fakturační údaje']) {
      await expect(page.getByRole('link', { name: label, exact: true })).toBeVisible()
    }
  })

  test('a saved tagline shows up on the storefront', async ({ page }) => {
    await page.goto(shopUrl('/admin/nastaveni/obchod'))

    await page.getByLabel('Slogan').fill('Nářadí, které vydrží')
    await page.getByRole('button', { name: 'Uložit' }).click()
    await expect(page.getByText('Nastavení obchodu bylo uloženo.')).toBeVisible()

    await page.goto(shopUrl('/'))
    await expect(page.getByText('Nářadí, které vydrží')).toBeVisible()

    // Put it back, so the rest of the suite sees the shop it expects.
    await page.goto(shopUrl('/admin/nastaveni/obchod'))
    await page.getByLabel('Slogan').fill('')
    await page.getByRole('button', { name: 'Uložit' }).click()
  })
})
