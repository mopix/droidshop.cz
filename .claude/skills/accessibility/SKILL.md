---
name: accessibility
description: Pre-release checklist přístupnosti (WCAG 2.2 AA) pro WooShop.cz — Vue 3 + Inertia.js + TypeScript marketplace. Použij při návrhu komponenty, před PR měnícím UI/render, a při manuálním auditu před release. WCAG 2.2 AA + kontext EAA (směrnice EU 2019/882).
---

# Checklist přístupnosti — WooShop.cz (WCAG 2.2 AA)

> Předreleasový kontrolní seznam pro **Vue 3 + Inertia.js** UI (`resources/js/`). Každá komponenta
> a stránka ve vlastním kódu by měla splňovat tato kritéria před nasazením na produkci.
>
> WooShop je veřejný komerční e-shop / tržiště:
> - **WCAG 2.2 AA** — cílový standard.
> - **Směrnice EU 2019/882 (EAA)** — European Accessibility Act, účinná od 28. 6. 2025 — týká se
>   e-shopů a komerčních služeb v EU. Pro WooShop závazné.
>
> Brand barvy a jejich ověřené kontrasty jsou v [`CLAUDE.md`](../../../CLAUDE.md) → sekce Grafika.
> Tento checklist je nepřepisuje — odkazuje na ně.

## Jak používat

1. **Při návrhu komponenty** — projdi relevantní sekce (formulář → §6, modal → §11/§14.3,
   produktová karta → §14.1, checkout → §14.4).
2. **Před PR** — zaškrtni body, na kterých jsi pracoval. Co neumíš ověřit → najmi agenta `a11y-checker`.
3. **Před release** — kompletní průchod manuálně + automatizovaný audit (Playwright + axe-core, Lighthouse).

## Audit nástroje

- **`a11y-checker` subagent** (`.claude/agents/a11y-checker.md`) — statická analýza `.vue`/`.ts` v `resources/js/`.
- **axe-core v Playwright** — e2e a11y test (`e2e/`), viz §15.
- **Lighthouse** — a11y score jako merge gate.
- **Manuálně:** NVDA (Windows) + Firefox, VoiceOver (macOS) + Safari, klávesnice-only v Chrome.

---

## 1. Adaptivní UI

- [ ] Relativní jednotky (`rem`, `em`, `%`, `ch`) místo fixních px pro text, mezery, kontejnery ([1.4.10](https://www.w3.org/WAI/WCAG22/Understanding/reflow), [1.4.4](https://www.w3.org/WAI/WCAG22/Understanding/resize-text))
- [ ] **Nezakazovat** zoom (`user-scalable=no` / `maximum-scale=1` v meta viewport)
- [ ] Funkční při 400 % zoomu, při zvýšeném text spacing, v landscape i portrait — bez horizontálního scrollu
- [ ] Produktová mřížka / katalog se přizpůsobí — žádný klíčový prvek (cena, „Koupit“) se neztratí na malé šířce
- [ ] Tmavý i světlý režim — oba splňují kontrast (CLAUDE.md → Grafika)

## 2. Struktura obsahu

- [ ] `<h1>`–`<h6>` dle logické hierarchie, bez přeskakování úrovní ([1.3.1](https://www.w3.org/WAI/WCAG22/Understanding/info-and-relationships))
- [ ] Jeden `<h1>` na stránku (název produktu na detailu, název sekce v dashboardu)
- [ ] `<ol>`/`<ul>` + `<li>` pro seznamy (produkty v košíku, historie objednávek, výplaty)

## 3. Kvalita kódu

- [ ] `lang` na `<html>` dle aktivního jazyka i18n (`cs`/`en`/`pl`/`sk`/`de`); `lang` na vložených cizojazyčných termínech
- [ ] Validní HTML a ARIA (axe-core); ARIA atributy odpovídají roli elementu
- [ ] Žádný `div soup` — viz pravidla v CLAUDE.md → Přístupnost

## 4. Obrázky a ikony

- [ ] Dekorativní: `alt=""` / CSS background / `<svg aria-hidden="true" focusable="false">`
- [ ] Informativní (náhled/obálka produktu, screenshot šablony): `alt` popisuje obsah, nebo `<svg role="img" aria-label>`
- [ ] Ikona nesoucí informaci (typ produktu, stav „schváleno/zamítnuto“) má textovou alternativu (ne jen vizuál)
- [ ] Avatar prodejce: `alt` se jménem prodejce, nebo `alt=""` pokud je jméno hned vedle

## 5. Tabulky (objednávky, výplaty, transakce, moderace)

- [ ] `<caption>` nebo `aria-labelledby` na datové tabulce
- [ ] `scope="col"` / `scope="row"` na `<th>`
- [ ] `<table>` jen pro tabulární data (ne layout)
- [ ] Řaditelné hlavičky (řazení objednávek, výplat): `aria-sort="ascending|descending|none"`
- [ ] Export transakcí pro účetnictví prodejce má i přístupný HTML náhled, ne jen download CSV/PDF

## 6. Formuláře (auth, upload produktu, ceny, profil prodejce)

- [ ] `<label for="id">` asociované s každým polem
- [ ] Povinná pole: `required` + `aria-required="true"`; vizuální `*` má textovou alternativu
- [ ] `autocomplete` na relevantních polích (`email`, `username`, `current-password`, `new-password`, fakturační údaje)
- [ ] Help text přes `aria-describedby` (např. „max 20 MB“ u uploadu, formát ceny)
- [ ] Server-side validace jako primární (Laravel); inline error: `aria-describedby` + `aria-invalid="true"` na poli
- [ ] Souhrnná chyba nahoře formuláře v `role="alert"`
- [ ] **Redundant Entry** ([3.3.7](https://www.w3.org/WAI/WCAG22/Understanding/redundant-entry)) — nevyžaduj
      opakované zadání téhož v rámci jednoho flow (např. fakturační údaje předvyplň z profilu)
- [ ] Upload souboru: `<input type="file">` má `<label>`, stav nahrávání ohlášen přes `aria-live`,
      chyba (přes 20 MB, nepovolený typ) textově popsaná

## 7. Styly textu

- [ ] `<strong>` pro důležité (cena, sleva), `<em>` pro důraz — ne jen CSS bold/italic na `<span>`

## 8. Klávesnice (kritické)

- [ ] **Žádné** `outline: none` bez alternativy ([2.4.7](https://www.w3.org/WAI/WCAG22/Understanding/focus-visible)); focus indicator ≥ 3:1 kontrast ([1.4.11](https://www.w3.org/WAI/WCAG22/Understanding/non-text-contrast))
- [ ] **Focus Appearance** ([2.4.13](https://www.w3.org/WAI/WCAG22/Understanding/focus-appearance), nové ve 2.2) — focus indikátor dostatečně velký a kontrastní
- [ ] **Focus Not Obscured** ([2.4.11](https://www.w3.org/WAI/WCAG22/Understanding/focus-not-obscured-minimum), nové ve 2.2) — sticky header / cookie lišta / chat widget nesmí překrýt fokusovaný prvek
- [ ] Native `<button>`/`<a>`/`<input>` — **ne** `<div @click>`
- [ ] `tabindex` jen `0` nebo `-1`, **nikdy kladné**
- [ ] Focus není uvězněn (kromě modálů); skryté prvky (`v-if`/`display:none`) nejsou fokusovatelné
- [ ] Vlastní klávesové zkratky nezablokují Tab navigaci ani SR virtuální kurzor
- [ ] Po Inertia navigaci (změna stránky bez reloadu) přesun focusu na `<h1>` / hlavní oblast nové stránky
- [ ] Skip link „Přeskočit na obsah“ na začátku stránky

## 9. Odkazy

- [ ] Vyhýbat se otevírání v novém okně defaultně; pokud nutné (externí download URL produktu), SR-only „(otevírá se v novém okně)“ + `rel="noopener noreferrer"`
- [ ] Akce typu „Přidat do košíku“, „Sdílet“, „Kopírovat odkaz“ = `<button type="button">`, ne `<a>`
- [ ] Odkaz na detail produktu má smysluplný text (název produktu), ne „klikni zde“

## 10. Navigace na stránce

- [ ] `<title>` / Inertia `<Head>` titulek per stránka (název produktu, „Košík“, „Můj dashboard“)
- [ ] Landmarky `<header>`, `<nav>`, `<main>`, `<footer>`
- [ ] Filtry katalogu / breadcrumbs jako `<nav aria-label>`
- [ ] **Consistent Help** ([3.2.6](https://www.w3.org/WAI/WCAG22/Understanding/consistent-help), nové ve 2.2) — kontakt/nápověda na stejném místě napříč stránkami

## 11. Pointer a pohyb

- [ ] Tooltip/popover (info o provizi, stavu výplaty) lze zavřít ESC, je perzistentní při hoveru
- [ ] **Dragging Movements** ([2.5.7](https://www.w3.org/WAI/WCAG22/Understanding/dragging-movements), nové ve 2.2) — drag-and-drop (řazení produktů, upload přetažením) má klikací alternativu
- [ ] **Target Size** ([2.5.8](https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum), nové ve 2.2) ≥ 24×24 CSS px; klíčové akce („Koupit“, „Aktivovat výplaty“) komfortně ≥ 44×44 px

## 12. Status zprávy

- [ ] Container statusu `role="status"` (`aria-live="polite"`) ([4.1.3](https://www.w3.org/WAI/WCAG22/Understanding/status-messages))
- [ ] Loading (načítání katalogu, zpracování platby): `role="status"` + SR-only text (ne jen spinner)
- [ ] Změny stavu (přidáno do košíku, produkt schválen, výplata vyžádána) ohlášené přes `aria-live`
- [ ] Kritická chyba (platba selhala): `role="alert"` (`aria-live="assertive"`) — střídmě

## 13. Animace a `prefers-reduced-motion`

- [ ] Animace (přechody, hover efekty karet, skeletony) ve `@media (prefers-reduced-motion: no-preference)`
- [ ] Při `reduce` — disable nebo zkrátit na < 150 ms; žádný nucený auto-play / parallax

## 14. Klíčové komponenty WooShopu (per komponent)

### 14.1. Produktová karta (katalog/mřížka)
- [ ] Celá karta nebo její nadpis je odkaz na detail (`<a>` se smysluplným textem = název produktu)
- [ ] Cena má textovou hodnotu vč. měny (ne jen vizuál), případně `<strong>`
- [ ] Badge „Nový“, „Sleva“, „Bestseller“ má textovou alternativu, ne jen barvu (CLAUDE.md → kontrast)
- [ ] Tlačítko „Do košíku“ je `<button>` s názvem produktu v `aria-label`, pokud text sám nestačí
- [ ] Hodnocení hvězdičkami: textová alternativa „4,5 z 5“ (ne jen ikony)

### 14.2. Detail produktu
- [ ] `<h1>` = název produktu; galerie náhledů má `alt`; klávesnicová obsluha přepínání náhledů
- [ ] Sekce (popis, cena, prodejce, recenze) jako landmarky/regiony se srozumitelnými nadpisy
- [ ] „Přidat do košíku“ / „Koupit“ ≥ 44×44 px, jasný focus, stav `aria-disabled` pokud nedostupné + důvod

### 14.3. Modální dialog (košík, potvrzení mazání, reset limitu stažení)
- [ ] `role="dialog"` + `aria-modal="true"` + `aria-labelledby`
- [ ] Focus trap dokud otevřený; ESC zavře; focus restore na spouštěč
- [ ] Close button `aria-label="Zavřít"`; background scroll zablokovaný
- [ ] **Všechny mazací akce** (CLAUDE.md → Mazací akce) = potvrzovací dialog s textovým varováním, ne jen barva

### 14.4. Checkout / košík (Stripe)
- [ ] Krok platby má jasný nadpis a pořadí kroků čitelné pro SR
- [ ] Souhrn objednávky (položky, cena, měna, Stripe poplatek) jako struktura, ne jen vizuální tabulka
- [ ] Stripe Elements / embedded checkout: ověř a11y vloženého iframe, zajisti label/instrukce v okolí
- [ ] Chyba platby: `role="alert"`, textový popis, možnost opakovat bez ztráty dat (Redundant Entry §6)
- [ ] Úspěch: oznámení přes `role="status"` + přesměrování s focusem na potvrzení

### 14.5. Souhlas + stažení digitálního produktu (EU)
- [ ] Checkbox „Souhlasím, že ztrácím právo na odstoupení od smlouvy“ (směrnice 2011/83/EU) má `<label>`,
      je povinný, stav chyby textově popsán — bez souhlasu se download nezpřístupní (CLAUDE.md → Produkty)
- [ ] Tlačítko „Stáhnout“ stav (počet zbývajících stažení / neomezeno) čitelný pro SR
- [ ] Externí download URL: SR-only „(otevírá se v novém okně)“ + `rel`

### 14.6. Seller dashboard (výplaty, upload, reset limitu)
- [ ] „Aktivovat výplaty“ (Stripe onboarding) — stav onboardingu textově (ne jen barevný badge)
- [ ] Tabulka výplat/transakcí dle §5; částky vč. měny; stav `paid_out` textově
- [ ] Schválit/zamítnout reset limitu stažení = `<button>` s jasným labelem + potvrzení

### 14.7. Auth (Laravel Breeze)
- [ ] Labely, `autocomplete`, error handling dle §6; `<fieldset>`/`<legend>` u skupin
- [ ] **Accessible Authentication** ([3.3.8](https://www.w3.org/WAI/WCAG22/Understanding/accessible-authentication-minimum), nové ve 2.2) —
      žádný cognitive test (přepisování zkomoleného textu) jako jediná možnost; povolit vložení hesla / password manager
- [ ] Toast oznámení (přihlášeno / chyba) přes `role="status"` / `role="alert"`

### 14.8. Admin moderace produktů
- [ ] Fronta ke schválení jako tabulka/seznam dle §5; stav (čeká/schváleno/zamítnuto) textově, ne jen barvou
- [ ] Akce schválit/zamítnout = `<button>`; zamítnutí s důvodem (povinné pole, §6)

### 14.9. Cookie lišta (ePrivacy)
- [ ] Klávesnicí ovladatelná, nepřekrývá focus (§8 — 2.4.11), tlačítka „Přijmout/Odmítnout“ rovnocenná a dosažitelná

## 15. Pre-release audit (manual check)

Před deploy změn ovlivňujících UI:

- [ ] Spustit subagent `a11y-checker` přes změněné soubory
- [ ] axe-core (Playwright, `e2e/`) zelené; Lighthouse a11y ≥ 95
- [ ] Manuálně klávesnicí: katalog → detail → košík → checkout → souhlas → stažení; otevřít/zavřít modal
- [ ] NVDA + Firefox (Windows) a VoiceOver + Safari (macOS): projít nákupní flow
- [ ] Tmavý i světlý režim; high contrast; `prefers-reduced-motion: reduce`; 400 % zoom — bez horizontálního scrollu

## 16. Reference

- [WCAG 2.2 AA](https://www.w3.org/TR/WCAG22/) · [quick reference](https://www.w3.org/WAI/WCAG22/quickref/?versions=2.2)
- [Co je nového ve WCAG 2.2](https://www.w3.org/WAI/standards-guidelines/wcag/new-in-22/)
- [Směrnice EU 2019/882 (EAA)](https://eur-lex.europa.eu/eli/dir/2019/882/oj)
- [ETSI EN 301 549](https://www.etsi.org/deliver/etsi_en/301500_301599/301549/) — harmonizovaný standard
- [W3C ARIA Authoring Practices Guide](https://www.w3.org/WAI/ARIA/apg/patterns/) — vzory komponent
- [axe-core rules](https://dequeuniversity.com/rules/axe/)
- [`a11y-checker` agent](../../agents/a11y-checker.md) · brand barvy → [`CLAUDE.md`](../../../CLAUDE.md)
