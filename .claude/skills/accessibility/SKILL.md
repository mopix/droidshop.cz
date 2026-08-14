---
name: accessibility
description: Checklist přístupnosti (WCAG 2.2 AA) pro DroidShop.cz — Blade SSR storefront nájemce + Vue 3/Inertia administrace. Použij při návrhu komponenty, před PR měnícím UI/render a při manuálním auditu před nasazením. WCAG 2.2 AA + kontext EAA (směrnice EU 2019/882).
---

# Checklist přístupnosti — DroidShop.cz (WCAG 2.2 AA)

> Kontrolní seznam pro obě UI vrstvy platformy. Každá stránka a komponenta ve vlastním kódu by měla
> splňovat tato kritéria před nasazením.
>
> **Směrnice EU 2019/882 (EAA)**, účinná od 28. 6. 2025, se týká e-shopů a komerčních služeb v EU.
> Storefront provozuje **nájemce**, ale bariéru do něj zavádí **naše šablona** — nájemce ji nemá jak
> opravit. Přístupnost storefrontu je proto vlastnost produktu, ne odpovědnost zákazníka.

## Dvě vrstvy, různá pravidla

| Vrstva | Kde | Renderuje | Kdo ji vidí |
|---|---|---|---|
| **Storefront** | `Modules/*/resources/views/**/*.blade.php` | Blade SSR, **bez JS musí projít celá nákupní cesta** | koncový zákazník nájemce (veřejné, EAA) |
| **Ostrůvky** | `resources/js/storefront.js` | vanilla JS nad hotovým HTML | tentýž zákazník, jen s JS |
| **Administrace** | `resources/js/Pages/`, `Components/`, `Layouts/` | Vue 3 + Inertia SPA, `noindex` | nájemce a jeho personál |

Projekt **nemá** TypeScript, i18n (jen čeština) ani dark mode jako primární režim. Nepiš doporučení,
která tyhle věci předpokládají.

**Ostrůvek nikdy nesmí být jediným zdrojem obsahu ani jedinou cestou k akci** — viz
`.claude/rules/storefront-rendering.md`. Bariéra „funguje jen s JS" je na storefrontu porušení
akceptačního kritéria, ne jen a11y nález.

## Jak používat

1. **Při návrhu komponenty** — projdi relevantní sekce (formulář → §6, modal → §11/§14.3,
   produktová karta → §14.1, pokladna → §14.4).
2. **Před PR** — zaškrtni body, na kterých jsi pracoval. Co neumíš ověřit → agent `a11y-checker`.
3. **Před nasazením** — manuální průchod + `npm run e2e`.

## Audit nástroje

- **`a11y-checker` subagent** (`.claude/agents/a11y-checker.md`) — statická analýza `.blade.php` a `.vue`.
- **axe-core v Playwright** — `e2e/accessibility.spec.ts` nad 7 stránkami; blokuje `critical` a `serious`.
- **`e2e/axe-sanity.spec.ts`** — podstrčí obrázek bez `alt` a trvá na tom, že ho audit chytí.
  **Zelený a slepý audit jsou zvenčí k nerozeznání** — když sanity test přestane fungovat, audit negarantuje nic.
- **Manuálně:** klávesnice-only, **prohlížeč s vypnutým JS**, VoiceOver (macOS) + Safari, NVDA (Windows) + Firefox.

---

## 1. Adaptivní UI

- [ ] Relativní jednotky (`rem`, `em`, `%`, `ch`) pro text, mezery, kontejnery ([1.4.10](https://www.w3.org/WAI/WCAG22/Understanding/reflow), [1.4.4](https://www.w3.org/WAI/WCAG22/Understanding/resize-text))
- [ ] **Nezakazovat** zoom (`user-scalable=no` / `maximum-scale=1` v meta viewport)
- [ ] Funkční při 400 % zoomu, při zvýšeném text spacing, v landscape i portrait — bez horizontálního scrollu
- [ ] Mřížka katalogu se přizpůsobí — cena ani „Do košíku" se neztratí na malé šířce
- [ ] Sbalené menu administrace i mobilní drawer ovladatelné na dotyk i klávesnicí

## 2. Struktura obsahu

- [ ] `<h1>`–`<h6>` dle logické hierarchie, bez přeskakování ([1.3.1](https://www.w3.org/WAI/WCAG22/Understanding/info-and-relationships))
- [ ] **Jeden `<h1>` na stránku.** Na homepage ho nese blok `hero` — proto smí být na stránce jen jeden hero blok (page builder to vynucuje)
- [ ] `<ol>`/`<ul>` + `<li>` pro seznamy (položky košíku, historie objednávek, výdejní místa)

## 3. Kvalita kódu

- [ ] `lang="cs"` na `<html>` v obou layoutech
- [ ] Validní HTML a ARIA; ARIA atributy odpovídají roli elementu
- [ ] **`aria-label` na `<div>` bez role je zakázaný atribut**, ne jen neúčinný — implicitní role `generic`
      ho nepřipouští (`aria-prohibited-attr`). Buď dej elementu roli, nebo použij `<label for>`
- [ ] Žádný `div soup` — sémantický element před ARIA náhradou

## 4. Obrázky a ikony

- [ ] Dekorativní: `alt=""` / CSS background / `<svg aria-hidden="true" focusable="false">`
- [ ] Informativní (fotka produktu, logo nájemce): `alt` popisuje obsah
- [ ] Ikona nesoucí informaci (stav objednávky, stav e-shopu) má textovou alternativu
- [ ] Ikony Lucide v administraci: dekorativní vedle textové položky → `aria-hidden`; ve sbaleném railu,
      kde text není vidět, musí mít tlačítko přístupné jméno

## 5. Tabulky (objednávky, doklady, produkty, zásilky)

- [ ] `<caption>` nebo `aria-labelledby` na datové tabulce
- [ ] `scope="col"` / `scope="row"` na `<th>`
- [ ] `<table>` jen pro tabulární data (ne layout)
- [ ] Řaditelné hlavičky: `aria-sort="ascending|descending|none"`
- [ ] Podbarvení řádku podle stavu je **jen pomůcka** — stav musí zůstat i slovy ve svém sloupci ([1.4.1](https://www.w3.org/WAI/WCAG22/Understanding/use-of-color))

## 6. Formuláře

- [ ] `<label for="id">` asociované s každým polem
- [ ] Povinná pole: `required` + `aria-required="true"`; vizuální `*` má textovou alternativu
- [ ] `autocomplete` na relevantních polích (`email`, `tel`, `given-name`, `family-name`,
      `street-address`, `postal-code`, `current-password`, `new-password`)
- [ ] Help text přes `aria-describedby` (formát ceny, povolené přípony obrázku, tvar EAN)
- [ ] Server-side validace jako primární (Laravel Form Request); inline chyba: `aria-describedby` + `aria-invalid="true"`
- [ ] Souhrnná chyba nahoře formuláře v `role="alert"`
- [ ] **Redundant Entry** ([3.3.7](https://www.w3.org/WAI/WCAG22/Understanding/redundant-entry)) — fakturační
      adresu předvyplň z doručovací, přihlášenému zákazníkovi z profilu
- [ ] Upload: `<input type="file">` má `<label>`, stav nahrávání přes `aria-live`, chyba (velikost, typ) popsaná textem
- [ ] **Plocha na přetažení nikdy jako jediná cesta** — nahrání musí jít i výběrem souboru (§11)
- [ ] Cena se zadává v korunách; pole `type="text"` + `inputmode="decimal"` (desetinná čárka není v `type="number"` platná) — `<label>` musí říct jednotku

## 7. Styly textu

- [ ] `<strong>` pro důležité (cena, sleva), `<em>` pro důraz — ne jen CSS bold na `<span>`
- [ ] Přeškrtnutá původní cena u akce má textovou alternativu (samotné `<s>` čte jen část odečítačů)

## 8. Klávesnice (kritické)

- [ ] **Žádné** `outline: none` bez alternativy ([2.4.7](https://www.w3.org/WAI/WCAG22/Understanding/focus-visible)); focus indikátor ≥ 3:1 kontrast ([1.4.11](https://www.w3.org/WAI/WCAG22/Understanding/non-text-contrast))
- [ ] **Focus Appearance** ([2.4.13](https://www.w3.org/WAI/WCAG22/Understanding/focus-appearance)) — indikátor dostatečně velký a kontrastní
- [ ] **Focus Not Obscured** ([2.4.11](https://www.w3.org/WAI/WCAG22/Understanding/focus-not-obscured-minimum)) — sticky hlavička ani **cookie lišta** nesmí překrýt fokusovaný prvek
- [ ] Native `<button>`/`<a>`/`<input>` — **ne** `<div @click>`
- [ ] `tabindex` jen `0` nebo `-1`, **nikdy kladné**
- [ ] **Skrytý panel musí být `invisible` nebo `display:none`, ne jen posunutý mimo obrazovku.**
      `-translate-x-full` nechá menu v pořadí tabulátoru a odečítač ho dál čte
- [ ] Focus není uvězněn (kromě modálů)
- [ ] Po Inertia navigaci přesun focusu na `<h1>` / hlavní oblast nové stránky
- [ ] Skip link „Přeskočit na obsah" na začátku obou layoutů

## 9. Odkazy

- [ ] Nové okno jen když je nutné; pak SR-only „(otevírá se v novém okně)" + `rel="noopener noreferrer"`
- [ ] **`target="_blank"` nenasazovat plošně z editoru** — interní odkaz v textu nájemce musí zůstat bez něj
- [ ] Akce („Do košíku", „Odebrat", „Kopírovat odkaz") = `<button type="button">`, ne `<a>`
- [ ] Odkaz na detail produktu má jako text název produktu, ne „zobrazit"

## 10. Navigace na stránce

- [ ] `<title>` per stránka: storefront přes `seo-meta.blade.php`, administrace přes Inertia `<Head>`
- [ ] Landmarky `<header>`, `<nav>`, `<main>`, `<footer>` v obou layoutech
- [ ] Drobečková navigace a filtry katalogu jako `<nav aria-label>`
- [ ] Stránkování katalogu má textové odkazy (nejen šipky) a `aria-current="page"` na aktivní straně
- [ ] **Consistent Help** ([3.2.6](https://www.w3.org/WAI/WCAG22/Understanding/consistent-help)) — kontakt a právní stránky na stejném místě v patičce napříč stránkami

## 11. Pointer a pohyb

- [ ] Tooltip/popover lze zavřít ESC, je perzistentní při hoveru
- [ ] **Dragging Movements** ([2.5.7](https://www.w3.org/WAI/WCAG22/Understanding/dragging-movements)) —
      **v tomhle projektu závazné pravidlo:** řazení kategorií, obrázků produktu i bloků homepage má
      tlačítka nahoru/dolů. Tažení smí být jen nadstavba, nikdy jediná cesta
- [ ] **Target Size** ([2.5.8](https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum)) ≥ 24×24 CSS px; klíčové akce („Do košíku", „Objednat zavazně k platbě") ≥ 44×44 px

## 12. Status zprávy

- [ ] Container statusu `role="status"` (`aria-live="polite"`) ([4.1.3](https://www.w3.org/WAI/WCAG22/Understanding/status-messages))
- [ ] Loading (zpracování platby, podání zásilky, import CSV): `role="status"` + SR-only text, ne jen spinner
- [ ] Změny stavu (přidáno do košíku, kupón uplatněn, nastavení uloženo) ohlášené přes `aria-live`
- [ ] Kritická chyba (platba selhala, kupón přestal platit): `role="alert"` — střídmě
- [ ] **Bez JS se změna stavu projeví reloadem stránky** — hláška musí být i v serverem vyrenderovaném HTML, ne jen v ostrůvku

## 13. Animace a `prefers-reduced-motion`

- [ ] Animace (přechody, hover karet, vysunutí menu) ve `@media (prefers-reduced-motion: no-preference)`
- [ ] Při `reduce` — vypnout nebo zkrátit pod 150 ms; žádný auto-play ani parallax

## 14. Klíčové obrazovky (per komponenta)

### 14.1. Produktová karta (`product-card.blade.php`)
- [ ] Nadpis karty je odkaz na detail se **jménem produktu** jako textem
- [ ] Cena má textovou hodnotu včetně měny; u plátce DPH je zřejmé, které číslo je s daní
- [ ] Badge „−N %" má textovou alternativu, ne jen barvu; řádek o nejnižší ceně za 30 dní je čitelný text
- [ ] Vyprodáno/skladem textově, ne jen barevná tečka

### 14.2. Detail produktu
- [ ] `<h1>` = název produktu; galerie má `alt` a přepínání náhledů ovladatelné klávesnicí
- [ ] **Výběr varianty je server-rendered** (radio nebo `<select>` s `<label>`); JS ostrůvek jen přepisuje
      cenu a dostupnost. Bez JS musí jít variantu vybrat a odeslat
- [ ] Přepnutí varianty ostrůvkem musí ohlásit změnu ceny a dostupnosti (`aria-live`), jinak ji odečítač mine
- [ ] „Do košíku" ≥ 44×44 px; nedostupná varianta `aria-disabled` **s uvedeným důvodem**
- [ ] Popis produktu psaný nájemcem v rich text editoru — hierarchie nadpisů začíná na `<h2>` (H1 patří názvu)

### 14.3. Modální dialog (administrace)
- [ ] `role="dialog"` + `aria-modal="true"` + `aria-labelledby`
- [ ] Focus trap dokud otevřený; ESC zavře; focus restore na spouštěč
- [ ] Zavírací tlačítko `aria-label="Zavřít"`; scroll pozadí zablokovaný
- [ ] **Mazací akce mají potvrzovací dialog** s textovým varováním (viz `CLAUDE.md` → Mazací akce).
      Výjimka: odebrání položky z košíku (reverzibilní, potvrzuje se bez dialogu)

### 14.4. Košík a pokladna (Blade SSR + progressive enhancement)
- [ ] **Celá cesta projde bez JS** — akceptační kritérium §16.3, hlídá `e2e/checkout-no-js.spec.ts`.
      Bez JS je pokladna o krok delší (platby se nabídnou až po volbě dopravy) — to je v pořádku, ale
      pořadí kroků musí být čitelné pro odečítač
- [ ] Krok pokladny má nadpis a je zřejmé, kolikátý z kolika je
- [ ] Souhrn objednávky jako struktura (položky, sleva, doprava, DPH rekapitulace), ne vizuální tabulka
- [ ] Výběr dopravy a platby = `<fieldset>` + `<legend>`, radio s `<label>`
- [ ] **Výběr výdejního místa Zásilkovny má server-rendered formulář jako primární cestu**; widget se
      načítá až na kliknutí a je pouhá nadstavba
- [ ] Chyba platby: `role="alert"`, textový popis, opakování bez ztráty zadaných dat
- [ ] Odkaz na obchodní podmínky u souhlasu vede na publikovanou stránku, ne do 404
- [ ] Tlačítko nese jednoznačný text o platební povinnosti; částka na něm musí souhlasit s tím, co se naúčtuje
- [ ] QR kód platby má textovou alternativu (číslo účtu, částka, VS) — QR sám o sobě není přístupný

### 14.5. Účet zákazníka
- [ ] Historie objednávek dle §5; stav objednávky i platby textově
- [ ] Stažení faktury: odkaz s názvem dokladu, ne „PDF"
- [ ] Sledování zásilky: číslo zásilky jako text, odkaz na dopravce označený jako externí

### 14.6. Administrace nájemce (`AdminLayout.vue`)
- [ ] Levé menu je `<nav aria-label>`; aktivní položka `aria-current="page"`
- [ ] Sbalení do ikon: tlačítko má `aria-expanded`; ve sbaleném railu **jsou vidět všechny položky**
      (nadpisy sekcí se nevykreslují — schovat položky za neotevíratelnou sekci by administraci znepřístupnilo)
- [ ] Mobilní drawer: `invisible` když zavřený (§8), focus trap když otevřený, ESC zavře
- [ ] Nástěnka: čísla mají popisky, ne jen velký font
- [ ] Formuláře nastavení (`SettingsPage`/`SettingsGrid`/`SettingsCard`) dle §6; tlačítka Uložit jen na záložkách, které ukládají

### 14.7. Rich text editor (`RichTextEditor.vue`)
- [ ] Editační plocha: `role="textbox"` + `aria-multiline="true"` + `aria-label` přes `editorProps.attributes`
      (atribut vázaný na `<EditorContent>` skončí na obalu, kam se focus nedostane)
- [ ] **Každé tlačítko toolbaru má `aria-label`** — glyf („B", „H2") stačí pravidlu `button-name`,
      takže axe ztrátu labelu **nechytí**; ověřuj přímo přes přístupné jméno
- [ ] Stav tlačítka (aktivní formát) přes `aria-pressed`, ne jen barvou
- [ ] Editor je ovladatelný klávesnicí bez myši; nabízené formátování odpovídá tomu, co server nechá projít

### 14.8. Cookie lišta (`app/Core/Consent/`)
- [ ] Ovladatelná klávesnicí, nepřekrývá fokusovaný prvek (§8 — 2.4.11)
- [ ] **„Přijmout vše" a „Odmítnout vše" mají shodné třídy a rovnocennou vizuální váhu** — nerovnocenná
      volba znamená, že souhlas není svobodný (EDPB 03/2022). Hlídá to test, okem se to v review neuhlídá
- [ ] Bez JS lišta po rozhodnutí nezmizí (je v cachovaném HTML) — rozhodnutí se přesto uloží; nesmí blokovat obsah

### 14.9. Onboarding a registrace
- [ ] Labely, `autocomplete`, chyby dle §6; skupiny v `<fieldset>`/`<legend>`
- [ ] **Accessible Authentication** ([3.3.8](https://www.w3.org/WAI/WCAG22/Understanding/accessible-authentication-minimum)) —
      žádný cognitive test jako jediná cesta; povolit vložení hesla ze správce hesel
- [ ] Kontrola dostupnosti subdomény ohlášená přes `aria-live` (výsledek se mění bez reloadu)
- [ ] Souhlas s VOP: `<label>`, chyba popsaná textem, odkaz na znění

### 14.10. Zaheslovaný storefront
- [ ] Formulář hesla má `<label>` a `autocomplete="current-password"`
- [ ] Vyčerpaný počet pokusů popsaný textem, ne jen mlčením

## 15. Barvy a kontrast (branding nájemce)

- [ ] Kontrast textu ≥ 4.5:1 (normální), ≥ 3:1 (velký); UI prvky ≥ 3:1 ([1.4.11](https://www.w3.org/WAI/WCAG22/Understanding/non-text-contrast))
- [ ] **Barvu storefrontu si volí nájemce** — čitelný text nad ní dopočítává `Contrast` helper (WCAG luminance).
      Nikdy nepiš do šablony natvrdo barvu textu nad brandovou plochou; ptej se helperu
- [ ] Sanitizace hex barvy je bezpečnostní opatření (CSS injekce), ne a11y — neobcházet ji kvůli kontrastu
- [ ] **Informace nikdy jen barvou** — stav objednávky, stav e-shopu, badge slevy má vždy i text

## 16. Před nasazením (manuální průchod)

- [ ] Agent `a11y-checker` přes změněné soubory
- [ ] `npm run e2e` zelené — včetně `axe-sanity.spec.ts` a scénářů bez JS
- [ ] Klávesnicí: katalog → detail (přepnout variantu) → košík → pokladna → děkovná stránka; otevřít a zavřít modal v administraci
- [ ] **S vypnutým JS** táž cesta až po dokončenou objednávku
- [ ] VoiceOver + Safari nebo NVDA + Firefox: nákupní cesta
- [ ] 400 % zoom, `prefers-reduced-motion: reduce`, mobilní šířka — bez horizontálního scrollu

## 17. Reference

- [WCAG 2.2 AA](https://www.w3.org/TR/WCAG22/) · [quick reference](https://www.w3.org/WAI/WCAG22/quickref/?versions=2.2)
- [Co je nového ve WCAG 2.2](https://www.w3.org/WAI/standards-guidelines/wcag/new-in-22/)
- [Směrnice EU 2019/882 (EAA)](https://eur-lex.europa.eu/eli/dir/2019/882/oj) · [ETSI EN 301 549](https://www.etsi.org/deliver/etsi_en/301500_301599/301549/)
- [W3C ARIA Authoring Practices Guide](https://www.w3.org/WAI/ARIA/apg/patterns/) · [axe-core rules](https://dequeuniversity.com/rules/axe/)
- [`a11y-checker` agent](../../agents/a11y-checker.md) · [`storefront-rendering.md`](../../rules/storefront-rendering.md)
