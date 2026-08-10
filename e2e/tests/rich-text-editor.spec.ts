import { expect, test } from '@playwright/test'
import { artisanEval, shopUrl, signInAsOwner } from '../support/shop'
import { auditPage } from '../support/a11y'

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
   * even without a button producing it.
   *
   * The seed link also carries `title` — the one attribute TitledLink adds
   * on top of Tiptap's stock Link mark (HtmlSanitizer allows it on <a>,
   * Tiptap does not know it without the extension). Nothing else in this
   * suite would notice if that override silently stopped working.
   *
   * Clicking "Uložit" with no prior interaction is not a schema test: Show.vue
   * initialises `form.description` straight from the loaded prop and posts
   * that same value on submit unless `RichTextEditor`'s `onUpdate` has fired
   * at least once — mounting the editor does not fire it, only a real
   * transaction does. Without the harmless edit-and-undo below, this test
   * would stay green even with `TitledLink`/`SizedImage` deleted, because it
   * would just be re-posting the exact string `artisanEval` seeded. The edit
   * forces `getHTML()` to actually re-serialise the loaded document, so the
   * assertions below are checking what the *schema* reconstructs, not what
   * PHP originally wrote to the row.
   */
  test('a hand-typed link and an existing image survive an edit and a save', async ({ page }) => {
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

    // A harmless type-then-undo, placed before the link by Home, so it fires
    // a real Tiptap transaction (see the comment above) without touching the
    // link's or the image's own markup.
    await editor.locator('p').first().click()
    await page.keyboard.press('Home')
    await page.keyboard.type('x')
    await page.keyboard.press('Backspace')

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
    await page.keyboard.press('Delete')
    await editor.pressSequentially('Odkaz')
    await page.keyboard.press('Control+a')

    await page.getByRole('button', { name: 'Vložit odkaz' }).click()
    await page.getByLabel('Adresa odkazu').fill('javascript:alert(1)')
    await page.getByRole('button', { name: 'Vložit', exact: true }).click()

    await expect(page.getByText('Adresa musí začínat http://, https://, mailto:, tel: nebo /.')).toBeVisible()
    await expect(page.locator('#p-description .ProseMirror a')).toHaveCount(0)
  })

  /**
   * Tiptap drops nodes its schema does not know, so a description carrying a
   * table or an image would lose it just by being opened and saved. The schema
   * knows both; only the table is offered in the toolbar.
   *
   * Clicking "Uložit" with no prior interaction proves nothing about that: per
   * the comment on the link/image test above, `form.description` is posted
   * unchanged unless the editor has fired a real transaction — with no edit
   * here, this test would stay green even with `TableKit`/`SizedImage`
   * deleted from the schema entirely, because it would just be re-posting the
   * exact string `forceFill` wrote. The edit-and-undo below forces
   * `getHTML()` to re-serialise the whole document (table, image and all)
   * through the actual schema before it is posted.
   *
   * A genuine round trip does not come back byte-identical, and the
   * assertions below deliberately do not require that: Tiptap's table cell
   * schema wraps cell text in its own `<p>` (the same reason list items need
   * `collapseSingleParagraphListItems`, not applied here since it only
   * targets `<li>`) and always serialises `colspan`/`rowspan` — even the `1`
   * a cell never had explicitly. `HtmlSanitizer` allows both on `<th>`/`<td>`
   * and does not police nesting, so none of that is stripped and none of it
   * changes what a browser renders. What must not change is the row/column
   * shape (the `colspan="2"` header still spans two columns) and the actual
   * content (both cell values, the image and every attribute HtmlSanitizer
   * allows on it) — that is what these assertions check.
   */
  test('a table and an image already in the description survive an edit and a save', async ({ page }) => {
    const original =
      '<p>Popis</p><table><tbody><tr><th colspan="2">Rozměry</th></tr>' +
      '<tr><td>Šířka</td><td>30 cm</td></tr></tbody></table>' +
      '<img src="/media/demo.png" alt="Náhled" width="120" height="80">'

    artisanEval(`
      $t = App\\Models\\Tenant::whereHas('domains', fn($q) => $q->where('domain', 'obchod.droidshop'))->firstOrFail();
      app(App\\Core\\Tenancy\\TenantContext::class)->runAs($t, function () {
        $p = Modules\\Products\\Models\\Product::query()->where('slug', '${slug}')->firstOrFail();
        $p->forceFill(['description' => '${original.replace(/'/g, "\\'")}'])->save();
      });
    `)

    await page.goto(shopUrl(`/admin/m/products/${slug}`))

    const editor = page.locator('#p-description .ProseMirror')
    // A harmless type-then-undo in the leading paragraph — see the comment
    // above and the matching one on the link/image test.
    await editor.locator('p').first().click()
    await page.keyboard.press('Home')
    await page.keyboard.type('x')
    await page.keyboard.press('Backspace')

    await page.getByRole('button', { name: 'Uložit', exact: true }).click()
    await expect(page.getByText('Produkt byl uložen.')).toBeVisible()

    const html = await (await page.request.get(shopUrl(`/produkt/${slug}`))).text()

    expect(html).toContain('<table>')
    expect(html).toContain('colspan="2"')
    expect(html).toContain('Rozměry')
    expect(html).toContain('Šířka')
    expect(html).toContain('30 cm')
    expect(html).toContain('src="/media/demo.png"')
    expect(html).toContain('alt="Náhled"')
    expect(html).toContain('width="120"')
    expect(html).toContain('height="80"')
  })

  test('a table can be inserted from the toolbar', async ({ page }) => {
    const editor = page.locator('#p-description .ProseMirror')

    await editor.click()
    await page.keyboard.press('Control+a')
    await page.keyboard.press('Delete')

    await page.getByRole('button', { name: 'Vložit tabulku' }).click()
    await expect(editor.locator('table')).toBeVisible()

    await page.getByRole('button', { name: 'Uložit', exact: true }).click()
    await expect(page.getByText('Produkt byl uložen.')).toBeVisible()

    const html = await (await page.request.get(shopUrl(`/produkt/${slug}`))).text()
    expect(html).toContain('<table>')
  })

  /**
   * Row and column buttons stay visible but go inactive outside a table:
   * buttons that disappear shift the rest of the toolbar and the merchant hunts
   * for them where they were last time.
   */
  test('row and column buttons are disabled outside a table', async ({ page }) => {
    const editor = page.locator('#p-description .ProseMirror')

    await editor.click()
    await page.keyboard.press('Control+a')
    await page.keyboard.press('Delete')
    await editor.pressSequentially('Bez tabulky')

    await expect(page.getByRole('button', { name: 'Přidat řádek' })).toBeDisabled()
    await expect(page.getByRole('button', { name: 'Přidat řádek' })).toBeVisible()
  })

  test('the product form with the editor has no blocking accessibility findings', async ({ page }) => {
    await page.locator('#p-description .ProseMirror').waitFor()

    await auditPage(page, 'product form — editor closed')
  })

  /**
   * axe's button-name rule cannot see a mislabeled toolbar button here: every
   * button also carries visible glyph text ("B", "H3", "•"...), so an
   * accessible name always exists even if aria-label goes missing — proven by
   * deliberately deleting Tučné's aria-label during this task and watching
   * the axe test above stay green. getByRole(role, { name }) performs the
   * same accessible-name lookup a screen reader does, so it is sensitive to
   * exactly the case axe is not: it only matches when the name is the Czech
   * label, not merely present. One button per family covered here (a text
   * mark, a heading, a list, the link button, a table control) rather than
   * all twenty — this is a representative sample against regressions in how
   * `editableAttributes`/toolbar labels are wired, not a re-test of every
   * individual aria-label string (already covered by each toolbar button's
   * own `getByRole('button', { name: ... })` used throughout this file).
   */
  test('the toolbar buttons announce their Czech names, not their glyphs', async ({ page }) => {
    await page.locator('#p-description .ProseMirror').waitFor()

    await expect(page.getByRole('button', { name: 'Tučné' })).toBeVisible()
    await expect(page.getByRole('button', { name: 'Nadpis 3' })).toBeVisible()
    await expect(page.getByRole('button', { name: 'Odrážkový seznam' })).toBeVisible()
    await expect(page.getByRole('button', { name: 'Vložit odkaz' })).toBeVisible()
    await expect(page.getByRole('button', { name: 'Vložit tabulku' })).toBeVisible()
  })

  /**
   * The link dialog swaps in a second row of controls (a text input plus two
   * buttons) that only exist while it is open — an axe pass over the closed
   * toolbar says nothing about whether that markup is also clean.
   */
  test('the link dialog has no blocking accessibility findings while open', async ({ page }) => {
    const editor = page.locator('#p-description .ProseMirror')
    await editor.click()

    await page.getByRole('button', { name: 'Vložit odkaz' }).click()
    await expect(page.getByLabel('Adresa odkazu')).toBeVisible()

    await auditPage(page, 'product form — link dialog open')
  })

  /**
   * global-setup runs migrate:fresh once per `playwright test` invocation,
   * not per spec file, and this describe block's tests run serially
   * (workers: 1, fullyParallel: false) against the one product `slug` picks
   * in beforeAll — so every save above lands on the same row, and whichever
   * of these tests runs last decides what that row holds for the rest of the
   * invocation. This file is currently the only one in the suite that reads
   * or writes a product's `description` (checked by grep), so nothing else
   * observes the mutation today; restoring it here is what keeps that true
   * as the suite grows, without relying on every future spec file to avoid
   * this one field. Matches what DemoShopSeeder itself writes (it does not
   * re-seed a product that already exists).
   */
  test.afterAll(() => {
    artisanEval(`
      $t = App\\Models\\Tenant::whereHas('domains', fn($q) => $q->where('domain', 'obchod.droidshop'))->firstOrFail();
      app(App\\Core\\Tenancy\\TenantContext::class)->runAs($t, function () {
        $p = Modules\\Products\\Models\\Product::query()->where('slug', '${slug}')->firstOrFail();
        app(Modules\\Products\\Services\\ProductWriter::class)->update($p, [
          'description' => '<p>'.$p->short_description.'</p><p>Demo produkt e-shopu DroidShop.</p>',
        ]);
      });
    `)
  })
})

/**
 * The other two admin fields that carry tenant HTML (wave 3.13 named three:
 * the product description above, the static page body, and the homepage
 * text block). Both get the same component Tasks 1-3 built; this only checks
 * that each screen actually mounts it, not the schema/sanitiser behaviour
 * already covered above.
 */
test.describe('rich text editor elsewhere in the admin', () => {
  test.beforeEach(async ({ page }) => {
    await signInAsOwner(page)
  })

  // DataTable renders each row as a real <tr>, and the "Upravit" link text is
  // identical on every row (Pages/Index.vue has no per-row suffix), so the
  // row has to be found by its title cell first, same as any other row-scoped
  // action in this admin.
  test('the static page body is an editor', async ({ page }) => {
    await page.goto(shopUrl('/admin/m/pages'))
    await page
      .locator('tr', { hasText: 'Obchodní podmínky' })
      .getByRole('link', { name: 'Upravit' })
      .click()

    await expect(page.locator('#body .ProseMirror')).toBeVisible()
    await expect(page.locator('textarea#body')).toHaveCount(0)
  })

  /**
   * The legal templates from wave 3.2 are filled in at "[DOPLŇTE …]" markers
   * and the form warns while any remain. Tiptap carries the text unchanged,
   * so the warning must still fire — it is the only thing standing between a
   * template and a page published while it still reads as unfinished.
   *
   * "Obchodní podmínky" (PageTemplates::terms()) seeds with about a dozen
   * [DOPLŇTE …] markers already in it, so `hasPlaceholders` is true from the
   * moment the page loads — before this test touches the editor at all.
   * Ticking "Publikovat" alone, with zero interaction with the editor, would
   * satisfy an assertion that the banner is merely *visible*: that version of
   * this test passed whether or not RichTextEditor propagated anything into
   * `form.body`. A before/after transition is the only shape that can only
   * pass if the binding actually works: select-all + delete empties the
   * document (RichTextEditor's onUpdate emits '' for a lone empty paragraph,
   * see isTrulyEmpty in RichTextEditor.vue), which must make the banner
   * disappear despite is_published staying checked throughout; typing a
   * marker back in must make it reappear. If the editor's v-model binding
   * were broken, form.body would still hold the original dozen markers after
   * the delete, and the first assertion (banner gone) would fail.
   *
   * The templates seed unpublished (Modules/Pages/Lifecycle.php), and the
   * banner is gated on `form.is_published && hasPlaceholders`, so the
   * checkbox is ticked once up front and left alone — the test only ever
   * varies the editor's content from there.
   */
  test('the placeholder warning tracks the editor, not just the publish checkbox', async ({ page }) => {
    await page.goto(shopUrl('/admin/m/pages'))
    await page
      .locator('tr', { hasText: 'Obchodní podmínky' })
      .getByRole('link', { name: 'Upravit' })
      .click()

    await page.getByLabel(/Publikovat/i).check()
    await expect(page.getByText(/nedoplněné části/)).toBeVisible()

    const editor = page.locator('#body .ProseMirror')
    await editor.click()
    await page.keyboard.press('Control+a')
    await page.keyboard.press('Delete')

    await expect(page.getByText(/nedoplněné části/)).toHaveCount(0)

    await editor.pressSequentially('[DOPLŇTE název firmy]')

    await expect(page.getByText(/nedoplněné části/)).toBeVisible()
  })

  /**
   * The wave-3.2 legal templates (Modules/Pages/Support/PageTemplates.php)
   * carry two constructs the survival tests on the product description never
   * exercised: <br> line breaks (the "Kontakt" page's address block, line 64)
   * and target="_blank" rel="noopener" on an external link (the ČOI link on
   * "Obchodní podmínky", line 84). Both tags/attributes are in
   * HtmlSanitizer::ALLOWED, so both should survive a real edit — "should" is
   * what the description field's own survival tests said too, before anyone
   * made them able to fail on a broken schema.
   *
   * HtmlSanitizer itself rewrites `rel` to exactly "noopener noreferrer"
   * whenever `target` is non-empty (HtmlSanitizer.php:135-136), unconditionally
   * — that already ran on every save before this task existed, so the
   * assertion checks for that server-authoritative value, not the template's
   * original "noopener".
   *
   * Each page gets the same harmless edit-and-undo the description survival
   * tests use: it forces a real Tiptap transaction, so getHTML() actually
   * re-serialises the whole loaded document (br and link included) through
   * the schema before it is posted, rather than just re-posting the prop
   * PageTemplates seeded untouched.
   */
  test('the seeded legal templates keep <br> and target/rel through an edit and a save', async ({ page }) => {
    await page.goto(shopUrl('/admin/m/pages'))
    await page.locator('tr', { hasText: 'Kontakt' }).getByRole('link', { name: 'Upravit' }).click()

    // <br> has no box of its own, so Playwright's toBeVisible() reports it
    // hidden even when present — count is the correct check here, not
    // visibility.
    let editor = page.locator('#body .ProseMirror')
    await expect(editor.locator('br')).not.toHaveCount(0)

    await editor.locator('p').first().click()
    await page.keyboard.press('Home')
    await page.keyboard.type('x')
    await page.keyboard.press('Backspace')

    await page.getByRole('button', { name: 'Uložit' }).click()
    await expect(page.getByText('Stránka byla uložena.')).toBeVisible()

    const contactBody = artisanEval(`
      $t = App\\Models\\Tenant::whereHas('domains', fn($q) => $q->where('domain', 'obchod.droidshop'))->firstOrFail();
      app(App\\Core\\Tenancy\\TenantContext::class)->runAs($t, function () {
        echo Modules\\Pages\\Models\\Page::where('slug', 'kontakt')->value('body');
      });
    `)
    expect(contactBody).toContain('<br>')

    await page.locator('tr', { hasText: 'Obchodní podmínky' }).getByRole('link', { name: 'Upravit' }).click()

    editor = page.locator('#body .ProseMirror')
    await expect(editor.locator('a[href="https://adr.coi.cz"]')).toBeVisible()

    await editor.locator('p').first().click()
    await page.keyboard.press('Home')
    await page.keyboard.type('x')
    await page.keyboard.press('Backspace')

    await page.getByRole('button', { name: 'Uložit' }).click()
    await expect(page.getByText('Stránka byla uložena.')).toBeVisible()

    const termsBody = artisanEval(`
      $t = App\\Models\\Tenant::whereHas('domains', fn($q) => $q->where('domain', 'obchod.droidshop'))->firstOrFail();
      app(App\\Core\\Tenancy\\TenantContext::class)->runAs($t, function () {
        echo Modules\\Pages\\Models\\Page::where('slug', 'obchodni-podminky')->value('body');
      });
    `)
    expect(termsBody).toContain('href="https://adr.coi.cz"')
    expect(termsBody).toContain('target="_blank"')
    expect(termsBody).toContain('rel="noopener noreferrer"')

    // The same template also carries a plain internal link
    // (`/ochrana-osobnich-udaju`, PageTemplates::terms()) with no target of
    // its own. Stock @tiptap/extension-link defaults the `target` attribute
    // to "_blank" for every link the schema knows about — parsed or
    // inserted — so without TitledLink overriding that default to null, this
    // internal link would silently gain target="_blank" (and, downstream,
    // HtmlSanitizer.php:135-136 would then stamp a rel onto it too) just from
    // being opened and saved. A tenant's own terms page would start punting
    // customers into a new tab for a link that never asked for one.
    const internalLink = termsBody.match(/<a[^>]*href="\/ochrana-osobnich-udaju"[^>]*>/)
    expect(internalLink).not.toBeNull()
    expect(internalLink![0]).not.toContain('target=')
  })

  /**
   * DefaultHomepage seeds hero/product_row/category_grid only — no shop
   * starts with a text block — so this adds one itself before it can open
   * its editor, and removes it afterwards so a later spec file (shop-lock,
   * shop-settings, smoke, vat-mode — all sort after this file and all visit
   * "/") never renders against a leftover block.
   */
  test('the homepage text block is an editor', async ({ page }) => {
    await page.goto(shopUrl('/admin/m/storefront/homepage'))

    await page.locator('#new-block-type').selectOption('text')
    await page.getByRole('button', { name: 'Přidat blok' }).click()

    const row = page.locator('li', { hasText: 'Textový blok' })
    await row.getByRole('button', { name: 'Upravit' }).click()
    await expect(row.locator('.ProseMirror')).toBeVisible()

    // exact: true — the editor's own toolbar adds a "Smazat tabulku" button
    // inside the same row once the RichTextEditor mounts, and a substring
    // match against plain "Smazat" catches that one too.
    await row.getByRole('button', { name: 'Smazat', exact: true }).click()
    // ConfirmDialog's confirm button is relabelled "Smazat" here too
    // (confirm-label="Smazat" on the dialog), not the component's default
    // "Potvrdit" — every other block's own row "Smazat" button carries the
    // same accessible name, so this has to be scoped to the dialog itself.
    await page.getByLabel('Smazat blok').getByRole('button', { name: 'Smazat', exact: true }).click()
    await expect(page.locator('li', { hasText: 'Textový blok' })).toHaveCount(0)
  })
})
