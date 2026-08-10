# As-is: rich text editor pro HTML pole administrace

**Datum:** 2026-08-10
**Verze:** 0.45.0
**Spec:** [`docs/superpowers/specs/2026-08-10-rich-text-editor-design.md`](../superpowers/specs/2026-08-10-rich-text-editor-design.md)
**Plán:** [`docs/superpowers/plans/2026-08-10-rich-text-editor.md`](../superpowers/plans/2026-08-10-rich-text-editor.md)

## Co se změnilo

Tři pole v administraci nájemce nesou HTML, které se tiskne na storefrontu, a všechna tři
se editovala jako holá `<textarea>` s nápovědou „Povolené HTML: …". Nájemce je provozovatel
e-shopu, ne autor HTML; u statické stránky (VOP, reklamační řád, vzory z vlny 3.2) to je
práce na tisíce znaků a markery `[DOPLŇTE …]` v textarea plné značek nikdo nenajde.

Nově jedna sdílená komponenta postavená na Tiptapu 3.29.

| Soubor | Role |
|--------|------|
| `resources/js/Components/Ui/RichTextEditor.vue` | nová komponenta, `v-model` na HTML řetězec |
| `resources/js/Pages/Modules/Products/Show.vue` | popis produktu |
| `resources/js/Pages/Modules/Pages/Form.vue` | obsah statické stránky |
| `resources/js/Pages/Modules/Storefront/Homepage.vue` | textový blok homepage |
| `e2e/support/a11y.ts` | sdílený `auditPage`/`STANDARD` (dřív duplikát v každém spec souboru) |
| `e2e/tests/rich-text-editor.spec.ts` | 17 scénářů |
| `security_warnings.md` | zápis nálezu na serveru, viz níže |

**Server se nezměnil.** Žádná migrace, žádný endpoint, žádná změna PHP validace.
`HtmlSanitizer` čistí při zápisu a zůstává jedinou autoritou nad tím, co se uloží — editor
je pohodlí, ne obrana.

## Plnění spec

| Požadavek | Stav |
|-----------|------|
| Jedna komponenta na všech třech místech | splněno |
| Toolbar pokrývá allowlist `HtmlSanitizer` a nic navíc | splněno |
| Existující obsah (tabulky, obrázky) přežije otevření a uložení | splněno, pinnuto testem s doloženým červeným během |
| Ovladatelné klávesnicí, WCAG 2.2 AA | splněno, axe bez `critical`/`serious` |
| Bez vkládání obrázků a bez zdrojového HTML | splněno |

Toolbar: tučné, kurzíva, podtržení · odstavec, H2, H3, H4 · odrážkový a číslovaný seznam ·
citace · odkaz (vložit/odebrat, vlastní dialog) · tabulka (vložit, řádek ±, sloupec ±,
smazat) · vymazat formátování.

Schéma: StarterKit s vypnutým `code`, `codeBlock`, `strike`, `horizontalRule`, nadpisy
omezené na 2–4 (`h1` patří názvu produktu). `Image` je ve schématu **bez tlačítka** — Tiptap
zahazuje uzly, které nezná, takže popis nesoucí `<img>` by o něj přišel už při otevření.

## Testy

17 scénářů v `e2e/tests/rich-text-editor.spec.ts`, celá E2E sada 81 zelených. PHPUnit
neměněn (376 testů v dotčených oblastech ověřeno, že se nic nerozbilo bokem).

**Čtyři testy v této sadě byly během vlny odhaleny jako neschopné selhat.** Tři „obsah
přežije uložení" ukládaly bez editace — a uložení bez editace posílá hodnotu z Inertia
props, která nikdy neprojde přes `getHTML()`, takže by prošly i se schématem, které tabulky
a obrázky zahazuje. Čtvrtý ověřoval varování o `[DOPLŇTE …]` na právní šabloně, která ty
markery obsahuje už při načtení, takže procházel i s úplně rozbitou vazbou editoru na
formulář. Všechny přepsané a s doloženým červeným během.

Poučení je totéž jako z vlny 3.4: zelený a slepý test jsou zvenčí k nerozeznání. Každý
test, který má něco držet, musel v této vlně ukázat, že umí zčervenat.

## Odchylky od specifikace

**`Underline` a `Link` nejsou samostatné balíčky.** Spec je vyjmenovala; StarterKit 3.x je
už obsahuje, takže se instalovaly jen `@tiptap/vue-3`, `@tiptap/starter-kit`,
`@tiptap/extension-image`, `@tiptap/extension-link`, `@tiptap/extension-table`, plus
`@tiptap/pm` a `@tiptap/extension-underline` doplněné po finální revizi — obojí se
importovalo nebo používalo tranzitivně, což je závislost na cizím stromu závislostí.

**Plán říkal `link: false` v Tasku 1.** Přepsáno: schéma bez Link marku zahodí existující
`<a>` už při načtení, tedy přesně ta ztráta, kterou globální omezení vlny zakazuje. Link
zůstává registrovaný od začátku, Task 2 ho jen vyměnil za variantu s atributem `title`.

**Komponenta má prop `ariaLabel` navíc.** Spec ho nejmenovala. `id` sedí na `<div>`, na
který `<label for>` nemůže platně ukazovat, takže editační plocha potřebuje vlastní název —
předává se přes `editorProps.attributes`, protože atribut vázaný v šabloně na
`<EditorContent>` skončí na obalu o úroveň výš a odečítač ho na editační ploše nenajde.

**Výstup neprochází `getHTML()` syrový.** `collapseSingleParagraphListItems()` rozbaluje
`<li><p>text</p></li>` na `<li>text</li>`; Tiptapův `listItem` obaluje obsah do odstavce a
sanitizer to propustí, takže by se do katalogu ukládala vnořená struktura navíc.

**`SizedImage` a `TitledLink` jsou na Tiptapu 3.29 no-opy.** Stock rozšíření už `width`,
`height` i `title` deklarují. Ponechány jako pojistka pro případ, že je budoucí verze ze
svých výchozích hodnot vypustí; komentáře to říkají přímo, aby je nikdo nesmazal jako mrtvý
kód ani jim nepřipsal práci, kterou dnes nedělají. Testy oba atributy hlídají.

**Klientská `isSafeUrl` je přísnější než serverová.** Odmítá protokolově relativní `//host`
a `/\host`, které `HtmlSanitizer::isSafeUrl` propouští. Odchylka je v bezpečném směru —
klient odmítne, co by server uložil, takže nikomu neřekne „uloženo" o hodnotě, kterou pak
server změní. Precedent je `BlockUrl::isSafe` (rozhodnutí 2026-07-26).

## Známé chování (vědomé, nezaznamenané jako chyba)

- **`<label for>` ukazuje na `<div>`.** Kliknutí na popisek pole už needituje kurzor do
  editoru. Odstranit znamená přepsat komponentu na `label` prop stylem `TextField.vue`.
- **`<thead>` se při načtení sloučí do `<tbody>`.** Sanitizer ho povoluje, `prosemirror-tables`
  pro něj nemá uzel. Řádky ani buňky `<th>` se neztrácejí.
- **Prázdný nadpis přežije jako `<h2></h2>`.** Smazání textu nadpisu nevrátí pole do
  prázdného stavu; `isTrulyEmpty()` považuje za prázdný jen samotný prázdný odstavec.
- **Toolbar je 17 tabulátorových zastávek před editační plochou.** Klávesnicí ovladatelné
  (WCAG 2.1.1 splněno), ale ARIA APG vzor pro toolbar je jedna zastávka se šipkami.
- **Zákaz tlačítka pro obrázek neznamená, že obrázek nemůže vzniknout.** Vložení obsahu ze
  schránky z jiné stránky přinese `<img src="https://…">` a ten přežije i sanitizer jako
  hotlink. Base64 zahazuje Tiptap při parsování, takže nemůže nastat stav „editor obrázek
  ukazuje, server uložil prázdný `<img>`".
- **`role="textbox"` přepne část odečítačů do formulářového režimu** a může tak uvnitř
  editoru potlačit procházení nadpisů, seznamů a tabulek. Je to doporučený vzor ARIA APG pro
  `contenteditable` a lépe podporovaná alternativa neexistuje.
- **Osm tlačítek nemá test na výstup** (podtržení, číslovaný seznam, citace, odstavec, H2,
  H4, vymazat formátování, odebrat odkaz).

## Nález mimo rozsah vlny

`HtmlSanitizer::isSafeUrl` přijímá protokolově relativní `//evil.com` v `href` psaném
nájemcem — prohlížeč to čte jako `https://evil.com`, tedy open redirect maskovaný jako
interní odkaz v popisu produktu. `BlockUrl::isSafe` ten guard má, sanitizer ne. Server byl
v této vlně zmrazený, takže nález je zapsaný v `security_warnings.md` i s návrhem opravy.

## Technický dluh

- `auditPage`/`STANDARD` jsou sdílené, ale zbytek E2E sady si helpery dál drží po svém
- `ToolbarButton` nebyl vyčleněn; 17 tlačítek opakuje stejný řetězec tříd
- Nájemce nemá jak u odkazu zvolit otevření v novém okně (dřív ho dostal vždy, nechtěně)

## Pre-deploy

- [ ] `npm run build` (žádná migrace)
- [ ] Po nasazení otevřít jeden existující produkt s tabulkou nebo obrázkem v popisu, uložit
      beze změny a ověřit, že se obsah nezměnil
