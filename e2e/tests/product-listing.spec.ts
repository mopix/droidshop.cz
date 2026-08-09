import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'
import { shopUrl, signInAsOwner } from '../support/shop'

/**
 * The listing's inline controls (wave 3.12).
 */
test.describe('product listing', () => {
  test.beforeEach(async ({ page }) => {
    await signInAsOwner(page)
    await page.goto(shopUrl('/admin/m/products'))
  })

  test('the status saves as soon as it is changed', async ({ page }) => {
    const row = page.locator('tbody tr').first()
    const select = row.locator('select')

    const before = await select.inputValue()
    const next = before === 'active' ? 'hidden' : 'active'

    await select.selectOption(next)
    await expect(page.getByText('Stav produktu byl změněn.')).toBeVisible()

    await page.reload()
    await expect(page.locator('tbody tr').first().locator('select')).toHaveValue(next)

    // Put it back, so the rest of the suite finds the shop it expects.
    await page.locator('tbody tr').first().locator('select').selectOption(before)
    await expect(page.getByText('Stav produktu byl změněn.')).toBeVisible()
  })

  /**
   * The tint is a scanning aid, never the only sign of the status — the Stav
   * column says it in words as well (WCAG 1.4.1).
   */
  test('a row is tinted by its status', async ({ page }) => {
    const row = page.locator('tbody tr').first()

    await row.locator('select').selectOption('active')
    await expect(page.getByText('Stav produktu byl změněn.')).toBeVisible()

    await expect(row).toHaveClass(/bg-green-50/)
  })

  test('the actions column offers edit and delete', async ({ page }) => {
    const row = page.locator('tbody tr').first()

    await expect(row.getByRole('link', { name: /^Upravit / })).toBeVisible()
    await expect(row.getByRole('button', { name: /^Smazat / })).toBeVisible()
  })

  /**
   * Every delete asks first (project rule), and this one says where the
   * product goes.
   */
  test('deleting asks first and says it goes to the bin', async ({ page }) => {
    await page.locator('tbody tr').first().getByRole('button', { name: /^Smazat / }).click()

    await expect(page.getByText(/přesune do koše/)).toBeVisible()

    // Cancelled: the suite must not lose a demo product to a UI test.
    await page.getByRole('button', { name: 'Zrušit' }).click()
    await expect(page.getByText(/přesune do koše/)).toBeHidden()
  })

  test('the listing has no blocking accessibility violations', async ({ page }) => {
    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
      .analyze()

    expect(results.violations).toEqual([])
  })
})
