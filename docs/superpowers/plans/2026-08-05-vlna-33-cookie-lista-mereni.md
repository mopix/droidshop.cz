# Vlna 3.3 — cookie lišta a měřicí kódy nájemce — implementační plán

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Nájemce může měřit návštěvnost a konverze (GA4, Sklik, Meta Pixel, Heureka Ověřeno zákazníky), aniž by porušil zákon — žádný měřicí kód se nespustí dřív, než k tomu návštěvník dá souhlas.

**Architecture:** Souhlas čte a lištu renderuje jádro (`app/Core/Consent/`), protože cookie lišta je povinnost i pro e-shop, který nic neměří. Měřicí kódy vlastní nový base modul `analytics`, který se na souhlas napojuje. Lišta je v cachovaném HTML vždy a skrývá ji JS podle cookie, takže page cache z vlny 3.0 zůstává nedotčená.

**Tech Stack:** Laravel 13, Blade SSR, vanilla JS v `resources/js/storefront.js` (žádné Alpine — precedent 2.4), PHPUnit. Žádná nová závislost.

**Spec:** [`docs/superpowers/specs/2026-08-05-vlna-33-cookie-lista-mereni-design.md`](../specs/2026-08-05-vlna-33-cookie-lista-mereni-design.md)

## Global Constraints

- **Žádná nová závislost.** `composer.json` ani `package.json` se nemění. Cookie lišta se píše ručně, ne přes hotovou CMP knihovnu.
- **`PageCachePolicy` se nemění.** Cookie souhlasu se nesmí stát důvodem k obcházení cache — to by zabilo cache většině návštěvníků (stejná úvaha, proč 3.0 zrušilo `has_cart`). Test to hlídá.
- **Bez souhlasu žádný cizí skript v HTML.** Ani `<script src>`, ani `<img>` pixel, ani preconnect. Testuje se na surovém HTML, ne na chování prohlížeče.
- **Kód anglicky**, uživatelské texty česky. **PHP 8.3.** **`./vendor/bin/pint`** před commitem.
- **Testy po adresářích**, ne jednou dávkou.

---

### Task 1: Jádro souhlasu

**Files:**
- Create: `app/Core/Consent/ConsentCategory.php` (enum `Necessary|Analytics|Marketing`)
- Create: `app/Core/Consent/Consent.php` (hodnotový objekt: verze, kategorie, čas)
- Create: `app/Core/Consent/ConsentCookie.php` (čtení a zápis cookie)
- Create: `config/consent.php`
- Test: `tests/Feature/Consent/ConsentCookieTest.php`

**Interfaces:**
- Produces: `Consent::fromCookie(?string $raw): ?Consent`, `Consent::allows(ConsentCategory): bool`, `Consent::acceptAll()/rejectAll()/of(array $categories)`
- Produces: `ConsentCookie::NAME = 'cookie_consent'`, `ConsentCookie::read(Request): ?Consent`, `ConsentCookie::queue(Consent): void`
- Produces: `config('consent.version')`, `config('consent.lifetime_days')` (default 180)

- [ ] **Step 1: Napiš padající testy**

1. Chybějící cookie → `null` (tedy „ještě se nerozhodl", ne „odmítl").
2. Poškozený JSON → `null`, ne výjimka. Návštěvník s rozbitou cookie dostane lištu znovu; pád na storefrontu kvůli cookie je nepřijatelný.
3. Cookie s **jinou verzí** než `config('consent.version')` → `null` (souhlas se starším zněním nepokrývá nové nástroje).
4. `Necessary` je povolená vždy, i u `rejectAll()`.
5. Zápis cookie: `httpOnly = false` (JS ji musí přečíst, aby skryl lištu), `sameSite = Lax`, životnost z configu.

- [ ] **Step 2: Implementuj.** Cookie **není** `httpOnly` schválně — JS ji čte, aby lištu skryl dřív než se vykreslí. Neobsahuje nic osobního, jen tři booleany a verzi; ochrana proti XSS je tady bezpředmětná, protože skript, který by ji četl, už běží na stránce.

- [ ] **Step 3: Ověř** — `php artisan test tests/Feature/Consent --compact`
- [ ] **Step 4: Commit** — `feat(consent): read and write the visitor's cookie consent`

---

### Task 2: Lišta a endpoint souhlasu

**Files:**
- Create: `app/Http/Controllers/ConsentController.php`
- Create: `resources/views/components/consent-banner.blade.php` (jádrová komponenta, ne modulová — lištu má mít i e-shop bez modulu `analytics`)
- Modify: `routes/web.php`
- Modify: `Modules/Storefront/Resources/views/layouts/shop.blade.php`
- Modify: `resources/js/storefront.js`
- Test: `tests/Feature/Consent/ConsentBannerTest.php`

**Interfaces:**
- Produces: `POST /souhlas-cookies` (`consent.store`), `GET /souhlas-cookies` (`consent.show` — stránka nastavení pro bez-JS cestu a pro odkaz v patičce)

- [ ] **Step 1: Napiš padající testy**

1. Anonymní GET storefrontu **bez cookie** obsahuje lištu.
2. Lišta má obě tlačítka a ta jsou v HTML **stejnou třídou** — rovnocennost se testuje na markupu, ne okem (spec AK 1).
3. POST „přijmout vše" nastaví cookie a přesměruje zpět; POST „odmítnout vše" totéž s opačnou hodnotou.
4. POST bez JS (běžný form submit) funguje — žádný `X-Requested-With`, žádný JSON.
5. **Stránka s lištou je nadále cachovatelná**: druhý požadavek se servíruje z cache a lišta v něm je (AK 5).
6. Návštěvník **s** cookie souhlasu dostane pořád tutéž cachovanou odpověď — cookie nezpůsobí miss (AK 6).
7. Odkaz „Nastavení cookies" je v patičce.
8. Lišta se nerenderuje v administraci ani na platform hostu.

- [ ] **Step 2: Komponenta.** Lišta na konci `<body>`, `role="dialog"` + `aria-label`, focus trap **ne** (lišta nesmí blokovat čtení stránky — ÚOOÚ nevyžaduje modální okno a modal by poškodil použitelnost i SEO). Obě tlačítka jako `<button type="submit" name="volba" value="…">` uvnitř jednoho formuláře.

- [ ] **Step 3: JS.** Do `resources/js/storefront.js`: přečti cookie a je-li platná, lištu **odstraň z DOM** dřív, než se vykreslí (inline skript v `<head>` nastaví na `<html>` třídu, CSS lištu skryje — jinak lišta problikne). Tlačítka pak fungují bez reloadu (`fetch` POST + skrytí), ale form zůstává funkční i bez JS.

- [ ] **Step 4: Ověř**

```
php artisan test tests/Feature/Consent tests/Feature/PageCache --compact
npm run build
```

- [ ] **Step 5: Commit** — `feat(consent): show a cache-safe cookie banner with equal accept and reject`

---

### Task 3: Modul `analytics` — kostra a nastavení

**Files:**
- Create: `Modules/Analytics/module.json` (`level: base`, permission `analytics.manage`, `settings_schema`, `settings_permission`)
- Create: `Modules/Analytics/settings.json`
- Create: `Modules/Analytics/Providers/ModuleProvider.php`
- Test: `tests/Feature/Modules/Analytics/AnalyticsSettingsTest.php`

**Nastavení:** `ga4_measurement_id`, `sklik_retargeting_id`, `sklik_conversion_id`, `meta_pixel_id`, `heureka_enabled`, `heureka_api_key`.

Validace **tvaru id**, ne jen `string`: `G-` prefix u GA4, číslice u Skliku a Meta. Překlep v id znamená měření, které tiše nikam nechodí — a to nájemce zjistí až po měsíci.

- [ ] **Step 1: Napiš padající testy** — modul je ve **všech** tarifech (`PlanModuleDefaults`, poučení z vlny 2.9: modul mimo tarif fakticky neexistuje); obrazovka nastavení je za `analytics.manage`; vadné id se odmítne s českou hláškou.
- [ ] **Step 2: Implementuj.** Žádná vlastní administrace — generická obrazovka z 2.10.
- [ ] **Step 3: Ověř** — `php artisan test tests/Feature/Modules/Analytics --compact`
- [ ] **Step 4: Commit** — `feat(analytics): add the base module with per-tenant measurement settings`

---

### Task 4: Měřicí kódy gated souhlasem

**Files:**
- Create: `Modules/Analytics/Support/TrackingCodes.php`
- Create: `Modules/Analytics/Resources/views/{ga4,sklik,meta}.blade.php`
- Modify: `Modules/Analytics/Providers/ModuleProvider.php` (composer na `storefront::layouts.shop`)
- Modify: `Modules/Storefront/Resources/views/layouts/shop.blade.php` (`@stack('tracking')`)
- Test: `tests/Feature/Modules/Analytics/TrackingCodesTest.php`

- [ ] **Step 1: Napiš padající testy**

1. **Bez souhlasu není v HTML `googletagmanager.com`, `seznam.cz` ani `connect.facebook.net`** (AK 3) — asertuje se na surové HTML.
2. Souhlas jen s analytickými → GA4 v HTML, Sklik a Meta ne (AK 4).
3. Nájemce bez vyplněného id → žádný kód, ale lišta ano (AK 8).
4. Vypnutý modul → žádné kódy, lišta zůstává (AK 10).
5. GA4 dostane `gtag('consent', 'default', …'denied')` **před** načtením gtag.js.
6. Měřicí id z jednoho e-shopu se nikdy neobjeví na jiném.

- [ ] **Step 2: Implementuj.** GA4 s Consent Mode v2 (default denied → update po souhlasu; bez toho se od 2024 nespáruje s Google Ads v EU). Sklik a Meta Consent Mode nemají, takže se jejich skript do HTML vkládá **až po** souhlasu.

**Pozor na page cache:** složení kódů závisí na souhlasu, tedy na cookie — a cachované HTML se souhlasem lišit nesmí. Řešení: do cachovaného HTML jde **jen konfigurace** (měřicí id jako `data-` atributy, per tenant, tedy cache-safe) a rozhodnutí, co spustit, dělá JS podle cookie. Žádný `<script src>` cizí domény se do HTML nerenderuje podmíněně na serveru.

- [ ] **Step 3: Ověř**

```
php artisan test tests/Feature/Modules/Analytics tests/Feature/PageCache --compact
```

- [ ] **Step 4: Commit** — `feat(analytics): load tracking scripts only after the visitor consents`

---

### Task 5: Konverze na děkovné stránce

**Files:**
- Create: `Modules/Analytics/Resources/views/purchase.blade.php`
- Modify: `Modules/Checkout/Resources/views/thank-you.blade.php`
- Test: `tests/Feature/Modules/Analytics/PurchaseTrackingTest.php`

- [ ] **Step 1: Napiš padající testy** — konverze nese číslo a hodnotu objednávky; odesílá se **jen** pro odsouhlasené kategorie (AK 7); děkovná stránka zůstává `no-store`; cizí objednávka nic nevyzradí.
- [ ] **Step 2: Implementuj.** Děkovná stránka je `no-store`, takže smí nést hodnotu objednávky přímo — na cachované stránce by šlo o únik mezi zákazníky.
- [ ] **Step 3: Ověř** — `php artisan test tests/Feature/Modules/Analytics tests/Feature/Modules/Checkout --compact`
- [ ] **Step 4: Commit** — `feat(analytics): report the purchase conversion on the thank-you page`

---

### Task 6: Heureka Ověřeno zákazníky

**Files:**
- Create: `Modules/Analytics/Services/HeurekaVerified.php`
- Modify: `Modules/Analytics/Resources/views/purchase.blade.php`
- Modify: `Modules/Pages/Support/PageTemplates.php` (odstavec do vzoru zásad)
- Test: `tests/Feature/Modules/Analytics/HeurekaVerifiedTest.php`

- [ ] **Step 1: Napiš padající testy** — odesílá se jen se zapnutým přepínačem a vyplněným klíčem; **není** gated souhlasem (neukládá cookies, právní titul je oprávněný zájem); API klíč se nikdy nevrátí do HTML ani do administrace.
- [ ] **Step 2: Implementuj.** Klíč patří k credentials podle §16.5 — šifrovaný, v administraci maskovaný, keep-on-update (precedent Comgate a Packeta).
- [ ] **Step 3: Ověř** — `php artisan test tests/Feature/Modules/Analytics --compact`
- [ ] **Step 4: Commit** — `feat(analytics): send completed orders to Heureka Overeno zakazniky`

---

### Task 7: Uzavření vlny

- [ ] **Step 1:** Testy po adresářích, zapiš skutečný počet.
- [ ] **Step 2:** **Ruční ověření na demu** — poučení z vlny 2.9: zelené testy neznamenají, že si nájemce funkci může zapnout. Projít: zapnout modul, vyplnit GA4 id, ověřit lištu, odmítnout, zkontrolovat že v HTML není `googletagmanager`, přijmout, ověřit že je.
- [ ] **Step 3:** `docs/as-is/2026-08-05-cookie-lista-mereni.md`, `STATUS.md`, rozhodnutí do CLAUDE.md, odškrtnout „Cookies / ePrivacy" v checklistu.
- [ ] **Step 4:** `VERSION` → `0.38.0`, `CHANGELOG.md`, merge, push.

---

## Rizika

| Riziko | Dopad | Mitigace |
|---|---|---|
| Měřicí kód se spustí před souhlasem | Porušení ePrivacy, pokuta pro **nájemce** | Test na surové HTML (Task 4, test 1), ne na chování prohlížeče |
| Lišta zabije page cache | Ztráta výkonu z celé vlny 3.0 | `PageCachePolicy` se nemění; testy 5 a 6 v Tasku 2 |
| Lišta problikne před skrytím | Kazí dojem na každé stránce | Inline skript v `<head>` nastaví třídu na `<html>`, CSS skryje — ne až JS na konci body |
| Nerovnocenná tlačítka | Souhlas není platný | Test na shodnou třídu v markupu (Task 2, test 2) |
| Nájemce zapne vše a zpomalí si e-shop | Horší Lighthouse | Vše asynchronně a až po souhlasu; výkon cizích skriptů je nájemcova volba, zmíněno v nápovědě u nastavení |
| Modul mimo tarif | Nájemce si ho nemůže zapnout | `PlanModuleDefaults` + test (poučení z 2.9) |
