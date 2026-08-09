# Vlna 3.8 — koruny v administraci, rozměry produktu, obrázky — implementační plán

> **Stav: hotovo (2026-08-09, v0.43.0).** As-is: [`docs/as-is/2026-08-09-produkt-a-ceny.md`](../../as-is/2026-08-09-produkt-a-ceny.md).

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Nájemce zadává ceny v korunách jako všude jinde, produkt má rozměry, které něco dělají, a obrázky jde seřadit.

**Architecture:** Jeden převodník korun na haléře na hranici požadavku (`MoneyInput`), tři nové sloupce na `products`, a ovládání k `ProductImageService::reorder()`, které existuje od vlny 1.2 bez UI.

**Spec:** [`docs/superpowers/specs/2026-08-09-vlna-38-produkt-a-ceny-design.md`](../specs/2026-08-09-vlna-38-produkt-a-ceny-design.md)

## Global Constraints

- **Žádná nová závislost.**
- **Uložená hodnota zůstává v haléřích.** Mění se jen to, co nájemce píše.
- **Převod dělá server**, nikdy JavaScript — stejné pravidlo jako u ceny bez DPH (3.7).
- **Nikdy float na float.** Řetězec → celé číslo haléřů; desetinné číslo se do uložené hodnoty nedostane.
- **Drag&drop nikdy jako jediná cesta** (rozhodnutí 2026-07-20) — tlačítka jsou závazná, přetažení je nadstavba.
- **Storefront zůstává Blade SSR.**
- **Kód anglicky, texty česky. PHP 8.3. `./vendor/bin/pint`. Testy po adresářích.**

---

### Task 1: Převodník korun

**Files:**
- Create: `app/Core/Money/MoneyInput.php`
- Test: `tests/Unit/MoneyInputTest.php`

- [x] **Step 1: Napiš padající testy**

1. `1790` → 179000, `1790,50` → 179050, `1790.50` → 179050.
2. Mezery a nedělitelné mezery uvnitř čísla se ignorují (`1 790,50` — přesně to, co vrátí kopírování z tabulky).
3. Prázdný řetězec a `null` → `null`, **ne nula**. Nula je cena; prázdno je „nevyplněno".
4. `1790,555` → chyba, ne tiché zaokrouhlení. Peníze pod haléř neexistují.
5. Nečíselný vstup → chyba.
6. **Žádný float se nedostane do výsledku** — `0,07` je přesně 7, ne 6 (klasická past `(int)(0.07*100)`).

- [x] **Step 2: Implementuj.** Statická třída vedle `Money`, protože je to vstupní parsování, ne aritmetika hodnoty.
- [x] **Step 3: Ověř** — `php artisan test tests/Unit --compact`
- [x] **Step 4: Commit** — `feat(money): parse a price typed in korunas`

---

### Task 2: Koruny v administraci

**Files:**
- Modify: `Modules/Products/Http/Requests/{StoreProductRequest,UpdateProductVariantRequest}.php`, `Modules/Shipping/Http/Requests/{StoreShippingMethodRequest,StorePaymentMethodRequest}.php`, `Modules/Discounts/Http/Requests/*.php`, odpovídající Vue obrazovky
- Test: `tests/Feature/Modules/Products/PriceInKorunasTest.php`

- [x] **Step 1: Napiš padající testy**

1. Produkt uložený s `1790,50` má v databázi 179050.
2. Totéž pro akční cenu, nákupní cenu, cenu varianty.
3. Doprava, platba, sleva, minimální hodnota košíku.
4. **Formulář vrací tutéž hodnotu, jakou nájemce zadal** — uložit a znovu otevřít nesmí cenu změnit.
5. Prázdná nákupní cena zůstane prázdná, ne 0 Kč.
6. **Cena bez DPH z vlny 3.7 přijímá koruny taky** a stále platí, že při obojím rozhoduje cena s DPH.

- [x] **Step 2: Implementuj.** Převod v `prepareForValidation()`; validační pravidla se mění z `integer` na `MoneyInput`-ověřený vstup.
- [x] **Step 3: Ověř + `npm run build`**
- [x] **Step 4: Commit** — `feat(admin): let prices be typed in korunas, not haléře`

---

### Task 3: Rozměry produktu

**Files:**
- Create: migrace `products.length_mm/width_mm/height_mm`
- Modify: `Modules/Products/Models/Product.php`, `StoreProductRequest`, `ProductAdminController`, `Show.vue`, `storefront/show.blade.php`, `Modules/Packeta/Services/ShipmentSubmitter.php`
- Test: `tests/Feature/Modules/Products/ProductDimensionsTest.php`

- [x] **Step 1: Napiš padající testy**

1. Rozměry jde uložit a nechat prázdné.
2. Záporný nebo nesmyslně velký rozměr se odmítne.
3. Vyplněné rozměry vidí zákazník na detailu produktu.
4. **Nevyplněné se nezobrazí vůbec** — ne prázdný řádek „Rozměry: —".
5. Zásilkovna dostane rozměry, když jsou vyplněné.
6. **Podání funguje beze změny, když vyplněné nejsou** — regresní test, aby volitelné pole nerozbilo existující cestu.

- [x] **Step 2: Implementuj.**
- [x] **Step 3: Ověř + commit** — `feat(products): give a product dimensions that reach the customer and the carrier`

---

### Task 4: Obrázky a rozvržení

**Files:**
- Modify: `resources/js/Pages/Modules/Products/Show.vue`
- Test: `tests/Feature/Modules/Products/ProductImageOrderTest.php`, `e2e/tests/product-images.spec.ts`

- [x] **Step 1: Napiš padající testy**

1. Přesun obrázku tlačítkem změní pořadí a **projeví se na storefrontu** (galerie i hlavní obrázek v katalogu).
2. Krajní obrázek nejde posunout za hranici.
3. Řazení zvedne generaci page cache (`reorder` píše přes query builder, takže si bump musí zvednout sám — poznámka v `PageCacheObserver`).
4. E2E: přetažení souboru na plochu ho nahraje.
5. E2E: Uložit/Smazat se **nekreslí** na záložce Obrázky.

- [x] **Step 2: Implementuj.** Tlačítka nahoru/dolů (vzor kategorie), plocha na přetažení jako doplněk, přesun tlačítek Uložit/Smazat do panelů, kde formulář ukládá.
- [x] **Step 3: Ověř + `npm run build` + commit** — `feat(products): let images be reordered and dropped in`

---

### Task 5: Uzavření

- [x] **Step 1:** PHPUnit po adresářích, `npm run e2e`.
- [x] **Step 2: Ruční průchod na demu** — zadat cenu v korunách, nahrát dva obrázky přetažením, přeskládat je, zkontrolovat storefront.
- [x] **Step 3:** as-is, STATUS, CLAUDE.md, `VERSION` → `0.43.0`, CHANGELOG, merge.

---

## Rizika

| Riziko | Dopad | Mitigace |
|---|---|---|
| Float v převodu | Cena o haléř vedle, tiše | Řetězec → int, test na `0,07`; nikdy `(int)($x*100)` |
| Prázdné pole se uloží jako 0 Kč | Produkt zdarma | `null` zůstává `null`; vlastní test |
| Zapomenuté místo v administraci | Půlka formulářů v haléřích, půlka v korunách | Vypsaný seznam v Tasku 2 + test na každý modul |
| Řazení obrázků nezvedne page cache | Nové pořadí se projeví až za deset minut | Bump ve volajícím (query builder nevyvolá observer) |
| Rozměry rozbijí podání zásilky | Nájemce nemůže expedovat | Regresní test na podání bez rozměrů |
| Přetažení jako jediná cesta | Nepoužitelné z klávesnice (WCAG 2.1.1) | Tlačítko zůstává, plocha je doplněk |
