# Rich text editor pro HTML pole administrace

**Datum:** 2026-08-10
**Status:** approved
**Související plán:** `docs/superpowers/plans/2026-08-10-rich-text-editor.md`

## Kontext

Tři pole v administraci nájemce nesou HTML, které se tiskne na storefrontu, a všechna
tři se dnes editují jako holá `<textarea>` s nápovědou „Povolené HTML: odstavce, tučné,
kurzíva, seznamy, nadpisy, odkazy, obrázky, tabulky. Ostatní se při uložení odstraní."

- popis produktu (`resources/js/Pages/Modules/Products/Show.vue`)
- obsah statické stránky (`resources/js/Pages/Modules/Pages/Form.vue`)
- textový blok homepage (`resources/js/Pages/Modules/Storefront/Homepage.vue`)

Nájemce je provozovatel e-shopu, ne autor HTML. Psát `<h3>` a `<ul><li>` ručně je
překážka u popisu produktu a u statické stránky (VOP, reklamační řád, vzory z vlny 3.2)
je to práce na tisíce znaků. Vzory právních stránek se navíc doplňují v místech
`[DOPLŇTE …]`, což v textarea plné značek nikdo nenajde.

Server se nemění. `HtmlSanitizer` (rozhodnutí 2026-07-20: čistí se **při zápisu**)
zůstává jedinou autoritou nad tím, co se uloží. Editor je pohodlí, ne obrana —
požadavek poslaný mimo formulář narazí na přesně totéž co dosud.

## Cíle

- [ ] Jedna sdílená komponenta `RichTextEditor.vue` nasazená na všechna tři místa
- [ ] Toolbar pokrývající to, co `HtmlSanitizer` povoluje, a nic navíc
- [ ] Existující obsah (tabulky, obrázky) přežije otevření a uložení beze změny
- [ ] Ovladatelné klávesnicí, WCAG 2.2 AA

## Mimo rozsah

- **Vkládání obrázků** — rozhodnutí vlastníka. Obrázky produktu mají vlastní správu
  (vlna 3.8); druhá cesta k nahrání souboru mimo `ProductImageService` by obešla
  kontrolu MIME typu a raster-only politiku.
- **Zobrazení / editace zdrojového HTML** — rozhodnutí vlastníka.
- **Blok kódu, přeškrtnutí, vodorovná čára** — `HtmlSanitizer` je zahazuje. Tlačítko,
  které vyrobí značku, co ji server smaže, je lež o tom, co e-shop umí.
- **Editor v storefrontu** — týká se výhradně administrace (Inertia SPA).
- **Sanitizace na klientu jako náhrada serverové** — klient sanitizaci nesmí vlastnit.

## Požadavky

### Backend

Beze změny. Žádná migrace, žádný nový endpoint, žádná změna validace.

### Frontend

**Komponenta** `resources/js/Components/Ui/RichTextEditor.vue`

| Prop | Typ | Význam |
|------|-----|--------|
| `modelValue` | `string \| null` | HTML |
| `id` | `string` | pro `<label for>` |
| `ariaDescribedby` | `string?` | napojení na stávající nápovědu pod polem |

Emituje `update:modelValue` s `editor.getHTML()`. Prázdný dokument emituje prázdný
řetězec, ne `<p></p>` — jinak by se pole nikdy nečetlo jako nevyplněné.

**Schéma editoru = allowlist `HtmlSanitizer`.** StarterKit s vypnutým `codeBlock`,
`code`, `strike`, `horizontalRule` a `heading` omezeným na úrovně 2–4 (`h1` patří
názvu produktu na storefrontu a sanitizer ho zahazuje). `Underline` a `Link` jsou
od Tiptapu 3 součástí StarterKitu, samostatné balíčky nejsou potřeba. Navíc `Image`
a `TableKit`.

`Image` je ve schématu **bez tlačítka v toolbaru**: Tiptap zahazuje uzly, které jeho
schéma nezná, takže popis produktu nesoucí `<img>` by o něj při prvním uložení tiše
přišel. Schéma ho tedy zná, aby ho zachovalo; nabídnout ho neumí.

**Atributy, které Tiptap ve výchozím stavu nezná, se doplní.** `HtmlSanitizer`
povoluje na `img` také `width` a `height` a na `a` také `title`; Tiptap je nezná a
při načtení by je zahodil. Obě rozšíření je proto doplňují (`addAttributes`) —
jinak by editor tiše měnil cizí obsah jen tím, že se otevřel.

Naopak `b` se uloží jako `strong` a `i` jako `em`. To je změna značky, ne ztráta
významu, a `HtmlSanitizer` obojí povoluje.

**Toolbar**

| Skupina | Tlačítka |
|---------|----------|
| Text | tučné, kurzíva, podtržení |
| Blok | odstavec, nadpis 2, nadpis 3, nadpis 4 |
| Seznamy | odrážkový, číslovaný |
| Ostatní | citace, odkaz (vložit / odebrat) |
| Tabulka | vložit, řádek +/−, sloupec +/−, smazat tabulku |
| Úklid | vymazat formátování |

Tabulková tlačítka jsou vidět vždy, ale ta pro úpravu řádků a sloupců jsou neaktivní
(`disabled`), dokud kurzor není v tabulce — zmizelá tlačítka posouvají zbytek toolbaru
a uživatel je hledá tam, kde je viděl posledně.

**Odkaz** se zadává v malém dialogu s inputem, ne přes `window.prompt`. Nativní dialog
nejde ostylovat, blokuje vlákno a v Chrome jde vypnout zaškrtávátkem „už nezobrazovat",
po kterém by tlačítko přestalo cokoli dělat bez zpětné vazby. Vložená adresa projde
stejným pravidlem jako `HtmlSanitizer::isSafeUrl` — relativní `/`, `#`, jinak
`http`/`https`/`mailto`/`tel`.

**Přístupnost**

- `role="toolbar"` s `aria-label`, tlačítka `type="button"` (jinak odešlou formulář)
- `aria-pressed` na tlačítku formátu, který je pod kurzorem aktivní
- `aria-label` na každém tlačítku (ikona sama nic nesděluje odečítači)
- viditelný focus ring, kontrast textu i ikon podle WCAG 2.2 AA
- editační plocha nese `aria-describedby` na stávající nápovědu

### Závislosti

Nové, do `dependencies` (vedle `lucide-vue-next`), všechny `^3.29.2` (ověřeno na npm
2026-08-10): `@tiptap/vue-3`, `@tiptap/starter-kit`, `@tiptap/extension-image`,
`@tiptap/extension-link`, `@tiptap/extension-table`.

`extension-link` je tranzitivní závislost StarterKitu, ale komponenta ho importuje
přímo (aby doplnila atribut `title`), a importovat balíček, který v `package.json`
není, je závislost na cizím stromu závislostí — proto je uvedený explicitně.

Admin bundle poroste přibližně o 90 kB gzip. Storefront nulově — jsou to oddělené
vstupy Vite a storefront nese jen `resources/js/storefront.js`. Limit 100 kB gzip
z `.claude/rules/storefront-rendering.md` se týká storefrontu, ne administrace.

## Akceptační kritéria

1. V popisu produktu jde myší nebo klávesnicí udělat nadpis, odrážkový seznam, tučný
   text a odkaz; po uložení storefront vydá `<h3>`, `<ul><li>`, `<strong>` a `<a>`.
2. Produkt, jehož popis obsahuje `<table>` a `<img>`, se otevře, uloží beze změny a
   obojí v uloženém HTML zůstane.
3. Editor nemá tlačítko pro vložení obrázku ani pro zobrazení zdrojového HTML.
4. Celý toolbar je dosažitelný klávesnicí a každé tlačítko má čitelný název pro
   odečítač; axe nad záložkou nehlásí `critical` ani `serious`.
5. Vzory právních stránek: varování o `[DOPLŇTE …]` v `Pages/Form.vue` funguje dál —
   text projde editorem beze změny.
6. Značka mimo allowlist (např. `<script>` vložený schránkou) se do uloženého HTML
   nedostane — serverová sanitizace platí beze změny.

## Technické poznámky

- `HtmlSanitizer::ALLOWED` je jediná pravda o povolených značkách. Schéma editoru se
  od něj odvozuje ručně; rozejít se mohou jen tak, že se změní jedno bez druhého —
  proto je vazba pojmenovaná v komentáři komponenty.
- `Pages/Form.vue:41` počítá `form.body.includes('[DOPLŇTE')`. Tiptap nese text uvnitř
  odstavců beze změny, takže podmínka platí; kryje to akceptační kritérium 5.
- Homepage editor sedí v panelu bloku (`Storefront/Homepage.vue`), který je užší než
  stránka produktu — toolbar se musí zalamovat, ne přetékat.

## Testy

Playwright (`e2e/`):

1. popis produktu — napsat text, dát mu nadpis 3, udělat seznam, uložit, ověřit výstup
   na storefrontu
2. produkt s tabulkou v popisu — otevřít, uložit beze změny, tabulka přežije
3. axe nad záložkou „Základní" detailu produktu

## Reference

- Rozhodnutí 2026-07-20 (sanitizace vlastní, čistí se při zápisu)
- Rozhodnutí 2026-07-20 (drag&drop nikdy jako jediná cesta — analogicky platí pro
  ovládání toolbaru)
- `.claude/rules/storefront-rendering.md` (rozpočet JS se týká storefrontu)
- As-is (po dokončení): `docs/as-is/2026-08-10-rich-text-editor.md`
