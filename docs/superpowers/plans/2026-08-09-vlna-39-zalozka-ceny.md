# Vlna 3.9 — záložka Ceny na kartě produktu — implementační plán

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Nájemce vidí ceny ve stejném pořadí, v jakém o nich přemýšlí — bez DPH, sazba, s DPH — a to třikrát: prodejní, nákupní, akční. Slevu může zadat procentem.

**Architecture:** Tři sloupce navíc na `products` (`purchase_tax_rate_id`, `purchase_price` zůstává, `sale_percent`), přepočet výhradně na serveru přes `TaxRate` a `MoneyInput`, tři sekce v jednom formuláři.

**Zadání:** konverzace 2026-08-09. Rozhodnutí vlastníka: procento se **ukládá**, nákupní cena má **vlastní sazbu**.

## Global Constraints

- **Uložené zůstává uložené v haléřích a v ceně s DPH.** Pole „bez DPH" a „%" jsou vstupní pomůcky, ne druhá pravda (rozhodnutí 2026-07-21 a 3.7).
- **Přepočet dělá server**, nikdy JavaScript — JS smí jen napovídat.
- **Nikdy float na float** (`MoneyInput`, rozhodnutí 3.8).
- **Historie ceny z vlny 2.7 se nesmí obejít.** Každý zápis ceny jde přes `ProductWriter`, jinak přestane platit zákonná evidence nejnižší ceny za 30 dní.
- **Neplátce DPH nevidí nic o dani** (rozhodnutí 3.7) — sekce se mu smrskne na jedno pole v každé skupině.
- **Kód anglicky, texty česky. PHP 8.3. `./vendor/bin/pint`. Testy po adresářích.**

---

### Task 1: Sloupce a přepočet

**Files:**
- Create: migrace `products.purchase_tax_rate_id`, `products.sale_percent`
- Modify: `Modules/Products/Models/Product.php`, `Modules/Products/Http/Requests/StoreProductRequest.php`
- Test: `tests/Feature/Modules/Products/PriceTabTest.php`

- [ ] **Step 1: Napiš padající testy**

1. Zadané procento uloží akční cenu: 1 000 Kč a 20 % → 800 Kč.
2. **Změna pultové ceny přepočítá akční cenu**, protože procento je uložené. To je celý důvod, proč se ukládá.
3. Zadaná částka i procento naráz → **rozhoduje částka**, procento se zahodí. Jinak by se cena při každém uložení posunula (stejné pravidlo jako gross vs. net ve 3.7).
4. Procento mimo 1–99 se odmítne. 100 % je „zdarma", což není sleva, ale jiný nástroj; 0 % není sleva vůbec.
5. Nákupní cena se přepočítá vlastní sazbou, ne prodejní.
6. Prázdná nákupní sazba dědí sazbu produktu.
7. **Zápis prochází `ProductWriter`**, takže se zapíše i do historie ceny (regrese na vlnu 2.7).

- [ ] **Step 2: Implementuj.** Přepočet v `prepareForValidation()` za `MoneyInput`, aby `lt:price` dál porovnávalo haléře s haléři.
- [ ] **Step 3: Ověř** — `php artisan test tests/Feature/Modules/Products --compact`
- [ ] **Step 4: Commit** — `feat(products): store a sale percentage and a purchase VAT rate`

---

### Task 2: Přeskládaná záložka Ceny

**Files:**
- Modify: `resources/js/Pages/Modules/Products/Show.vue`, `Modules/Products/Http/Controllers/ProductAdminController.php`
- Test: `e2e/tests/product-prices.spec.ts`

Tři sekce pod sebou, v každé pořadí **bez DPH → sazba → s DPH**:

| Sekce | Pole |
|---|---|
| Prodejní cena | cena bez DPH, sazba DPH, cena s DPH |
| Nákupní cena | nákupní cena bez DPH, sazba DPH, nákupní cena s DPH |
| Akce | akční cena **nebo** sleva v %, okno kampaně |

- [ ] **Step 1: Napiš padající testy**

1. Plátce vidí všechny tři sekce a v každé tři pole ve správném pořadí.
2. **Neplátce vidí jen částky** — žádnou sazbu, žádné „bez DPH" (regrese na 3.7).
3. Nákupní sekce se nezobrazí bez práva `products.costs` (regrese na §16.1).
4. Vyplnění procenta dopočítá náhled částky, ale uloží se to, co spočítá server.

- [ ] **Step 2: Implementuj.**
- [ ] **Step 3: Ověř + `npm run build` + commit** — `feat(products): lay the prices tab out the way a merchant reads it`

---

### Task 3: Uzavření

- [ ] **Step 1:** PHPUnit po adresářích, `npm run e2e`.
- [ ] **Step 2:** `php artisan migrate` na lokále a ruční průchod.
- [ ] **Step 3:** as-is, STATUS, CLAUDE.md, `VERSION` → `0.44.0`, CHANGELOG, merge.

---

## Rizika

| Riziko | Dopad | Mitigace |
|---|---|---|
| Procento se přepočte při každém uložení | Cena se posouvá sama | Částka vyhrává nad procentem; test 3 v Tasku 1 |
| Přepočet obejde `ProductWriter` | Přestane platit zákonná evidence nejnižší ceny (2.7) | Zápis jen přes writer; test 7 |
| Nákupní sazba se použije na prodejní cenu | Špatná marže i špatný doklad | Oddělené sloupce, test 5 |
| Úklid pro neplátce ubere plátci | Chybí povinné údaje | Regresní testy pro plátce v obou úkolech |
| Sleva 100 % | Objednávka za 0 Kč přes cenu, ne přes slevový engine | Rozsah 1–99 %; test 4 |
