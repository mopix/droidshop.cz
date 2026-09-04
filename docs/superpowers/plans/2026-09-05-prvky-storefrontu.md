# Prvky storefrontu a šablona `katalog` — implementační plán

> **Pro agenta:** Použij `superpowers:subagent-driven-development` nebo `superpowers:executing-plans`. Kroky mají `- [ ]`.

**Cíl:** Doplnit storefrontu prvky, na kterých stojí referenční e-shop — bloky titulky, fasetové filtry, doplňky produktu — a teprve nad nimi postavit třetí šablonu.

**Architektura:** Prvky jsou platformní, ne šablonové. Bloky přibývají jako nové `BlockType` s vlastním payloadem a editorem; vlastnosti produktu jsou číselník nájemce s pivotem a rozšířeným `ProductQuery`; doplněk zakládá vlastní řádek košíku i objednávky, aby prošel do dokladu se svou sazbou. Každý prvek renderuje server, JavaScript je vždy jen vylepšení.

**Tech stack:** Laravel 13, MySQL 8, Blade SSR, Vue 3 + Inertia (administrace), Tailwind, PHPUnit, Playwright.

**Spec:** [`docs/superpowers/specs/2026-09-05-prvky-storefrontu-design.md`](../specs/2026-09-05-prvky-storefrontu-design.md)

**Stav: dokončeno 2026-09-05.** As-is včetně odchylek: [`docs/as-is/2026-09-05-prvky-storefrontu.md`](../../as-is/2026-09-05-prvky-storefrontu.md).

---

## Global Constraints

- **Storefront je Blade SSR.** Každý nový prvek musí být v HTML první odpovědi a použitelný bez JavaScriptu (`.claude/rules/storefront-rendering.md`).
- **Cenová logika jen na serveru.** Doplňky mění částku k zaplacení; JavaScript ji nesmí počítat ani navrhovat.
- **Každý nový query parametr patří do whitelistu `PageCacheKey` a musí se normalizovat.** Nenormalizovaný parametr znamená buď dva záznamy s identickým HTML, nebo neomezené množství záznamů z jednoho skenu.
- **Filtrovaná stránka je `noindex` s canonicalem na nefiltrovanou.** Jinak vzniknou tisíce skoro stejných URL v indexu.
- **Tenant izolace.** Nové tabulky nesou `tenant_id` a `BelongsToTenant`; ke každé obrazovce test, že nájemce A nevidí data nájemce B.
- **Doklad je zákonná evidence.** Doplněk na faktuře musí mít vlastní sazbu a součet musí sedět na to, co zákazník odsouhlasil.
- **Před commitem PHP:** `./vendor/bin/pint` na dotčené soubory. **Po Blade/CSS změně:** `npm run build`.
- **Testy pouštěj po adresářích, ve foregroundu.**

---

## Etapa A — nové bloky titulky

Na konci etapy si nájemce poskládá titulku z pěti nových bloků a všechny fungují bez JavaScriptu. Šablony je zatím renderují ve své dosavadní estetice.

### Task A1: Payload, validace a limity

**Files:**
- Modify: `Modules/Storefront/Enums/BlockType.php`, `Http/Requests/UpdateBlockRequest.php`
- Test: `tests/Feature/Storefront/HomepageBlockPayloadTest.php`

- [ ] **Step 1: Padající test** — pět nových typů má výchozí payload; blok s devíti slidy neprojde validací; položka bez povinného pole neprojde; neznámá ikona v `usp_strip` neprojde.
- [ ] **Step 2: Ověř červený běh.**
- [ ] **Step 3: Rozšiř `BlockType`** o `Slider`, `UspStrip`, `ProductTabs`, `CategoryMosaic`, `BannerGrid` a jejich `defaultPayload()`.
- [ ] **Step 4: Validace položkových polí.** Limity z spec (slidy 1–8, USP 2–6, záložky 2–5, bannery 2–3). Limit není kosmetika: titulka s dvaceti slidy je stránka, kterou nikdo nedonačte, a page cache ji uloží celou.
- [ ] **Step 5: Testy, pint, commit.**

### Task A2: Render bloků v základní šabloně

**Files:**
- Create: `Modules/Storefront/Resources/views/components/blocks/{slider,usp-strip,product-tabs,category-mosaic,banner-grid}.blade.php`
- Create: `Modules/Storefront/Resources/views/components/usp-icon.blade.php`
- Test: `tests/Feature/Storefront/HomepageBlocksRenderTest.php` (rozšíření)

- [ ] **Step 1: Padající test** — každý blok vyrenderuje svůj obsah; slider má v HTML **všechny** slidy; `product_tabs` bez parametru ukáže první záložku a ostatní jsou odkazy; `?zalozka=` přepne obsah.
- [ ] **Step 2: Ověř červený běh.**
- [ ] **Step 3: Napiš komponenty.** Slider = vodorovný pás se `scroll-snap`, tečky jsou kotvy na slidy. Bez JS tedy funguje posouvání i skok na slide.
- [ ] **Step 4: `usp-icon`** — pevná sada ikon jako inline SVG, vzor `NavIcon.vue` v administraci. Neznámá ikona = žádná, nikdy fallback kolečko.
- [ ] **Step 5: Testy, build, pint, commit.**

### Task A3: `?zalozka=` v page cache

**Files:**
- Modify: `app/Core/PageCache/PageCacheKey.php`
- Test: `tests/Feature/PageCache/PageCacheKeyTest.php` (rozšíření)

- [ ] **Step 1: Padající test** — dvě záložky nesdílí klíč; neznámá hodnota `zalozka` klíč nemění (spadne na výchozí).
- [ ] **Step 2–3: Doplň normalizaci** vedle `razeni` a `skladem`.

> Bez tohohle kroku by první návštěvník uložil záložku „Obrazy" a druhý by pod „Tapety" dostal jeho HTML. Je to stejná třída chyby jako cachovat podle cookie.

- [ ] **Step 4: Testy, pint, commit.**

### Task A4: Editor bloků v administraci

**Files:**
- Modify: `resources/js/Pages/Modules/Storefront/Homepage.vue` (+ nové dílčí komponenty)
- Test: `tests/Feature/Storefront/HomepageAdminTest.php` (rozšíření)

- [ ] **Step 1: Padající test** — uložení slideru se třemi slidy; přidání a odebrání položky; přesun položky nahoru a dolů.
- [ ] **Step 2: Ověř červený běh.**
- [ ] **Step 3: Editor položkových bloků.** Jedna sdílená komponenta pro „seznam položek s pořadím", ne pět zvlášť — pět kopií znamená pět míst, kde se zapomene na přístupnost tlačítek pořadí.
- [ ] **Step 4: Pořadí tlačítky, ne tažením** (WCAG 2.1.1, rozhodnutí 2026-07-20).
- [ ] **Step 5: Testy, build, pint, commit.**

---

## Etapa B — vlastnosti produktu a fasetové filtry

### Task B1: Datový model vlastností

**Files:**
- Create: migrace `product_attributes`, `product_attribute_values`, `product_attribute_value_product`
- Create: `Modules/Products/Models/ProductAttribute.php`, `ProductAttributeValue.php`
- Create: `Modules/Products/Services/AttributeWriter.php`
- Test: `tests/Feature/Modules/Products/ProductAttributeTest.php`

**Interfaces:** `AttributeWriter::create/update/delete`, `AttributeWriter::syncForProduct(Product, array<int>)`

- [ ] **Step 1: Padající test** — vlastnost s hodnotami; slug se generuje a je unikátní v rámci vlastnosti; vlastnost používaná produktem nejde smazat; nájemce A nevidí číselník nájemce B.
- [ ] **Step 2: Ověř červený běh.**
- [ ] **Step 3: Migrace.** Unikát `(tenant_id, code)` a `(attribute_id, slug)`; pivot s `tenant_id` a unikátem, index pro filtrování.
- [ ] **Step 4: Modely a writer.**
- [ ] **Step 5: Testy, pint, commit.**

### Task B2: Filtrování v katalogu

**Files:**
- Modify: `app/Core/Catalog/ProductQuery.php`, `Modules/Products/Services/EloquentProductCatalog.php`
- Test: `tests/Feature/Modules/Products/ProductFacetTest.php`

- [ ] **Step 1: Padající test** — dvě hodnoty jedné vlastnosti = **sjednocení**; dvě vlastnosti = **průnik**; neznámý kód i hodnota se ignorují, nikdy nevrací prázdný výpis omylem; filtr respektuje viditelnost produktu a tenant scope.
- [ ] **Step 2: Ověř červený běh.**
- [ ] **Step 3: `ProductQuery::fromInput()`** čte `vlastnost[kod]=slug,slug`, normalizuje (seřadí, odduplikuje, zahodí neznámé).
- [ ] **Step 4: Dotaz.** Per vlastnost jeden `whereExists` nad pivotem — ne `whereHas` v cyklu s `orWhere`, to je způsob, jak si vyrobit průnik tam, kde má být sjednocení.
- [ ] **Step 5: Počty u hodnot** (kolik produktů zbyde). Počítá se **v rámci aktuálního filtru bez té vlastnosti**, jinak filtr nabízí volby, které vedou na prázdno.
- [ ] **Step 6: Testy, pint, commit.**

### Task B3: Fasety ve výpisu

**Files:**
- Modify: `Modules/Categories/Http/Controllers/CategoryStorefrontController.php`, `Modules/Storefront/Http/Controllers/SearchController.php`
- Create: `Modules/Storefront/Resources/views/components/facet-panel.blade.php`
- Modify: `app/Core/PageCache/PageCacheKey.php`, `Modules/Storefront/Support/Seo.php`
- Test: `tests/Feature/Storefront/StorefrontFacetTest.php`

- [ ] **Step 1: Padající test** — GET s filtrem zúží výpis; panel je `<form method="get">`; filtrovaná stránka je `noindex` a canonical míří na nefiltrovanou; dvě pořadí hodnot sdílí klíč cache; neznámá hodnota nevyrobí nový záznam.
- [ ] **Step 2: Ověř červený běh.**
- [ ] **Step 3: Panel jako formulář** s checkboxy, odesílaný tlačítkem. Ostrůvek smí odeslat sám; bez JS musí stačit tlačítko.
- [ ] **Step 4: `noindex` + canonical.**
- [ ] **Step 5: Normalizace `vlastnost` v klíči cache.**
- [ ] **Step 6: Testy, build, pint, commit.**

### Task B4: Administrace vlastností

**Files:**
- Create: `Modules/Products/Http/Controllers/AttributeAdminController.php`, requesty, `resources/js/Pages/Modules/Products/Attributes.vue`
- Modify: formulář produktu (přiřazení hodnot)
- Test: `tests/Feature/Modules/Products/AttributeAdminTest.php`

- [ ] **Step 1: Padající test** — CRUD číselníku; přiřazení hodnot produktu; oprávnění; izolace nájemců.
- [ ] **Step 2–4: Implementace.**
- [ ] **Step 5: Testy, build, pint, commit.**

---

## Etapa C — doplňky produktu s příplatkem

> Tohle je nejrizikovější etapa vlny: sahá na částku, kterou zákazník platí, a na doklad. Nic z toho se nesmí počítat jinde než na serveru a nic se nesmí zaokrouhlovat dvakrát.

### Task C1: Model doplňků

**Files:**
- Create: migrace `product_addon_groups`, `product_addons`, `cart_item_addons`, sloupec `order_items.parent_item_id`
- Create: `Modules/Products/Models/ProductAddonGroup.php`, `ProductAddon.php`
- Test: `tests/Feature/Modules/Products/ProductAddonTest.php`

- [ ] **Step 1: Padající test** — skupina s doplňky; povinná skupina; doplněk nese vlastní sazbu DPH; izolace nájemců.
- [ ] **Step 2–4: Migrace a modely.**
- [ ] **Step 5: Testy, pint, commit.**

### Task C2: Doplněk v košíku

**Files:**
- Modify: `Modules/Checkout/Http/Controllers/CartController.php`, `Http/Requests/AddToCartRequest.php`, `Services/…`
- Test: `tests/Feature/Modules/Checkout/CartAddonTest.php`

- [ ] **Step 1: Padající test** — přidání s doplňkem založí doplňkový řádek; povinná skupina bez výběru = 422; doplněk cizího produktu = 422; změna množství položky mění i doplněk; odebrání položky odebere doplněk.
- [ ] **Step 2: Ověř červený běh.**
- [ ] **Step 3: Validace na serveru.** Doplněk musí patřit k **tomu** produktu — jinak si kdokoli koupí rám za korunu z jiného produktu.
- [ ] **Step 4: Cena ze serveru**, nikdy z formuláře.
- [ ] **Step 5: Testy, pint, commit.**

### Task C3: Doplněk v objednávce a na dokladu

**Files:**
- Modify: `Modules/Orders/Services/OrderPlacer.php` (nebo ekvivalent), doklady
- Test: `tests/Feature/Modules/Orders/OrderAddonTest.php`, `tests/Feature/Modules/Docs/…`

- [ ] **Step 1: Padající test** — objednávka nese doplněk jako samostatný řádek s `parent_item_id`; součet objednávky odpovídá tomu, co bylo v košíku; faktura tiskne doplněk s jeho sazbou; storno vrací obojí.
- [ ] **Step 2–4: Implementace.**
- [ ] **Step 5: Testy, pint, commit.**

### Task C4: Doplňky na detailu produktu

**Files:**
- Modify: `Modules/Products/Resources/views/storefront/show.blade.php` (+ obě existující šablony)
- Test: rozšíření `ThemeStorefrontContractTest`

- [ ] **Step 1: Padající test** — dlaždice doplňků jsou v HTML; výběr je `<input type="radio">`/`checkbox` uvnitř formuláře do košíku; povinná skupina je označená.
- [ ] **Step 2–4: Implementace ve všech šablonách.** Bez JS musí jít doplněk vybrat a odeslat.
- [ ] **Step 5: Testy, build, pint, commit.**

---

## Etapa D — drobnosti nákupní cesty

### Task D1: Částka úspory a počet na stránku

- [ ] Test: „Ušetříte X Kč" se počítá z **téže** referenční ceny jako procento; produkt bez historie nemá ani jedno.
- [ ] Test: `?na-stranku=48` mění stránkování, neznámá hodnota padá na 24, hodnota je v klíči cache.
- [ ] Implementace, testy, pint, commit.

### Task D2: Kotvy sekcí, sticky lišta, lightbox, stránkování nahoře

- [ ] Kotvy jsou odkazy na `id` sekcí; sticky lišta je ostrůvek a bez JS se nezobrazí vůbec.
- [ ] Lightbox přes `<dialog>`; bez JS zůstávají náhledy odkazy na soubor.
- [ ] Stránkování i nad výpisem (stejná komponenta, ne kopie).
- [ ] Testy (PHPUnit + axe v E2E), build, pint, commit.

---

## Etapa E — šablona `katalog`

### Task E1: Manifest, tokeny, písmo

- [ ] `themes/katalog/theme.json`, vlastní Vite vstup, self-hostované písmo.
- [ ] **Paleta zůstává nájemcova** — oranžová jen jako výchozí ukázka v tokenech, `--brand-*` ji přebíjí.
- [ ] Ověř, že smluvní i kontrastní test berou novou šablonu automaticky.

### Task E2: Layout a titulka

- [ ] Layout podle kontrolního seznamu z vlny 4.1 (skip link, verze souhlasu, banner, tracking, prázdný mini-košík).
- [ ] Bloky: karusel → mozaika → pás výhod → záložky produktů.

### Task E3: Výpis a detail

- [ ] Výpis: chipy podkategorií s náhledy, toolbar, fasetový panel, karty s badgem a tlačítkem.
- [ ] Detail: galerie s lightboxem, varianty, doplňky, stepper, dlaždice výhod, kotvy.

### Task E4: Uzavření

- [ ] E2E: nákup bez JS **včetně doplňku** ve všech šablonách; axe na třech stránkách nové šablony.
- [ ] `docs/as-is/`, `docs/decisions/`, `STATUS.md`, `CLAUDE.md` (migrace + build), minor bump.

---

## Strategie testů

| úroveň | co hlídá |
|---|---|
| `HomepageBlockPayloadTest`, `HomepageBlocksRenderTest` | payload, limity, render bez JS, `?zalozka=` |
| `ProductAttributeTest`, `ProductFacetTest` | číselník, sjednocení/průnik, počty, izolace |
| `StorefrontFacetTest` | GET formulář, `noindex`, canonical, klíč cache |
| `CartAddonTest`, `OrderAddonTest`, doklady | částka, sazba, povinná skupina, cizí doplněk, storno |
| `ThemeStorefrontContractTest` | všechny šablony, včetně nové, beze změny testu |
| Playwright | nákup bez JS s doplňkem; axe na nové šabloně |

---

## Rizika a mitigace

| riziko | mitigace |
|---|---|
| **Doplněk rozbije součet objednávky nebo DPH na faktuře.** | Vlastní řádek s vlastní sazbou, nikdy přičtení do ceny produktu; test porovnává součet košíku, objednávky a dokladu. |
| **Cizí doplněk v požadavku.** | Server ověří, že doplněk patří k produktu; test to zkouší. |
| **Fasety vyrobí tisíce indexovatelných URL.** | `noindex` + canonical na nefiltrovanou kategorii. |
| **Fasety zaplaví page cache.** | Normalizace parametru: neznámé pryč, hodnoty seřazené. |
| **Filtr vrátí průnik tam, kde má být sjednocení.** | Jeden `whereExists` na vlastnost; test na obě kombinace. |
| **Slider bez JS ukáže jen první slide.** | `scroll-snap` pás se všemi slidy; test asertuje přítomnost všech. |
| **Editor pěti položkových bloků pětkrát zopakuje chybu.** | Jedna sdílená komponenta seznamu položek. |
| **Vlna je moc velká na jeden zátah.** | Etapy A–E jsou samostatně nasaditelné; po každé je storefront konzistentní. |

---

## Co tenhle plán vědomě nedělá

- Wishlist, vícejazyčnost, odznaky srovnávačů.
- Sklad na doplňcích.
- Whitelist indexovatelných kombinací filtrů (zůstává `noindex`).
- Sjednocení variant a vlastností do jednoho číselníku.
