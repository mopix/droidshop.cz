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

  /**
   * The old textarea's own hint told merchants links were allowed, so a
   * hand-typed `<a href>` in an existing description is a real scenario, not
   * a hypothetical. Neither a link nor an image has a toolbar button in this
   * task (Task 2 and the plan's own image decision respectively), so the
   * only way either survives is if the editor's schema still knows the tag
   * even without a button producing it — opening and saving unchanged must
   * not be what strips it.
   *
   * The seed link also carries `title` — the one attribute TitledLink adds
   * on top of Tiptap's stock Link mark (HtmlSanitizer allows it on <a>,
   * Tiptap does not know it without the extension). Nothing else in this
   * suite would notice if that override silently stopped working.
   */
  test('a hand-typed link and an existing image survive opening the field unchanged', async ({ page }) => {
    artisanEval(`
      $t = App\\Models\\Tenant::whereHas('domains', fn($q) => $q->where('domain', 'obchod.droidshop'))->firstOrFail();
      app(App\\Core\\Tenancy\\TenantContext::class)->runAs($t, function () {
        $product = Modules\\Products\\Models\\Product::where('slug', '${slug}')->firstOrFail();
        app(Modules\\Products\\Services\\ProductWriter::class)->update($product, [
          'description' => '<p>Text s <a href="https://example.com/x" title="Navod">odkazem</a>.</p><p><img src="/media/e2e-test.png" alt="Ukazkovy obrazek" width="120"></p>',
        ]);
      });
    `)

    // The shared beforeEach already navigated before this seed ran.
    await page.reload()

    const editor = page.locator('#p-description .ProseMirror')
    await expect(editor.locator('a[href="https://example.com/x"]')).toBeVisible()
    await expect(editor.locator('a[title="Navod"]')).toBeVisible()
    await expect(editor.locator('img[src="/media/e2e-test.png"]')).toBeVisible()

    await page.getByRole('button', { name: 'Uložit', exact: true }).click()
    await expect(page.getByText('Produkt byl uložen.')).toBeVisible()

    const response = await page.request.get(shopUrl(`/produkt/${slug}`))
    const html = await response.text()

    expect(html).toContain('href="https://example.com/x"')
    expect(html).toContain('title="Navod"')
    expect(html).toContain('<img src="/media/e2e-test.png" alt="Ukazkovy obrazek" width="120">')
  })

  test('the toolbar offers no image and no source view', async ({ page }) => {
    await expect(page.getByRole('button', { name: /obráz/i })).toHaveCount(0)
    await expect(page.getByRole('button', { name: /zdrojov|HTML/i })).toHaveCount(0)
  })

  /**
   * The hint text ("Nadpisy, seznamy a odkazy...") lives in a <p> next to
   * the editor, wired by id through aria-describedby — but the id it needs
   * to land on belongs to the actual editable node Tiptap builds at
   * runtime, not the wrapping <div id="p-description"> Show.vue renders
   * around the whole component. An attribute bound on the wrong element is
   * present in the DOM either way, so only checking where it actually
   * ended up catches the bug.
   */
  test('the hint is announced from the editable node itself', async ({ page }) => {
    await expect(page.locator('#p-description .ProseMirror')).toHaveAttribute(
      'aria-describedby',
      'p-description-hint',
    )
  })

  test('a link written in the editor reaches the storefront', async ({ page }) => {
    const editor = page.locator('#p-description .ProseMirror')

    await editor.click()
    await page.keyboard.press('Control+a')
    await page.keyboard.press('Delete')
    await editor.pressSequentially('Návod')
    await page.keyboard.press('Control+a')

    await page.getByRole('button', { name: 'Vložit odkaz' }).click()
    await page.getByLabel('Adresa odkazu').fill('https://example.com/navod')
    await page.getByRole('button', { name: 'Vložit', exact: true }).click()

    await page.getByRole('button', { name: 'Uložit', exact: true }).click()
    await expect(page.getByText('Produkt byl uložen.')).toBeVisible()

    const html = await (await page.request.get(shopUrl(`/produkt/${slug}`))).text()
    expect(html).toContain('href="https://example.com/navod"')
  })

  /**
   * The sanitiser rejects javascript: on the server. The editor must not offer
   * it either — a field that accepts a value the server silently strips teaches
   * the merchant that the save is broken.
   */
  test('the link dialog refuses a javascript: url', async ({ page }) => {
    const editor = page.locator('#p-description .ProseMirror')

    await editor.click()
    await page.keyboard.press('Control+a')
    await editor.pressSequentially('Odkaz')
    await page.keyboard.press('Control+a')

    await page.getByRole('button', { name: 'Vložit odkaz' }).click()
    await page.getByLabel('Adresa odkazu').fill('javascript:alert(1)')
    await page.getByRole('button', { name: 'Vložit', exact: true }).click()

    await expect(page.getByText('Adresa musí začínat http://, https://, mailto:, tel: nebo /.')).toBeVisible()
    await expect(page.locator('#p-description .ProseMirror a')).toHaveCount(0)
  })
})
