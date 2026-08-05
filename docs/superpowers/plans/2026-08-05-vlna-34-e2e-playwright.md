# Vlna 3.4 — E2E testy v prohlížeči (Playwright) — implementační plán

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ověřit v reálném prohlížeči to, co 2003 PHP testů ověřit nemůže: že měřicí skripty respektují souhlas, že nákup projde bez JavaScriptu a že klíčové stránky nemají závažná porušení přístupnosti.

**Architecture:** Samostatná sada v `e2e/`, `@playwright/test` proti `php artisan serve` na HTTP. Vlastní testovací doména `droidshop.test` (RFC 6761), aby Chromium neupgradoval na HTTPS. PHPUnit sada zůstává beze změny na `droidshop`.

**Tech Stack:** Playwright (Chromium), `@axe-core/playwright`, Laravel 13.

**Spec:** [`docs/superpowers/specs/2026-08-05-vlna-34-e2e-playwright-design.md`](../specs/2026-08-05-vlna-34-e2e-playwright-design.md)

## Global Constraints

- **Dvě nové devDependencies** (`@playwright/test`, `@axe-core/playwright`) — schváleno vlastníkem 2026-08-05. Žádné další.
- **PHPUnit sada se nemění.** Přepisovat 2003 testů na novou doménu je změna s velkým dosahem a nulovým přínosem.
- **Žádné `waitForTimeout`.** Čeká se jen na stav (`expect(...).toBeVisible()`, `waitForURL`, `waitForResponse`). Pevná prodleva je nejrychlejší cesta k blikající sadě.
- **Žádný skutečný požadavek na cizí doménu.** Scénář souhlasu je odchytává přes `page.route()` a testuje **pokus**, ne odpověď.
- **Každý scénář si zakládá vlastní data.** Testy nesmí záviset na pořadí.
- **Kód a komentáře anglicky**, texty v asercích česky (tak, jak je vidí zákazník).

---

### Task 1: Instalace a kostra

**Files:**
- Modify: `package.json` (devDependencies + skript `e2e`)
- Create: `e2e/playwright.config.ts`
- Create: `e2e/README.md`
- Create: `e2e/support/shop.ts` (URL helpery, seed)
- Modify: `.gitignore` (`e2e/.artifacts/`, `playwright-report/`, `test-results/`)

**Interfaces:**
- Produces: `npm run e2e`, `npm run e2e:ui`
- Produces: `shopUrl(path)`, `platformUrl(path)` nad `E2E_BASE_HOST` (default `droidshop.test`)

- [ ] **Step 1:** `npm install -D @playwright/test @axe-core/playwright` + `npx playwright install chromium`
- [ ] **Step 2: Konfigurace.** Jeden projekt (Chromium), `webServer` spustí `php artisan serve --port=8001` a počká na něj — port jiný než demo (8000), aby E2E nespadlo na tom, že si vývojář nechal běžet server. `retries: 0` lokálně, `1` v CI (jedna síťová chyba není důvod k červenému buildu, dvě ano). `trace: 'retain-on-failure'`.
- [ ] **Step 3: `e2e/README.md`** — jak sadu spustit, co dělá se seedem, proč `droidshop.test`, a **výslovně** poznámka, že omezení certifikátu v `STATUS.md` se E2E netýká.
- [ ] **Step 4: Seed helper.** `migrate:fresh --seed` + `DemoShopSeeder` v `globalSetup`, jednou před celým během. Ne mezi testy: seed je pomalý a scénáře si stejně zakládají vlastní data.
- [ ] **Step 5: Commit** — `chore(e2e): add Playwright with a Chromium project against a served app`

---

### Task 2: Doména `droidshop.test`

**Files:**
- Create: `e2e/.env.e2e` (nebo dokumentovaný postup, pokud se `.env` nemá dotýkat)
- Modify: `docs/DEMO-LOCAL.md` (záznam do `/etc/hosts`)
- Modify: `docs/as-is/STATUS.md` (opravit tvrzení o certifikátu)

**Pozn.:** `.env` se needituje (pravidlo projektu). E2E si prostředí předá přes proměnné na příkazové řádce v `webServer.command`, stejně jako to dělá demo (`CACHE_STORE=array … php artisan serve`).

- [ ] **Step 1:** `webServer.command` nastaví `PLATFORM_DOMAIN=droidshop.test`, `CACHE_STORE=array`, `SESSION_DRIVER=file`, `QUEUE_CONNECTION=sync`, `PAGE_CACHE_ENABLED=false`.

  **Page cache se pro E2E vypíná.** Sada by jinak testovala uložené HTML z předchozího scénáře a první červený test by byl nevysvětlitelný. Cache má vlastní PHPUnit pokrytí (108 testů).

- [ ] **Step 2:** Dokumentovat `/etc/hosts` řádek: `127.0.0.1 droidshop.test obchod.droidshop.test admin.droidshop.test`
- [ ] **Step 3:** Opravit `STATUS.md` — omezení certifikátu se týká `curl` přes HTTPS, ne Playwrightu na HTTP.
- [ ] **Step 4: Commit** — `chore(e2e): serve the suite from droidshop.test so Chromium leaves the host alone`

---

### Task 3: Souhlas a měření

Nejdůležitější scénář vlny — zavírá mezeru z 3.3.

**Files:**
- Create: `e2e/tests/consent.spec.ts`
- Create: `e2e/support/tracking.ts` (odchytávání požadavků na cizí domény)

- [ ] **Step 1: Helper.** `blockVendors(page)` zaregistruje `page.route()` na tři vendor domény, odpoví prázdným 200 a **zaznamená pokus**. Testuje se tím pokus o požadavek, ne odpověď — v CI nesmí nic reálně odejít.
- [ ] **Step 2: Scénáře**

1. **Bez rozhodnutí neodejde nic.** Načíst homepage nakonfigurovaného e-shopu, počkat na `networkidle`, ověřit prázdný seznam pokusů.
2. **Po „Přijmout vše" požadavek odejde** — a to **na téže stránce**, bez reloadu (`consent:changed`).
3. **Po „Odmítnout vše" neodejde nic**, ani po reloadu.
4. **Jen analytické** (přes obrazovku nastavení): odejde požadavek na Google, ne na Seznam ani Meta.
5. **Lišta neprobliká.** S nastavenou cookie souhlasu je lišta při prvním vykreslení neviditelná — ověřeno hned po `domcontentloaded`, ne až po ustálení stránky (to je přesně to, co inline skript v `<head>` řeší).
6. **Rozhodnutí přežije reload** a lišta se znovu neobjeví.

- [ ] **Step 3: Ověřit, že test umí selhat.** Dočasně odstranit podmínku `allows('analytics')` v `Modules/Analytics/Resources/views/tracking.blade.php`, spustit scénář 1, potvrdit **červenou**, změnu vrátit. Test, o kterém nevíme, že umí spadnout, negarantuje nic — a tenhle hlídá zákonnou povinnost.
- [ ] **Step 4: Commit** — `test(e2e): prove tracking scripts stay silent until the visitor consents`

---

### Task 4: Nákup bez JavaScriptu

**Files:**
- Create: `e2e/tests/checkout-no-js.spec.ts`

- [ ] **Step 1: Projekt s vypnutým JS.** V konfiguraci druhý projekt `no-js` s `javaScriptEnabled: false`, aby to nebylo jen nastavení schované v jednom testu.
- [ ] **Step 2: Scénář** — katalog → detail produktu → do košíku → košík → doprava a platba → údaje → odeslat → děkovná stránka s číslem objednávky. Každý krok ověří, že obsah je v HTML (bez JS není co dohydratovat).
- [ ] **Step 3:** Ověřit, že vznikla objednávka — poslední krok čte číslo z děkovné stránky a scénář ho asertuje proti tvaru čísla, ne proti pevné hodnotě.
- [ ] **Step 4: Commit** — `test(e2e): walk a whole purchase with JavaScript switched off`

---

### Task 5: Nákup s JavaScriptem

**Files:**
- Create: `e2e/tests/checkout.spec.ts`

- [ ] **Step 1: Scénář** — tatáž cesta s JS, navíc: výběr varianty přepočítá cenu **bez reloadu** (ostrůvek z 2.4), mini-košík v hlavičce ukáže počet položek, vyhledávání najde produkt.
- [ ] **Step 2:** Konverze na děkovné stránce po souhlasu odešle `purchase` (odchycený požadavek nese číslo objednávky) — zavírá druhou půlku mezery z 3.3.
- [ ] **Step 3: Commit** — `test(e2e): walk the ordinary purchase including variants and the mini cart`

---

### Task 6: Přístupnost (axe)

**Files:**
- Create: `e2e/tests/accessibility.spec.ts`

- [ ] **Step 1:** Axe na homepage, detailu produktu, košíku, pokladně a na stránce s viditelnou lištou cookies.
- [ ] **Step 2:** Práh: žádné porušení `critical` ani `serious`. Nižší úrovně se **vypíší do reportu, ale nebrání zelené** — jinak sada zčervená na věci, kterou nikdo neopraví, a začne se přeskakovat.
- [ ] **Step 3:** Nálezy zapsat do as-is. Pokud axe najde skutečné porušení, opravit ho v této vlně — nálezy přístupnosti se neodkládají.
- [ ] **Step 4: Commit** — `test(e2e): run axe over the storefront's key pages`

---

### Task 7: CI

**Files:**
- Modify: `.github/workflows/ci.yml`

- [ ] **Step 1:** Nový job `e2e` vedle `tests` a `tenant-isolation`: MySQL service, PHP, Node, `npm ci`, `npx playwright install --with-deps chromium`, `npm run build`, `npm run e2e`.
- [ ] **Step 2:** Cache prohlížečů (`~/.cache/ms-playwright`), jinak si každý běh stáhne 300 MB.
- [ ] **Step 3:** Report jako artefakt při selhání (`actions/upload-artifact`, `if: failure()`).
- [ ] **Step 4:** `/etc/hosts` v CI: `echo "127.0.0.1 droidshop.test obchod.droidshop.test" | sudo tee -a /etc/hosts`
- [ ] **Step 5: Commit** — `ci: run the Playwright suite on every pull request`

---

### Task 8: Uzavření vlny

- [ ] **Step 1:** Tři běhy `npm run e2e` po sobě — stabilita je akceptační kritérium, ne dojem.
- [ ] **Step 2:** PHPUnit sada po adresářích (E2E se jí neměla dotknout, ale `package.json` a případné opravy přístupnosti ano).
- [ ] **Step 3:** `docs/as-is/2026-08-05-e2e-playwright.md`, `STATUS.md` (Playwright z „není" na „hotovo", oprava tvrzení o certifikátu), CLAUDE.md rozhodnutí.
- [ ] **Step 4:** `VERSION` → `0.39.0`, `CHANGELOG.md`, merge, push.

---

## Rizika

| Riziko | Dopad | Mitigace |
|---|---|---|
| Blikající sada | Přeskakuje se, pak ignoruje i skutečnou chybu | Žádné `waitForTimeout`, čekání jen na stav, tři běhy jako AK |
| Test souhlasu neumí selhat | Falešná jistota u zákonné povinnosti | Task 3 krok 3 to explicitně dokazuje porušením gate |
| Page cache servíruje HTML z minulého scénáře | Nevysvětlitelně červený test | `PAGE_CACHE_ENABLED=false` pro E2E; cache má vlastní pokrytí |
| Skutečný požadavek na Google z CI | Odchozí provoz z CI, závislost na cizí dostupnosti | `page.route()` odchytí a odpoví lokálně |
| CI job zpomalí PR | Lidé ho začnou obcházet | Vlastní job běží paralelně; cache prohlížečů |
| Axe najde porušení, která se neopraví | Sada zčervená natrvalo | Práh `critical`/`serious`; skutečné nálezy se opravují v této vlně |
