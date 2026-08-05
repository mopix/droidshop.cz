import { expect, test } from '@playwright/test'
import { shopUrl } from '../support/shop'

/**
 * Proves the harness itself works: the server starts, the seed ran, the host
 * resolves and the shop renders. When something here fails, no other scenario
 * is worth reading.
 */
test('the demo shop answers on its own host', async ({ page }) => {
  await page.goto(shopUrl('/'))

  await expect(page).toHaveTitle(/.+/)
  await expect(page.locator('body')).toContainText(/./)
})

test('the storefront renders products server-side', async ({ page }) => {
  const response = await page.goto(shopUrl('/'))

  expect(response?.status()).toBe(200)

  // The binding storefront rule: the catalogue has to be in the server's
  // first response, not fetched afterwards.
  const html = await response!.text()
  expect(html).toContain('/produkt/')
})
