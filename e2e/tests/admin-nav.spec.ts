import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'
import { enableModule, shopUrl, signInAsOwner } from '../support/shop'

/**
 * The admin navigation from wave 3.5: full width, sections that collapse, a
 * rail on narrow desktops and a drawer on mobile.
 *
 * Every one of those is behaviour that only exists once scripts run, so this
 * is the only place it can be checked at all.
 */
test.describe('admin navigation', () => {
  test.beforeAll(() => {
    for (const module of ['products', 'categories', 'orders', 'docs']) {
      enableModule(module)
    }
  })

  test.beforeEach(async ({ page }) => {
    await signInAsOwner(page)
  })

  test('sections are headings, not links, and open on click', async ({ page }) => {
    await page.goto(shopUrl('/dashboard'))

    const heading = page.getByRole('button', { name: 'Produkty', exact: true })
    await expect(heading).toBeVisible()

    // Closed to start with: the owner asked for a menu that begins folded.
    await expect(heading).toHaveAttribute('aria-expanded', 'false')
    await expect(page.getByRole('link', { name: 'Import / export' })).toBeHidden()

    await heading.click()

    await expect(heading).toHaveAttribute('aria-expanded', 'true')
    await expect(page.getByRole('link', { name: 'Import / export' })).toBeVisible()
  })

  /**
   * Arriving on a page whose own menu entry is hidden is disorienting, and it
   * is the one section the visitor demonstrably wants open.
   */
  test('the section holding the current page opens by itself', async ({ page }) => {
    await page.goto(shopUrl('/admin/m/products'))

    await expect(page.getByRole('button', { name: 'Produkty', exact: true })).toHaveAttribute(
      'aria-expanded',
      'true',
    )
    await expect(page.getByRole('link', { name: 'Import / export' })).toBeVisible()
  })

  test('what the user opened survives moving to another page', async ({ page }) => {
    await page.goto(shopUrl('/dashboard'))
    await page.getByRole('button', { name: 'Objednávky', exact: true }).click()
    await expect(page.getByRole('link', { name: 'Doklady' })).toBeVisible()

    await page.getByRole('link', { name: 'Nástěnka' }).click()
    await page.waitForURL(/\/dashboard/)

    await expect(page.getByRole('link', { name: 'Doklady' })).toBeVisible()
  })

  test('the menu is full height beside the content, not above it', async ({ page }) => {
    await page.goto(shopUrl('/dashboard'))

    const nav = page.getByRole('navigation', { name: 'Navigace správy e-shopu' })
    const navBox = await nav.boundingBox()
    const main = await page.locator('#admin-content').boundingBox()

    expect(navBox, 'the side navigation did not render').not.toBeNull()
    expect(main, 'the content area did not render').not.toBeNull()

    // Side by side: the content starts to the right of the menu.
    expect(main!.x).toBeGreaterThan(navBox!.x + navBox!.width - 1)
    // And the menu is at the very left, as asked.
    expect(navBox!.x).toBeLessThanOrEqual(1)
  })

  test('collapsing to a rail keeps every entry reachable', async ({ page }) => {
    await page.goto(shopUrl('/dashboard'))
    await page.getByRole('button', { name: 'Produkty', exact: true }).click()

    await page.getByRole('button', { name: 'Zúžit menu' }).click()

    const nav = page.getByRole('navigation', { name: 'Navigace správy e-shopu' })

    // Polled, not measured once: the width is animated, so a single reading
    // right after the click catches the transition mid-way.
    await expect
      .poll(async () => (await nav.boundingBox())?.width ?? 0, {
        message: 'the rail is not narrower than the full menu',
      })
      .toBeLessThan(100)

    // The label is gone from sight but the link still has an accessible name,
    // so a screen reader and the keyboard can still use it.
    const link = page.getByRole('link', { name: /Produkty: Import \/ export/ })
    await expect(link).toBeVisible()
  })

  test('on a phone the menu is a drawer that Escape closes', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 })
    await page.goto(shopUrl('/dashboard'))

    const nav = page.getByRole('navigation', { name: 'Navigace správy e-shopu' })
    await expect(nav).toBeHidden()

    const hamburger = page.getByRole('button', { name: 'Otevřít menu' })
    await expect(hamburger).toBeVisible()
    await hamburger.click()

    await expect(nav).toBeVisible()

    await page.keyboard.press('Escape')
    await expect(nav).toBeHidden()
  })

  test('a module the shop does not run leaves no link behind', async ({ page }) => {
    await page.goto(shopUrl('/dashboard'))

    // `packeta` is not switched on in beforeAll, so its entry must not exist.
    await expect(page.getByRole('link', { name: 'Expedice' })).toHaveCount(0)
  })

  test('profile and sign-out are in the menu as well as the top bar', async ({ page }) => {
    await page.goto(shopUrl('/dashboard'))

    const nav = page.getByRole('navigation', { name: 'Navigace správy e-shopu' })

    await expect(nav.getByRole('link', { name: 'Profil' })).toBeVisible()
    await expect(nav.getByRole('button', { name: 'Odhlásit' })).toBeVisible()
    await expect(page.getByRole('banner').getByRole('button', { name: 'Odhlásit' })).toBeVisible()
  })

  test('the admin has no blocking accessibility violations', async ({ page }) => {
    await page.goto(shopUrl('/dashboard'))
    await page.getByRole('button', { name: 'Produkty', exact: true }).click()

    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
      .analyze()

    const blocking = results.violations.filter(
      (v) => v.impact === 'critical' || v.impact === 'serious',
    )

    expect(blocking.map((v) => `${v.id} — ${v.help}`)).toEqual([])
  })

  test('the open mobile drawer has no blocking accessibility violations', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 })
    await page.goto(shopUrl('/dashboard'))
    await page.getByRole('button', { name: 'Otevřít menu' }).click()

    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
      .analyze()

    const blocking = results.violations.filter(
      (v) => v.impact === 'critical' || v.impact === 'serious',
    )

    expect(blocking.map((v) => `${v.id} — ${v.help}`)).toEqual([])
  })
})
