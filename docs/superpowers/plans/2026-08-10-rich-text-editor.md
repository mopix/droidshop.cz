# Rich text editor — implementační plán

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Nahradit holou `<textarea>` pro HTML pole administrace jednou sdílenou WYSIWYG komponentou postavenou na Tiptapu, jejíž schéma odpovídá allowlistu `HtmlSanitizer`.

**Architecture:** Jedna komponenta `resources/js/Components/Ui/RichTextEditor.vue` s `v-model` na HTML řetězec, nasazená na tři místa (popis produktu, obsah statické stránky, textový blok homepage). Server se nemění — `HtmlSanitizer` zůstává autoritou nad tím, co se uloží. Editor jen zúží, co nájemce vyrobí.

**Tech Stack:** Vue 3 `<script setup lang="ts">`, Inertia, Tiptap 3.29.2 (`@tiptap/vue-3`, `@tiptap/starter-kit`, `@tiptap/extension-image`, `@tiptap/extension-link`, `@tiptap/extension-table`), Tailwind, Playwright.

**Spec:** `docs/superpowers/specs/2026-08-10-rich-text-editor-design.md`

## Global Constraints

- **Server se nemění.** Žádná migrace, žádný nový endpoint, žádná změna PHP validace. Kdyby se v průběhu ukázalo, že je potřeba, je to nález k předložení vlastníkovi, ne krok navíc.
- **Schéma editoru nesmí nabídnout značku mimo `app/Core/Html/HtmlSanitizer.php::ALLOWED`.** Tlačítko, jehož výstup server smaže, je lež o tom, co e-shop umí.
- **Žádné tlačítko pro obrázek a žádné zobrazení zdrojového HTML** (zadání vlastníka).
- **Žádná tichá ztráta cizího obsahu.** Otevřít a uložit beze změny nesmí ubrat značku ani atribut, který sanitizer povoluje.
- Kód, komentáře i commity anglicky; texty v UI česky. Komentář vysvětluje **proč**, ne co.
- Závislosti přidávat přesně ty jmenované v tomto plánu, verze `^3.29.2`.
- Přístupnost WCAG 2.2 AA: vše ovladatelné klávesnicí, tlačítka `type="button"`, ikona vždy s textovým názvem pro odečítač.
- Před commitem PHP: `./vendor/bin/pint` (v tomto plánu se PHP nemění). Projekt **nepoužívá prettier** — formátování Vue souborů drž podle okolního kódu.
- E2E sada: `npm run e2e`, jednotlivý soubor `npx playwright test --config=e2e/playwright.config.ts e2e/tests/<jméno>.spec.ts`.
- Po každé změně frontendu, kterou má vidět E2E: `npm run build`.

---

### Task 1: Komponenta se základním toolbarem, nasazená na popis produktu

**Files:**
- Modify: `package.json` (nové závislosti)
- Create: `resources/js/Components/Ui/RichTextEditor.vue`
- Modify: `resources/js/Pages/Modules/Products/Show.vue` (~825–838, blok s `#p-description`)
- Test: `e2e/tests/rich-text-editor.spec.ts`

**Interfaces:**
- Consumes: nic (první úkol)
- Produces: komponenta `RichTextEditor` s props `modelValue: string | null`, `id: string`, `ariaDescribedby?: string` a emitem `update:modelValue(value: string)`. Toolbar tlačítka nesou `aria-label` v češtině: `Tučné`, `Kurzíva`, `Podtržené`, `Odstavec`, `Nadpis 2`, `Nadpis 3`, `Nadpis 4`, `Odrážkový seznam`, `Číslovaný seznam`, `Citace`, `Vymazat formátování`. Editační plocha má `class="ProseMirror"` (dává jí Tiptap) a v testech se dohledá přes `page.locator('#p-description .ProseMirror')`.

- [ ] **Step 1: Nainstalovat závislosti**

```bash
npm install @tiptap/vue-3@^3.29.2 @tiptap/starter-kit@^3.29.2 @tiptap/extension-image@^3.29.2 @tiptap/extension-link@^3.29.2 @tiptap/extension-table@^3.29.2
```

Ověř, že skončily v `dependencies` (vedle `lucide-vue-next`), ne v `devDependencies`.

- [ ] **Step 2: Napsat padající E2E test**

Vytvoř `e2e/tests/rich-text-editor.spec.ts`:

```ts
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
```

- [ ] **Step 3: Spustit test, ověřit, že padá**

```bash
npx playwright test --config=e2e/playwright.config.ts e2e/tests/rich-text-editor.spec.ts
```

Očekávané: FAIL — `#p-description .ProseMirror` neexistuje, pole je pořád `<textarea>`.

- [ ] **Step 4: Napsat komponentu**

Vytvoř `resources/js/Components/Ui/RichTextEditor.vue`. Toolbar zatím bez odkazu a bez tabulek (přijdou v Tasku 2 a 3), ale schéma už zná `Image`, aby existující obrázek přežil.

```vue
<script setup lang="ts">
/**
 * A small WYSIWYG editor for the three admin fields that carry tenant HTML.
 *
 * The editor's schema is a hand-kept mirror of the allowlist in
 * app/Core/Html/HtmlSanitizer.php. The two can only drift by someone changing
 * one without the other, so: a button here that produces a tag the sanitiser
 * drops is a lie about what the shop can do, and a tag the sanitiser allows
 * but the schema does not know is data the editor deletes just by opening.
 *
 * The sanitiser stays the authority (decision 2026-07-20, cleaning on write).
 * This component is convenience, not defence.
 */
import { EditorContent, useEditor } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import Image from '@tiptap/extension-image'
import { ref, watch } from 'vue'

const props = defineProps<{
  modelValue: string | null
  id: string
  ariaDescribedby?: string
}>()

const emit = defineEmits<{ (e: 'update:modelValue', value: string): void }>()

/**
 * The sanitiser allows width and height on <img>; Tiptap does not know them
 * and would drop them on load.
 */
const SizedImage = Image.extend({
  addAttributes() {
    return {
      ...this.parent?.(),
      width: { default: null },
      height: { default: null },
    }
  },
})

/** True while we are writing the prop into the editor, so the resulting
 * update event does not bounce back out as a change the user made. */
const applyingExternal = ref(false)

/**
 * useEditor, not `new Editor`: it re-renders the component on every editor
 * transaction, which is what keeps the toolbar's aria-pressed in step with
 * where the cursor is. A plain shallowRef would leave every button stuck in
 * the state it had when the editor was built. It also destroys the editor on
 * unmount for us.
 */
const editor = useEditor({
  content: props.modelValue ?? '',
  extensions: [
    StarterKit.configure({
      // Tags the sanitiser drops. A button for them would produce markup the
      // server deletes on save.
      code: false,
      codeBlock: false,
      strike: false,
      horizontalRule: false,
      // h1 belongs to the product name on the storefront.
      heading: { levels: [2, 3, 4] },
      link: false, // replaced in Task 2 with one that keeps the title attribute
    }),
    SizedImage,
  ],
  onUpdate: ({ editor }) => {
    if (applyingExternal.value) return

    emit('update:modelValue', editor.isEmpty ? '' : editor.getHTML())
  },
})

watch(
  () => props.modelValue,
  (value) => {
    const current = editor.value?.getHTML() ?? ''
    if (!editor.value || value === current) return
    // An empty document reads as "<p></p>", which is not the same as no value.
    if ((value ?? '') === '' && editor.value.isEmpty) return

    applyingExternal.value = true
    editor.value.commands.setContent(value ?? '', { emitUpdate: false })
    applyingExternal.value = false
  },
)
</script>

<template>
  <div :id="id" class="mt-1 rounded-md border border-gray-300 shadow-sm focus-within:border-gray-900 focus-within:ring-1 focus-within:ring-gray-900">
    <div
      v-if="editor"
      role="toolbar"
      aria-label="Formátování textu"
      class="flex flex-wrap gap-1 border-b border-gray-200 bg-gray-50 p-1"
    >
      <button
        type="button"
        aria-label="Tučné"
        :aria-pressed="editor.isActive('bold')"
        class="rounded px-2 py-1 text-sm font-bold text-gray-700 hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-gray-900 aria-pressed:bg-gray-900 aria-pressed:text-white"
        @click="editor.chain().focus().toggleBold().run()"
      >
        B
      </button>
      <!-- Zbylá tlačítka podle tabulky pod tímto blokem kódu -->
    </div>

    <EditorContent :editor="editor" :aria-describedby="ariaDescribedby" />
  </div>
</template>

<style scoped>
/**
 * Tailwind's preflight strips headings and lists back to plain text, so the
 * editor would show a heading identical to a paragraph. Scoped styles do not
 * reach nodes ProseMirror creates at runtime, hence :deep().
 */
:deep(.ProseMirror) {
  min-height: 14rem;
  padding: 0.75rem;
  outline: none;
}
:deep(.ProseMirror:focus) {
  outline: none;
}
:deep(.ProseMirror h2) {
  font-size: 1.375rem;
  font-weight: 700;
  margin: 1rem 0 0.5rem;
}
:deep(.ProseMirror h3) {
  font-size: 1.175rem;
  font-weight: 700;
  margin: 0.875rem 0 0.5rem;
}
:deep(.ProseMirror h4) {
  font-size: 1rem;
  font-weight: 700;
  margin: 0.75rem 0 0.5rem;
}
:deep(.ProseMirror p) {
  margin: 0.5rem 0;
}
:deep(.ProseMirror ul) {
  list-style: disc;
  padding-left: 1.5rem;
  margin: 0.5rem 0;
}
:deep(.ProseMirror ol) {
  list-style: decimal;
  padding-left: 1.5rem;
  margin: 0.5rem 0;
}
:deep(.ProseMirror blockquote) {
  border-left: 3px solid #d1d5db;
  padding-left: 0.75rem;
  color: #4b5563;
  margin: 0.5rem 0;
}
:deep(.ProseMirror a) {
  color: #1d4ed8;
  text-decoration: underline;
}
:deep(.ProseMirror img) {
  max-width: 100%;
  height: auto;
}
</style>
```

Zbylá tlačítka mají přesně stejný tvar jako to první, liší se ve třech místech:

| `aria-label` | Popisek v tlačítku | `:aria-pressed` | `@click` (vždy `editor.chain().focus()…run()`) |
|---|---|---|---|
| Kurzíva | *I* | `editor.isActive('italic')` | `.toggleItalic()` |
| Podtržené | U | `editor.isActive('underline')` | `.toggleUnderline()` |
| Odstavec | ¶ | `editor.isActive('paragraph')` | `.setParagraph()` |
| Nadpis 2 | H2 | `editor.isActive('heading', { level: 2 })` | `.toggleHeading({ level: 2 })` |
| Nadpis 3 | H3 | `editor.isActive('heading', { level: 3 })` | `.toggleHeading({ level: 3 })` |
| Nadpis 4 | H4 | `editor.isActive('heading', { level: 4 })` | `.toggleHeading({ level: 4 })` |
| Odrážkový seznam | • | `editor.isActive('bulletList')` | `.toggleBulletList()` |
| Číslovaný seznam | 1. | `editor.isActive('orderedList')` | `.toggleOrderedList()` |
| Citace | „ | `editor.isActive('blockquote')` | `.toggleBlockquote()` |
| Vymazat formátování | ✕ | — (bez `aria-pressed`) | `.unsetAllMarks().clearNodes()` |

Textové popisky (B, I, U, H2…) nesou vizuál; **odečítač čte `aria-label`**, protože „H2" ani „¶" samo o sobě nic neřekne. Skupiny odděl `<span class="mx-1 w-px bg-gray-300" aria-hidden="true" />`.

`aria-pressed:` varianta Tailwindu vyžaduje Tailwind 3.4+ (projekt má `^3.4.0`); pokud by nefungovala, použij `:class` s podmínkou místo ní.

- [ ] **Step 5: Nasadit na popis produktu**

V `resources/js/Pages/Modules/Products/Show.vue` nahraď blok s `<textarea id="p-description">` komponentou. Import přidej k ostatním na začátku souboru.

```vue
<div class="sm:col-span-2">
  <label for="p-description" class="block text-sm font-medium text-gray-700">Popis</label>
  <RichTextEditor
    id="p-description"
    v-model="form.description"
    aria-describedby="p-description-hint"
  />
  <p id="p-description-hint" class="mt-1 text-sm text-gray-600">
    Nadpisy, seznamy a odkazy udělá panel nad polem. Vložený text se pročistí při
    uložení.
  </p>
</div>
```

Pozor na `for="p-description"`: `id` teď nese `<div>`, ne formulářový prvek, takže `<label for>` na něj neukazuje platně. Nahraď `<label>` za `<span id="p-description-label">` a komponentě předej `aria-labelledby` — nebo (jednodušeji) nech `<label>` být a dej editační ploše `aria-label="Popis"`. Zvol druhé a zdůvodni komentářem.

- [ ] **Step 6: Sestavit a spustit test**

```bash
npm run build && npx playwright test --config=e2e/playwright.config.ts e2e/tests/rich-text-editor.spec.ts
```

Očekávané: PASS ve všech třech testech.

- [ ] **Step 7: Ruční kontrola v prohlížeči**

Otevři `/admin/m/products/<slug>`, zkus:
- napsat text, dát mu H2/H3/H4, vrátit na odstavec
- odrážkový a číslovaný seznam, vnořit `Tab`em
- vložit text ze schránky z jiné stránky — nesmí přinést barvy ani fonty
- projít celý toolbar `Tab`em a spustit tlačítko `Enter`/`Space`

- [ ] **Step 8: Commit**

```bash
git add package.json package-lock.json resources/js/Components/Ui/RichTextEditor.vue resources/js/Pages/Modules/Products/Show.vue e2e/tests/rich-text-editor.spec.ts
git commit -m "feat(admin): give the product description a rich text editor"
```

---

### Task 2: Odkaz s dialogem

**Files:**
- Modify: `resources/js/Components/Ui/RichTextEditor.vue`
- Test: `e2e/tests/rich-text-editor.spec.ts` (přidat testy)

**Interfaces:**
- Consumes: komponentu z Tasku 1
- Produces: tlačítka `Vložit odkaz` a `Odebrat odkaz`; dialog s polem `aria-label="Adresa odkazu"` a tlačítky `Vložit` / `Zrušit`

- [ ] **Step 1: Napsat padající test**

Přidej do `e2e/tests/rich-text-editor.spec.ts`:

```ts
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
```

- [ ] **Step 2: Spustit, ověřit pád**

```bash
npx playwright test --config=e2e/playwright.config.ts e2e/tests/rich-text-editor.spec.ts -g "link"
```

Očekávané: FAIL — tlačítko `Vložit odkaz` neexistuje.

- [ ] **Step 3: Doplnit Link do schématu**

Ve `<script setup>` komponenty přidej k importům a k `extensions` (StarterKit má `link: false` už z Tasku 1):

```ts
import Link from '@tiptap/extension-link'

/** The sanitiser allows title on <a>; Tiptap does not know it. */
const TitledLink = Link.extend({
  addAttributes() {
    return { ...this.parent?.(), title: { default: null } }
  },
}).configure({
  openOnClick: false,
  autolink: false,
  // Mirrors HtmlSanitizer::ALLOWED_SCHEMES plus relative paths.
  protocols: ['http', 'https', 'mailto', 'tel'],
})
```

- [ ] **Step 4: Přidat kontrolu adresy**

Ve `<script setup>`:

```ts
const linkOpen = ref(false)
const linkUrl = ref('')
const linkError = ref('')

/** Mirrors HtmlSanitizer::isSafeUrl. */
function isSafeUrl(url: string): boolean {
  const value = url.trim()
  if (value === '') return false
  if (value.startsWith('#')) return true
  // "/" is fine, but "//evil.com" and "/\evil.com" are open redirects wearing
  // an internal path (same guard as BlockUrl::isSafe, decision 2026-07-26).
  if (value.startsWith('/')) return !value.startsWith('//') && !value.startsWith('/\\')

  return /^(https?|mailto|tel):/i.test(value)
}

function openLinkDialog() {
  linkUrl.value = editor.value?.getAttributes('link').href ?? ''
  linkError.value = ''
  linkOpen.value = true
}

function applyLink() {
  if (!isSafeUrl(linkUrl.value)) {
    linkError.value = 'Adresa musí začínat http://, https://, mailto:, tel: nebo /.'

    return
  }

  editor.value?.chain().focus().extendMarkRange('link').setLink({ href: linkUrl.value.trim() }).run()
  linkOpen.value = false
}

function removeLink() {
  editor.value?.chain().focus().extendMarkRange('link').unsetLink().run()
}
```

- [ ] **Step 5: Přidat tlačítka a dialog do šablony**

Do toolbaru dvě tlačítka (`Vložit odkaz` → `openLinkDialog`, `Odebrat odkaz` → `removeLink`, druhé `:disabled="!editor.isActive('link')"`). Dialog vykresli pod toolbarem, ne přes `window.prompt`:

```vue
<div v-if="linkOpen" class="flex flex-wrap items-start gap-2 border-b border-gray-200 bg-white p-2">
  <input
    v-model="linkUrl"
    type="text"
    aria-label="Adresa odkazu"
    :aria-invalid="linkError ? 'true' : undefined"
    aria-describedby="rte-link-error"
    class="min-w-0 flex-1 rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
    placeholder="https://"
    @keydown.enter.prevent="applyLink"
    @keydown.esc.prevent="linkOpen = false"
  />
  <button type="button" class="rounded-md bg-gray-900 px-3 py-2 text-sm text-white" @click="applyLink">
    Vložit
  </button>
  <button type="button" class="rounded-md border border-gray-300 px-3 py-2 text-sm" @click="linkOpen = false">
    Zrušit
  </button>
  <p v-if="linkError" id="rte-link-error" class="w-full text-sm text-red-600">{{ linkError }}</p>
</div>
```

Po otevření dialogu přesuň focus do inputu (`nextTick` + `ref`), jinak uživatel klávesnice zůstane na tlačítku a nemá jak psát.

- [ ] **Step 6: Sestavit a spustit testy**

```bash
npm run build && npx playwright test --config=e2e/playwright.config.ts e2e/tests/rich-text-editor.spec.ts
```

Očekávané: PASS, včetně testů z Tasku 1.

- [ ] **Step 7: Commit**

```bash
git add resources/js/Components/Ui/RichTextEditor.vue e2e/tests/rich-text-editor.spec.ts
git commit -m "feat(admin): add link support to the rich text editor"
```

---

### Task 3: Tabulky a zachování cizího obsahu

**Files:**
- Modify: `resources/js/Components/Ui/RichTextEditor.vue`
- Test: `e2e/tests/rich-text-editor.spec.ts` (přidat testy)

**Interfaces:**
- Consumes: komponentu z Tasků 1–2
- Produces: tlačítka `Vložit tabulku`, `Přidat řádek`, `Odebrat řádek`, `Přidat sloupec`, `Odebrat sloupec`, `Smazat tabulku`

- [ ] **Step 1: Napsat padající test na zachování obsahu**

Tohle je nosný test celé vlny: schéma, které neumí tabulku ani obrázek, je zvenčí k nerozeznání od schématu, které je umí — dokud někdo neuloží cizí produkt.

```ts
/**
 * Tiptap drops nodes its schema does not know, so a description carrying a
 * table or an image would lose it just by being opened and saved. The schema
 * knows both; only the table is offered in the toolbar.
 */
test('a table and an image already in the description survive a save', async ({ page }) => {
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
  await page.getByRole('button', { name: 'Uložit', exact: true }).click()
  await expect(page.getByText('Produkt byl uložen.')).toBeVisible()

  const html = await (await page.request.get(shopUrl(`/produkt/${slug}`))).text()

  expect(html).toContain('<th colspan="2">Rozměry</th>')
  expect(html).toContain('<td>30 cm</td>')
  expect(html).toContain('src="/media/demo.png"')
  expect(html).toContain('alt="Náhled"')
  expect(html).toContain('width="120"')
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
```

- [ ] **Step 2: Spustit, ověřit pád**

```bash
npx playwright test --config=e2e/playwright.config.ts e2e/tests/rich-text-editor.spec.ts -g "table|image"
```

Očekávané: FAIL — tabulka po uložení zmizí, tlačítko `Vložit tabulku` neexistuje.

- [ ] **Step 3: Doplnit TableKit do schématu**

```ts
import { TableKit } from '@tiptap/extension-table'
```

a do `extensions`:

```ts
TableKit.configure({
  // Resizing writes a colwidth attribute the sanitiser drops anyway, and the
  // handle is mouse-only.
  table: { resizable: false },
}),
```

- [ ] **Step 4: Přidat tabulková tlačítka**

Do toolbaru, oddělená svislou čárou od zbytku:

| Popisek | Příkaz | `:disabled` |
|---------|--------|-------------|
| Vložit tabulku | `insertTable({ rows: 3, cols: 3, withHeaderRow: true })` | — |
| Přidat řádek | `addRowAfter()` | `!editor.can().addRowAfter()` |
| Odebrat řádek | `deleteRow()` | `!editor.can().deleteRow()` |
| Přidat sloupec | `addColumnAfter()` | `!editor.can().addColumnAfter()` |
| Odebrat sloupec | `deleteColumn()` | `!editor.can().deleteColumn()` |
| Smazat tabulku | `deleteTable()` | `!editor.can().deleteTable()` |

Přidej i styl pro tabulku uvnitř editoru (bez něj splynou buňky):

```css
:deep(.ProseMirror table) {
  border-collapse: collapse;
  width: 100%;
  margin: 0.75rem 0;
}
:deep(.ProseMirror th),
:deep(.ProseMirror td) {
  border: 1px solid #d1d5db;
  padding: 0.375rem 0.5rem;
  text-align: left;
}
:deep(.ProseMirror th) {
  background: #f3f4f6;
  font-weight: 600;
}
```

- [ ] **Step 5: Sestavit a spustit celý soubor**

```bash
npm run build && npx playwright test --config=e2e/playwright.config.ts e2e/tests/rich-text-editor.spec.ts
```

Očekávané: PASS. Pokud test na zachování obsahu padá na chybějícím `width="120"`, `SizedImage` z Tasku 1 nefunguje — oprav ho, nezaškrtávej test.

- [ ] **Step 6: Vrátit demo produkt do původního stavu**

Testy popis produktu přepisují. Ověř, že `DemoShopSeeder` sadu vrací, nebo doplň `test.afterAll`, který popis nastaví zpět — jinak další běh sady startuje z jiného výchozího stavu než ten první.

- [ ] **Step 7: Commit**

```bash
git add resources/js/Components/Ui/RichTextEditor.vue e2e/tests/rich-text-editor.spec.ts
git commit -m "feat(admin): add tables to the rich text editor and keep foreign markup"
```

---

### Task 4: Nasazení na statickou stránku a textový blok homepage

**Files:**
- Modify: `resources/js/Pages/Modules/Pages/Form.vue` (~84–96, `<textarea id="body">`)
- Modify: `resources/js/Pages/Modules/Storefront/Homepage.vue` (~626–640, `<textarea :id="\`html-${block.id}\`">`)
- Test: `e2e/tests/rich-text-editor.spec.ts` (přidat testy)

**Interfaces:**
- Consumes: hotovou komponentu z Tasků 1–3
- Produces: nic nového

- [ ] **Step 1: Napsat padající testy**

```ts
test.describe('rich text editor elsewhere in the admin', () => {
  test.beforeEach(async ({ page }) => {
    await signInAsOwner(page)
  })

  test('the static page body is an editor', async ({ page }) => {
    await page.goto(shopUrl('/admin/m/pages'))
    await page.getByRole('link', { name: /upravit|obchodní podmínky/i }).first().click()

    await expect(page.locator('#body .ProseMirror')).toBeVisible()
    await expect(page.locator('textarea#body')).toHaveCount(0)
  })

  /**
   * The legal templates from wave 3.2 are filled in at "[DOPLŇTE …]" markers
   * and the form warns while any remain. Tiptap carries the text unchanged, so
   * the warning must still fire — it is the only thing standing between a
   * template and a published page that reads as finished.
   */
  test('the placeholder warning still fires', async ({ page }) => {
    await page.goto(shopUrl('/admin/m/pages'))
    await page.getByRole('link', { name: /upravit|obchodní podmínky/i }).first().click()

    const editor = page.locator('#body .ProseMirror')
    await editor.click()
    await page.keyboard.press('Control+a')
    await page.keyboard.press('Delete')
    await editor.pressSequentially('[DOPLŇTE název firmy]')

    await expect(page.getByText(/DOPLŇTE/)).toBeVisible()
  })

  test('the homepage text block is an editor', async ({ page }) => {
    await page.goto(shopUrl('/admin/m/storefront/homepage'))
    await page.getByRole('button', { name: /upravit/i }).first().click()

    await expect(page.locator('.ProseMirror').first()).toBeVisible()
  })
})
```

Selektory na odkaz „Upravit" a tlačítko v page builderu ověř proti skutečnému UI — pokud se liší, uprav test podle reality, ne UI podle testu.

- [ ] **Step 2: Spustit, ověřit pád**

```bash
npx playwright test --config=e2e/playwright.config.ts e2e/tests/rich-text-editor.spec.ts -g "elsewhere"
```

Očekávané: FAIL na obou.

- [ ] **Step 3: Nasadit na statickou stránku**

V `resources/js/Pages/Modules/Pages/Form.vue` nahraď `<textarea id="body">` komponentou. `form.body` je `string | null` — komponenta to zvládá.

Ověř, že `hasPlaceholders` (řádek ~41) čte `form.body` a ne obsah textarey přes DOM.

- [ ] **Step 4: Nasadit na textový blok homepage**

V `resources/js/Pages/Modules/Storefront/Homepage.vue` nahraď `<textarea :id="\`html-${block.id}\`">` komponentou s `:id="\`html-${block.id}\`"` a `v-model="editState.html"`. Panel bloku je užší než stránka produktu — ověř, že se toolbar zalamuje a nepřetéká (`flex-wrap` už na něm je).

- [ ] **Step 5: Sestavit a spustit celou sadu**

```bash
npm run build && npm run e2e
```

Očekávané: celá sada zelená. Sadu nech běžet ve foregroundu.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/Modules/Pages/Form.vue resources/js/Pages/Modules/Storefront/Homepage.vue e2e/tests/rich-text-editor.spec.ts
git commit -m "feat(admin): use the rich text editor for pages and the homepage text block"
```

---

### Task 5: Přístupnost

**Files:**
- Modify: `resources/js/Components/Ui/RichTextEditor.vue` (podle nálezů)
- Test: `e2e/tests/rich-text-editor.spec.ts` (přidat axe blok)

**Interfaces:**
- Consumes: hotový editor
- Produces: nic nového

- [ ] **Step 1: Napsat axe test**

Vzor si vezmi z `e2e/tests/accessibility.spec.ts` (import `AxeBuilder` z `@axe-core/playwright`, blokující jsou jen `critical` a `serious` — sada, která zčervená na neopravovaném nálezu, se začne přeskakovat i s nálezem skutečným).

```ts
test('the product form with the editor has no blocking accessibility findings', async ({ page }) => {
  await signInAsOwner(page)
  await page.goto(shopUrl(`/admin/m/products/${slug}`))
  await page.locator('#p-description .ProseMirror').waitFor()

  const results = await new AxeBuilder({ page }).analyze()
  const blocking = results.violations.filter((v) => ['critical', 'serious'].includes(v.impact ?? ''))

  expect(blocking, JSON.stringify(blocking.map((v) => v.id))).toHaveLength(0)
})
```

- [ ] **Step 2: Spustit**

```bash
npx playwright test --config=e2e/playwright.config.ts e2e/tests/rich-text-editor.spec.ts -g "accessibility"
```

Pokud padá, oprav komponentu podle konkrétního `id` porušení. Časté: `aria-pressed` na prvku, který není `button`; kontrast textu tlačítka; `role="toolbar"` bez `aria-label`.

- [ ] **Step 3: Ruční průchod klávesnicí**

`Tab` do toolbaru, `Tab` mezi tlačítky, `Enter`/`Space` spustí, `Tab` do editační plochy, `Shift+Tab` zpět. Odečítač musí u aktivního formátu hlásit stav (`aria-pressed`).

Pokud toolbar vyjde jako dlouhá řada `Tab` stopů před samotným polem, zvaž šipkovou navigaci uvnitř toolbaru (`roving tabindex`) — ale jen pokud to průchod skutečně zdržuje; předčasně to nedělej.

- [ ] **Step 4: Ověřit, že axe test umí selhat**

Dočasně smaž `aria-label` z jednoho tlačítka a spusť test. Musí zčervenat (`button-name`). Pak `aria-label` vrať.

Zelený a slepý audit jsou zvenčí k nerozeznání (poučení vlny 3.4).

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/Ui/RichTextEditor.vue e2e/tests/rich-text-editor.spec.ts
git commit -m "test(admin): assert the rich text editor has no blocking a11y findings"
```

---

### Task 6: Uzavření

- [ ] **Step 1: Spustit celou E2E sadu a relevantní PHPUnit adresáře**

```bash
npm run e2e
php artisan test tests/Feature/Products --compact
php artisan test tests/Feature/Pages --compact
```

Sady pouštěj po adresářích a ve foregroundu — celá PHPUnit sada jedním příkazem přeteče timeout a sdílená testovací databáze kolabuje.

Server se v této vlně neměnil, takže PHPUnit je kontrola, že se nic nerozbilo bokem, ne nový důkaz.

Akceptační kritérium 6 ze spec (značka mimo allowlist se do uloženého HTML nedostane) kryjí existující testy `HtmlSanitizer`. Ověř, že běží a že se jich nikdo nedotkl:

```bash
php artisan test --filter=HtmlSanitizer --compact
```

Kdyby test na sanitizer neexistoval, je to nález k doplnění — editor v prohlížeči není důkaz o ničem, co se ukládá.

- [ ] **Step 2: Zapsat as-is**

`docs/as-is/2026-08-10-rich-text-editor.md` — mapa změněných částí, plnění spec po sekcích, testy, **povinná sekce Odchylky od specifikace**, technický dluh a pre-deploy checklist (`npm run build` na produkci).

- [ ] **Step 3: Doplnit rozhodnutí do CLAUDE.md**

Do sekce Rozhodnutí, ve stylu okolních zápisů — proč Tiptap, proč schéma zrcadlí sanitizer, proč `Image` bez tlačítka, proč vlastní dialog místo `window.prompt`.

- [ ] **Step 4: Uzavřít vlnu**

Spusť `/finish-wave`.
