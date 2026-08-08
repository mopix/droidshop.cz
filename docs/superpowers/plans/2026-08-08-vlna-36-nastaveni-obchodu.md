# Vlna 3.6 — nastavení obchodu pro nájemce — implementační plán

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Nájemce si nastaví svůj e-shop sám — jméno a slogan, kontakt na sebe, co o něm uvidí vyhledávač, jak se katalog chová a jestli je e-shop vůbec veřejný.

**Architecture:** Jedna nová tabulka `shop_settings` (1:1 na nájemce) a jedna služba, která ji čte; čtyři Inertia obrazovky nad ní. Zápis vždy zvedne generační čítač page cache, protože skoro každé z těch nastavení mění vyrenderované HTML.

**Spec:** [`docs/superpowers/specs/2026-08-08-vlna-36-nastaveni-obchodu-design.md`](../specs/2026-08-08-vlna-36-nastaveni-obchodu-design.md)

## Global Constraints

- **Žádná nová závislost.**
- **Vlastní tabulka, ne `SettingsService`.** Ten klíčuje podle modulu a validuje proti manifestu; tohle je nastavení e-shopu jako celku a muselo by se vymyslet, který modul „vlastní" časové pásmo. Sloupce navíc dávají typy a indexy, které JSON nedá.
- **Každý zápis zvedá page cache.** Slogan, kontakty, SEO i zobrazení jdou do HTML; bez bumpu je nájemce uvidí až po TTL.
- **Storefront zůstává Blade SSR.** Nic z toho se nedohydratuje.
- **WCAG 2.2 AA** — čtyři nové formuláře, popisky svázané s poli, chyby oznámené.
- **Kód anglicky, texty česky. PHP 8.3. `./vendor/bin/pint`. Testy po adresářích.**

---

### Task 1: Tabulka a služba

**Files:**
- Create: `database/migrations/2026_08_08_100000_create_shop_settings_table.php`
- Create: `app/Models/ShopSettings.php`
- Create: `app/Core/Shop/ShopSettingsService.php`
- Test: `tests/Feature/Shop/ShopSettingsTest.php`

**Sloupce:**

| Skupina | Sloupce |
|---|---|
| Obchod | `tagline`, `timezone` (default `Europe/Prague`), `date_format`, `time_format` |
| Kontakty | `contact_email`, `contact_phone`, `contact_street`, `contact_city`, `contact_zip`, `contact_country`, `opening_hours`, `facebook_url`, `instagram_url`, `x_url`, `youtube_url`, `tiktok_url` |
| SEO | `seo_title`, `seo_description`, `og_image_path`, `noindex` |
| Zobrazení | `hide_empty_categories`, `empty_search_text`, `show_footer_contact` |
| Zámek | `locked`, `lock_password` (hash) |

- [ ] **Step 1: Napiš padající testy**

1. Nájemce bez řádku dostane výchozí hodnoty, ne `null` — obrazovka i storefront musí fungovat, než někdo poprvé uloží.
2. Zápis vytvoří řádek, druhý zápis ho aktualizuje (ne druhý řádek).
3. Nastavení jednoho e-shopu není vidět z druhého.
4. **Zápis zvedne page cache generaci.**
5. `lock_password` je v databázi hash, ne plaintext.

- [ ] **Step 2: Implementuj.** `ShopSettings` s `BelongsToTenant`; služba `forCurrentTenant()` + `update(array)`; hodnoty slévané s výchozími, stejná úvaha jako `SettingsService::all()` (schéma je jediná pravda o tom, na čem běží nedotčený e-shop).
- [ ] **Step 3: Ověř** — `php artisan test tests/Feature/Shop --compact`
- [ ] **Step 4: Commit** — `feat(shop): add per-tenant shop settings`

---

### Task 2: Obrazovky Obchod a Kontakty

**Files:**
- Create: `app/Http/Controllers/Tenant/ShopSettingsController.php`
- Create: `app/Http/Requests/Tenant/{UpdateShopRequest,UpdateContactsRequest}.php`
- Create: `resources/js/Pages/Tenant/Settings/{Shop,Contacts}.vue`
- Modify: `routes/tenant.php`, `resources/js/Layouts/AdminLayout.vue`

- [ ] **Step 1: Napiš padající testy**

1. Obě obrazovky se otevřou vlastníkovi, 403 členovi bez práva, redirect hostovi.
2. Neplatné časové pásmo se odmítne (validace proti `timezone_identifiers_list()`, ne volný text — neplatná zóna shodí každý render data).
3. Odkaz na sociální síť musí být `http(s)` URL; `javascript:` se odmítne (precedent `BlockUrl` z vlny 2.3).
4. Kontaktní e-mail musí být e-mail.
5. Uložení zvedne page cache generaci.

- [ ] **Step 2: Implementuj.** Formuláře podle vzoru `/admin/nastaveni/vzhled`. Kontakty bez přepínačů „kde se zobrazí" — vyplněné se zobrazí (rozhodnutí vlastníka).
- [ ] **Step 3: Menu.** Do `CORE_ENTRIES.settings` přibývají Obchod, Kontakty a **Fakturační údaje** (dnes dostupné jen z banneru).
- [ ] **Step 4: Ověř + `npm run build`**
- [ ] **Step 5: Commit** — `feat(shop): let the tenant set the shop's name, tagline and contact details`

---

### Task 3: Obrazovka SEO

**Files:**
- Create: `app/Http/Requests/Tenant/UpdateSeoRequest.php`
- Create: `resources/js/Pages/Tenant/Settings/Seo.vue`
- Modify: `Modules/Storefront/Http/Controllers/{HomeController,RobotsController}.php`
- Modify: `Modules/Storefront/Providers/ModuleProvider.php`

- [ ] **Step 1: Napiš padající testy**

1. Vlastní title a description jsou v HTML homepage.
2. Prázdné **degradují na dnešní chování** (název obchodu), ne na prázdný `<title>`.
3. `noindex` je v `<meta name="robots">` **i v `robots.txt`** — jedno bez druhého je poloviční zákaz, který crawler obejde.
4. OG obrázek: raster-only, žádné SVG (stored XSS, precedent favicon z vlny 2.2).
5. Uložení zvedne page cache generaci.

- [ ] **Step 2: Implementuj.** Nahrání obrázku přes `FileStorage::putPublic`, cesta server-authoritative (klient ji nikdy nediktuje — precedent `image_path` z vlny 2.3).
- [ ] **Step 3: Ověř + commit** — `feat(shop): let the tenant control the homepage's title, description and indexing`

---

### Task 4: Obrazovka Zobrazení

**Files:**
- Create: `app/Http/Requests/Tenant/UpdateDisplayRequest.php`
- Create: `resources/js/Pages/Tenant/Settings/Display.vue`
- Modify: `Modules/Categories/…` (skrývání prázdných), `Modules/Storefront/…` (text hledání, patička)

- [ ] **Step 1: Napiš padající testy**

1. Prázdná kategorie zmizí z navigace jen se zapnutým přepínačem.
2. Kategorie s produkty jen v podkategorii se **nepovažuje za prázdnou** — jinak by přepínač schoval půl stromu.
3. Vlastní text prázdného hledání se zobrazí; prázdný degraduje na výchozí.
4. Kontaktní box v patičce jde vypnout.
5. Vše zvedne page cache generaci.

- [ ] **Step 2: Ověř + commit** — `feat(shop): let the tenant tune how the storefront behaves`

---

### Task 5: Zaheslování e-shopu

Nejrizikovější část vlny — bezpečnostní prvek, který sahá do page cache.

**Files:**
- Create: `app/Http/Middleware/EnsureShopUnlocked.php`
- Create: `app/Http/Controllers/ShopLockController.php`
- Create: `resources/views/shop-lock.blade.php`
- Modify: `bootstrap/app.php`, `app/Core/PageCache/PageCachePolicy.php`

- [ ] **Step 1: Napiš padající testy**

1. Zamčený e-shop vrátí formulář místo katalogu, a to i na detailu produktu a v košíku.
2. Správné heslo odemkne, špatné ne.
3. Odemčení **přežije přechod na jinou stránku**.
4. **Zamčený e-shop se neuloží do page cache** a nikdy neservíruje uloženou stránku bez odemčení. Nejdůležitější test celé vlny: uložená stránka by zámek obešla.
5. **Webhook platby a dopravce projde i zamčený** — jinak by e-shop tiše ztrácel objednávky.
6. Administrace zůstává dostupná.
7. Zamčený e-shop je `noindex` bez ohledu na přepínač SEO.
8. Heslo je v databázi hash; ověření je `Hash::check`.
9. Pokusy o odemčení jsou omezené počtem (precedent `ApplyDiscountRequest` z vlny 2.6) — bez toho je čtyřmístné heslo otázka minut.

- [ ] **Step 2: Implementuj.** Middleware na `web` skupině **před** page cache. Cookie, ne session vázaná na zákazníka: zamčený e-shop zákazníky nemá.
- [ ] **Step 3: `PageCachePolicy::tenantFor()`** vrací `null` pro zamčený e-shop.
- [ ] **Step 4: Ověř** — `php artisan test tests/Feature/Shop tests/Feature/PageCache --compact`
- [ ] **Step 5: Commit** — `feat(shop): let the tenant lock the storefront behind a password`

---

### Task 6: Uzavření

- [ ] **Step 1:** PHPUnit po adresářích, `npm run e2e` (3×).
- [ ] **Step 2: E2E scénář** — zamčený e-shop nevydá katalog ani po opakovaném načtení (page cache).
- [ ] **Step 3: Ruční průchod na demu** — poučení z 2.9: projít všechny čtyři obrazovky, uložit, zkontrolovat storefront.
- [ ] **Step 4:** as-is, STATUS, CLAUDE.md, `VERSION` → `0.41.0`, CHANGELOG, merge.

---

## Rizika

| Riziko | Dopad | Mitigace |
|---|---|---|
| Page cache servíruje zamčený e-shop | Zámek k ničemu, katalog veřejný | `PageCachePolicy` odmítne zamčený; test 4 v Tasku 5 |
| Webhook zablokovaný zámkem | Tiše ztracené platby | Webhooky mimo zámek; test 5 |
| Slabé heslo zámku | Zámek jen na oko | Hash, `Hash::check`, omezení pokusů |
| Neplatné časové pásmo | Pád na každém renderu data | Validace proti `timezone_identifiers_list()` |
| Skrývání prázdných kategorií schová i rodiče s produkty v podkategorii | Zmizí půl katalogu | Test 2 v Tasku 4 |
| Zapomenutý bump page cache | Nájemce nevidí vlastní změnu | Bump ve službě, ne v controllerech; test v Tasku 1 |
