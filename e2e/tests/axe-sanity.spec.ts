import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'
import { shopUrl } from '../support/shop'

/**
 * Proves the audit itself works.
 *
 * A green accessibility suite has two possible meanings: the pages are clean,
 * or the checker is not actually looking. From the outside they are
 * indistinguishable, and a silently misconfigured axe would look like the
 * second one forever. So this plants a violation and insists it is caught.
 *
 * Same reasoning as deliberately breaking the consent gate to watch the
 * consent suite go red (wave 3.4, task 3).
 */
test('the accessibility audit can actually fail', async ({ page }) => {
  await page.goto(shopUrl('/'))

  const clean = await new AxeBuilder({ page }).analyze()

  // If nothing at all was evaluated, everything downstream is theatre.
  expect(clean.passes.length, 'axe evaluated no rules').toBeGreaterThan(10)

  await page.evaluate(() => {
    const img = document.createElement('img')
    img.src = '/favicon.ico'
    document.body.appendChild(img)
  })

  const planted = await new AxeBuilder({ page }).analyze()

  expect(
    planted.violations.map((v) => v.id),
    'an image with no alt text went unnoticed',
  ).toContain('image-alt')
})
