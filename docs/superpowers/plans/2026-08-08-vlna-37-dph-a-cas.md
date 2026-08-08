# Vlna 3.7 — plátcovství DPH a čas obchodu — implementační plán

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Neplátce DPH nikde neuvidí daň — ani v administraci, ani na veřejné stránce. Plátce může zadat cenu bez DPH. Sazby jde spravovat. A nastavený čas se konečně používá.

**Architecture:** Jeden dotaz „je tenhle nájemce plátce" (`VatMode`), který čte administrace, storefront i košík; superadmin obrazovka nad existující platformní tabulkou `tax_rates`; převod ceny dělá server přes `TaxRate`, ne JavaScript.

**Spec:** [`docs/superpowers/specs/2026-08-08-vlna-37-dph-a-cas-design.md`](../specs/2026-08-08-vlna-37-dph-a-cas-design.md)

## Global Constraints

- **Žádná nová závislost.**
- **Cena s DPH zůstává jediná uložená hodnota.** Pole „bez DPH" je pomůcka pro zadání. Převod dělá `TaxRate` na serveru — `Money` o dani neví (rozhodnutí 2026-07-20).
- **Sazby zůstávají platformní** (`tax_rates` bez `tenant_id`). Rozhodnutí vlastníka.
- **Uložená sazba se nemaže**, když nájemce přestane být plátcem. Rozhodnutí vlastníka.
- **Vystavený doklad je snímek** a nesmí se změnit, když se změní sazba.
- **Storefront zůstává Blade SSR.**
- **Kód anglicky, texty česky. PHP 8.3. `./vendor/bin/pint`. Testy po adresářích.**

---

### Task 1: Dluh z 3.6 — čas a formáty se začnou používat

**Files:**
- Create: `app/Core/Shop/ShopClock.php`
- Modify: `app/Http/Middleware/SetTenantContext.php` (nebo vlastní middleware), `Modules/Orders/…`, `Modules/Customers/Resources/views/storefront/account/*.blade.php`
- Test: `tests/Feature/Shop/ShopClockTest.php`

- [ ] **Step 1: Napiš padající testy**

1. Objednávka založená v 23:30 UTC se nájemci v `Europe/Prague` ukáže jako druhý den — a nájemci v `UTC` ne.
2. Nastavený formát data se projeví na výpisu objednávek v administraci.
3. **Uložená hodnota v databázi se nemění** — přepnutí pásma nepřepíše `created_at`.
4. **ISDOC, Pohoda, feed a sitemap dál tisknou `Y-m-d`**, ať je nastavené cokoli. Strojový formát není vkus nájemce.

- [ ] **Step 2: Implementuj.** `ShopClock::format(DateTimeInterface, bool $withTime)` čte nastavení jednou; volající nikde nesestavuje formát sám.
- [ ] **Step 3: Ověř** — `php artisan test tests/Feature/Shop tests/Feature/Modules/Orders --compact`
- [ ] **Step 4: Commit** — `feat(shop): use the tenant's time zone and date format`

---

### Task 2: Jedna odpověď na „je plátce"

**Files:**
- Create: `app/Core/Tax/VatMode.php`
- Test: `tests/Feature/Core/VatModeTest.php`

- [ ] **Step 1: Napiš padající testy**

1. Plátce → `appliesVat() === true`, neplátce → `false`.
2. Bez tenanta (platformní host, konzole) → `false`, ne výjimka.
3. Přepnutí plátcovství se projeví okamžitě — hodnota se nekešuje přes hranici requestu.

- [ ] **Step 2: Implementuj.** Tenká služba nad `TenantContext`. Důvod, proč nestačí `$tenant->vat_payer` v šablonách: volajících bude přes deset a jedenáctý se zapomene (stejná úvaha jako u observeru page cache).
- [ ] **Step 3: Commit** — `feat(tax): add one place that answers whether the shop charges VAT`

---

### Task 3: Superadmin — správa sazeb

**Files:**
- Create: `app/Http/Controllers/Platform/TaxRateController.php`, `app/Http/Requests/Platform/{Store,Update}TaxRateRequest.php`, `resources/js/Pages/Platform/TaxRates.vue`
- Modify: `routes/platform.php`, `resources/js/Layouts/PlatformLayout.vue`
- Test: `tests/Feature/Platform/TaxRateAdminTest.php`

- [ ] **Step 1: Napiš padající testy**

1. Superadmin založí sazbu, upraví procento, přepne výchozí.
2. **Sazba, kterou používá produkt, doprava, platba nebo doklad, nejde smazat** (422). Smazaný řádek by z historické faktury udělal doklad, u kterého nejde dohledat, co se počítalo.
3. **Výchozí sazba musí být právě jedna** — přepnutí druhé zruší první.
4. Procento se ukládá jako promile (21 % → 210); desetinná sazba (12,5 %) projde.
5. **Změna procenta nezmění už vystavený doklad** ani řádek objednávky. Nejdůležitější test úkolu.
6. Zápis invaliduje cache `TaxRates` — jinak by e-shopy jely na staré sazbě do půlnoci.
7. Nájemce se na obrazovku nedostane (404 na hostu e-shopu).

- [ ] **Step 2: Implementuj.** Nad existující tabulkou, žádná migrace. `TaxRates::flush()` po každém zápisu.
- [ ] **Step 3: Ověř + `npm run build`**
- [ ] **Step 4: Commit** — `feat(platform): let the superadmin manage VAT rates`

---

### Task 4: Administrace produktu podle plátcovství

**Files:**
- Modify: `Modules/Products/Http/Controllers/ProductAdminController.php`, `Modules/Products/Http/Requests/{Store,Update}ProductRequest.php`, `resources/js/Pages/Modules/Products/Show.vue`
- Test: `tests/Feature/Modules/Products/VatModeAdminTest.php`

- [ ] **Step 1: Napiš padající testy**

1. Plátce zadá **cenu bez DPH** → uloží se správná cena s DPH (21 % z 1 000 Kč → 1 210 Kč).
2. Plátce zadá cenu s DPH → beze změny proti dnešku.
3. Poslané obojí najednou → **rozhoduje cena s DPH**, ne dopočet. Jinak by zaokrouhlení tiše měnilo cenu při každém uložení.
4. Neplátce: `tax_rate_id` **není povinné** a formulář ho nedostane.
5. Neplátce uloží produkt bez sazby a **uložená sazba na existujícím produktu zůstane** (rozhodnutí vlastníka).
6. Neplátce nevidí v odpovědi controlleru `net_price` ani seznam sazeb.
7. Varianty: totéž pravidlo (plátce smí zadat bez DPH, neplátce jen částku).

- [ ] **Step 2: Implementuj.** Převod v FormRequestu přes `TaxRate::gross()`, nikdy v JS — JS smí jen napovídat, stejné pravidlo jako u variant (vlna 2.4).
- [ ] **Step 3: Ověř + `npm run build`**
- [ ] **Step 4: Commit** — `feat(products): let a VAT payer enter the price without VAT, and hide VAT from everyone else`

---

### Task 5: Storefront podle plátcovství

**Files:**
- Modify: `Modules/Products/Resources/views/storefront/show.blade.php`, `Modules/Products/Resources/views/storefront/variant-picker.blade.php`, `Modules/Storefront/resources/views/components/product-card.blade.php`, `Modules/Checkout/Resources/views/**`
- Test: `tests/Feature/Modules/Products/VatModeStorefrontTest.php`

- [ ] **Step 1: Napiš padající testy**

1. Neplátce: detail produktu **neobsahuje** „s DPH" ani cenu bez DPH — jen částku.
2. Plátce: detail produktu obsahuje vše, co dnes (regresní test proti tomu, aby úklid ubral plátci).
3. Neplátce: v košíku ani v pokladně **není rozpis DPH**.
4. Plátce: rozpis DPH v pokladně beze změny.
5. Neplátce + varianty: JS ostrůvek nepřepisuje cenu bez DPH, protože žádná není — a nespadne.
6. Neplátce: potvrzovací e-mail a děkovná stránka bez DPH.

- [ ] **Step 2: Ověř + commit** — `feat(storefront): stop telling a non-VAT-payer's customers about VAT`

---

### Task 6: Uzavření

- [ ] **Step 1:** PHPUnit po adresářích, `npm run e2e`.
- [ ] **Step 2: E2E** — přepnout demo na neplátce, projít detail produktu a pokladnu, vrátit zpět.
- [ ] **Step 3: Ruční průchod na demu.**
- [ ] **Step 4:** as-is, STATUS, CLAUDE.md, `VERSION` → `0.42.0`, CHANGELOG, merge.

---

## Rizika

| Riziko | Dopad | Mitigace |
|---|---|---|
| Smazání používané sazby | Historický doklad bez dohledatelné sazby | Zákaz smazání + test 2 v Tasku 3 |
| Změna sazby přepíše vystavený doklad | Účetní chyba, nedohledatelná zpětně | Doklad je snímek; test 5 v Tasku 3 |
| Dopočet ceny v JS | Haléřový rozjezd mezi tím, co vidí nájemce, a tím, co se uloží | Převod jen na serveru přes `TaxRate` |
| Obojí pole poslané najednou | Cena se při každém uložení posune o haléř | Rozhoduje cena s DPH; test 3 v Tasku 4 |
| Úklid DPH ubere i plátci | Plátce ztratí povinné údaje na dokladu a v katalogu | Regresní testy pro plátce v Tasku 4 i 5 |
| Zapomenutý `flush()` po změně sazby | E-shopy účtují starou sazbu až 24 h | Test 6 v Tasku 3 |
| Časové pásmo přepíše uložená data | Rozbitá historie objednávek | Ukládá se dál UTC; test 3 v Tasku 1 |
