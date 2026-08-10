import { expect, test } from '@playwright/test'
import { artisanEval, shopUrl, signInAsOwner } from '../support/shop'

/**
 * The description field used to be a bare <textarea> where a merchant had to
 * type <h3> by hand (wave 3.13). What matters is not that a toolbar exists,
 * but that what it produces survives the server's sanitiser and reaches the
 * storefront as real markup.
 */
test.describe('rich text editor', () => {
  let slug = ''

  test.beforeAll(() => {
    slug = artisanEval(`
      $t = App\\Models\\Tenant::whereHas('domains', fn($q) => $q->where('domain', 'obchod.droidshop'))->firstOrFail();
      app(App\\Core\\Tenancy\\TenantContext::class)->runAs($t, function () {
        echo Modules\\Products\\Models\\Product::query()->value('slug');
      });
    `).trim()
  })

  test.beforeEach(async ({ page }) => {
    await signInAsOwner(page)
    await page.goto(shopUrl(`/admin/m/products/${slug}`))
  })

  test('the description field is an editor, not a textarea', async ({ page }) => {
    await expect(page.locator('#p-description .ProseMirror')).toBeVisible()
    await expect(page.locator('textarea#p-description')).toHaveCount(0)
  })

  test('a heading and a list written in the editor reach the storefront', async ({ page }) => {
    const editor = page.locator('#p-description .ProseMirror')

    await editor.click()
    await page.keyboard.press('Control+a')
    await page.keyboard.press('Delete')

    await editor.pressSequentially('Parametry')
    await page.getByRole('button', { name: 'Nadpis 3' }).click()

    await page.keyboard.press('Enter')
    await page.getByRole('button', { name: 'Odrážkový seznam' }).click()
    await editor.pressSequentially('Hliník')

    await page.getByRole('button', { name: 'Uložit', exact: true }).click()
    await expect(page.getByText('Produkt byl uložen.')).toBeVisible()

    const response = await page.request.get(shopUrl(`/produkt/${slug}`))
    const html = await response.text()

    expect(html).toContain('<h3>Parametry</h3>')
    expect(html).toContain('<li>Hliník</li>')
  })

  test('the toolbar offers no image and no source view', async ({ page }) => {
    await expect(page.getByRole('button', { name: /obráz/i })).toHaveCount(0)
    await expect(page.getByRole('button', { name: /zdrojov|HTML/i })).toHaveCount(0)
  })
})
