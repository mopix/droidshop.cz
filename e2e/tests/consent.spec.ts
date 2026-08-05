import { expect, test } from '@playwright/test'
import { enableModule, setConsent, setSettings, shopUrl } from '../support/shop'
import { watchVendors } from '../support/tracking'

/**
 * The scenario this whole suite was built for.
 *
 * Wave 3.3 gates the measurement scripts on the visitor's consent, but a PHP
 * test can only assert that the HTML contains nothing which fetches on its
 * own. Whether the JavaScript then honours the decision is a question only a
 * browser can answer — and it is a legal one, not a cosmetic one: a request
 * before consent carries the visitor's IP to Google whether or not a cookie
 * comes back.
 */
test.describe('cookie consent', () => {
  test.beforeAll(() => {
    enableModule('analytics')
    setSettings('analytics', {
      ga4_measurement_id: 'G-E2E1234567',
      sklik_retargeting_id: '123456',
      meta_pixel_id: '9988776655',
    })
  })

  test('nothing reaches a vendor before the visitor decides', async ({ page }) => {
    const vendors = await watchVendors(page)

    await page.goto(shopUrl('/'))
    await page.waitForLoadState('networkidle')

    expect(vendors.attempts, 'a measurement script ran before consent').toEqual([])
    await expect(page.locator('#cookie-banner')).toBeVisible()
  })

  test('accepting starts measurement on the same page, without a reload', async ({ page }) => {
    const vendors = await watchVendors(page)

    await page.goto(shopUrl('/'))
    await page.waitForLoadState('networkidle')
    expect(vendors.attempts).toEqual([])

    await page.getByRole('button', { name: 'Přijmout vše' }).click()

    // The banner listens for its own fetch to finish and then tells the
    // tracking snippet; a visitor who accepts is measured on the page where
    // they accepted, not only from the next one.
    await expect.poll(() => vendors.hit('googletagmanager.com'), {
      message: 'GA4 did not start after consent',
    }).toBe(true)

    await expect(page.locator('#cookie-banner')).toBeHidden()
  })

  /**
   * This is the test that actually catches a broken gate.
   *
   * Verified by removing `allows('analytics')` from the tracking snippet: this
   * scenario and the settings one below went red, while "nothing before the
   * visitor decides" stayed green — with no decision recorded the snippet does
   * not run at all, so the gate inside it never gets a chance to be wrong.
   * Worth knowing which test is load-bearing before trusting the suite.
   */
  test('refusing keeps every vendor silent, including after a reload', async ({ page }) => {
    const vendors = await watchVendors(page)

    await page.goto(shopUrl('/'))
    await page.getByRole('button', { name: 'Odmítnout vše' }).click()
    await expect(page.locator('#cookie-banner')).toBeHidden()

    await page.waitForLoadState('networkidle')
    expect(vendors.attempts, 'a vendor was contacted after an explicit refusal').toEqual([])

    await page.reload()
    await page.waitForLoadState('networkidle')

    expect(vendors.attempts, 'a refusal did not survive a reload').toEqual([])
    await expect(page.locator('#cookie-banner')).toBeHidden()
  })

  test('analytics alone starts GA4 and leaves the marketing vendors alone', async ({ page }) => {
    const vendors = await watchVendors(page)
    await setConsent(page, ['analytics'])

    await page.goto(shopUrl('/'))
    await page.waitForLoadState('networkidle')

    await expect.poll(() => vendors.hit('googletagmanager.com')).toBe(true)
    expect(vendors.hit('c.seznam.cz'), 'Sklik ran without marketing consent').toBe(false)
    expect(vendors.hit('connect.facebook.net'), 'Meta ran without marketing consent').toBe(false)
  })

  /**
   * The banner is baked into every cached page, so for someone who already
   * decided it would flash on every single page load if hiding it waited for
   * the deferred bundle. That is what the inline script in the head prevents,
   * and the only way to see it is to look before the page settles.
   */
  test('the banner never flashes for someone who already decided', async ({ page }) => {
    await setConsent(page, ['analytics', 'marketing'])

    await page.goto(shopUrl('/'), { waitUntil: 'domcontentloaded' })

    await expect(page.locator('#cookie-banner')).toBeHidden()
  })

  test('the decision can be changed from the settings screen', async ({ page }) => {
    const vendors = await watchVendors(page)
    await setConsent(page, ['analytics', 'marketing'])

    await page.goto(shopUrl('/souhlas-cookies'))
    // The settings screen uses the storefront layout, so with the consent
    // still in force the vendors legitimately ran while it was open. What is
    // under test is what happens AFTER the withdrawal, so the record starts
    // from there.
    await page.getByRole('button', { name: 'Odmítnout vše' }).click()
    await expect(page.getByText('Nastavení cookies bylo uloženo.')).toBeVisible()
    vendors.clear()

    await page.goto(shopUrl('/'))
    await page.waitForLoadState('networkidle')

    expect(vendors.attempts, 'withdrawing consent did not stop the vendors').toEqual([])
  })

  test('the footer always offers a way back to the settings', async ({ page }) => {
    await setConsent(page, ['analytics'])

    await page.goto(shopUrl('/'))

    await page.getByRole('link', { name: 'Nastavení cookies' }).click()
    await expect(page).toHaveURL(/souhlas-cookies/)
  })
})
