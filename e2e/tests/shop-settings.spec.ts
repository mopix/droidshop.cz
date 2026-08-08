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

  /**
   * The layout the owner asked for: full width like the rest of the admin,
   * and the cards that used to sit under one another side by side.
   *
   * Asserted on geometry, not on class names — a class can be present and
   * overridden, and what was wrong before was what the page looked like.
   */
  test('cards sit side by side on a wide screen and stack on a narrow one', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 })
    await page.goto(shopUrl('/admin/nastaveni/obchod'))

    const cards = page.locator('#admin-content fieldset')
    await expect(cards).toHaveCount(2)

    const [first, second] = [await cards.nth(0).boundingBox(), await cards.nth(1).boundingBox()]

    // Beside, not below.
    expect(second!.x).toBeGreaterThan(first!.x + first!.width - 1)
    expect(Math.abs(second!.y - first!.y)).toBeLessThan(4)

    // Filling the width, not a narrow column in the middle: the two cards
    // together used to cap at 672px whatever the screen.
    expect(first!.width + second!.width).toBeGreaterThan(900)

    // One column on a phone — two columns of form fields there are unusable.
    await page.setViewportSize({ width: 390, height: 844 })
    const stackedFirst = await cards.nth(0).boundingBox()
    const stackedSecond = await cards.nth(1).boundingBox()

    expect(stackedSecond!.y).toBeGreaterThan(stackedFirst!.y + stackedFirst!.height - 1)
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
