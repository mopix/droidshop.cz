# As-is: cookie lišta a měřicí kódy nájemce — vlna 3.3

Datum: 2026-08-05 · Verze: **0.38.0** (minor otevírá vlnu 3.3) · Větev: `feature/vlna-33-cookie-lista`

Spec: [`docs/superpowers/specs/2026-08-05-vlna-33-cookie-lista-mereni-design.md`](../superpowers/specs/2026-08-05-vlna-33-cookie-lista-mereni-design.md)
Plán: [`docs/superpowers/plans/2026-08-05-vlna-33-cookie-lista-mereni.md`](../superpowers/plans/2026-08-05-vlna-33-cookie-lista-mereni.md)

## Co vlna přinesla

Nájemce dosud neměl jak zjistit, odkud mu chodí zákazníci a co se z nich stane. Měřicí kódy ale nešly nasadit bez souhlasu — zásady cookies z vlny 3.2 to nájemcům výslovně slibovaly („nástroje pro správu souhlasu připravujeme"). Obojí proto přišlo najednou: lišta bez kódů nemá co blokovat, kódy bez lišty jsou protiprávní.

- **Souhlas se třemi kategoriemi** (nezbytné / analytické / marketingové), jádrová infrastruktura `app/Core/Consent/`
- **Cookie lišta**, která nestojí ani jeden cache hit
- **Modul `analytics`** (base) s nastavením GA4, Skliku, Meta Pixelu a Heureky
- **Konverze** na děkovné stránce
- **Heureka Ověřeno zákazníky** mimo souhlasový režim

## Mapa změn

36 souborů, +2861/−20 (`git diff --stat f7e4a05..HEAD`).

| Soubor | Změna |
|--------|-------|
| `app/Core/Consent/ConsentCategory.php` | **nový** — enum, `isRefusable()`, popisy pro lištu |
| `app/Core/Consent/Consent.php` | **nový** — hodnotový objekt nad cookie, `fromCookie()` vrací null na cokoli nejistého |
| `app/Core/Consent/ConsentCookie.php` | **nový** — čtení a zápis, ne-httpOnly, `SameSite=Lax`, 180 dnů |
| `config/consent.php` | **nový** — `version`, `lifetime_days` |
| `bootstrap/app.php` | cookie vyloučena z šifrování |
| `app/Http/Controllers/ConsentController.php` | **nový** — POST rozhodnutí, GET obrazovka nastavení |
| `resources/views/components/consent-banner.blade.php` | **nová** jádrová komponenta |
| `resources/views/consent/settings.blade.php` | **nová** obrazovka nastavení |
| `routes/web.php` | `/souhlas-cookies` (GET + POST), bez `page-cache` |
| `Modules/Storefront/Resources/views/layouts/shop.blade.php` | inline skript v `<head>`, `data-consent-version`, lišta, `@stack('tracking')`, odkaz v patičce |
| `resources/js/storefront.js` | čtení souhlasu, odeslání bez reloadu, `window.droidshopConsent`, event `consent:changed` |
| `app/Core/Settings/SettingsField.php` + `SettingsSchema.php` | **nový příznak `secret`** |
| `app/Core/Settings/SettingsService.php` | šifrování secret polí, keep-on-update, fail-closed dešifrování |
| `app/Http/Controllers/Tenant/ModuleSettingsController.php` | maskování secret polí (`{key}_stored`) |
| `resources/js/Pages/Tenant/ModuleSettings.vue` | typ `password`, hláška „Uloženo" |
| `Modules/Analytics/*` | **nový modul** — manifest, settings, provider, `TrackingCodes`, `PurchasePayload`, `HeurekaVerified`, listener, tři šablony |
| `Modules/Checkout/Resources/views/thank-you.blade.php` | `@push('tracking')` s konverzí |
| `Modules/Pages/Support/PageTemplates.php` | odstavec o Heurece do vzoru zásad |
| `tests/Feature/Consent/*`, `tests/Feature/Modules/Analytics/*` | **5 nových testovacích souborů** |

## Plnění akceptačních kritérií

| # | Kritérium | Stav |
|---|---|---|
| 1 | Lišta bez cookie; obě tlačítka stejná | splněno, test na shodu tříd v markupu |
| 2 | Souhlas i odmítnutí bez JS | splněno, ověřeno i ručně curlem |
| 3 | Bez souhlasu žádný gtag.js / Sklik / Meta | splněno, test na `src`/`href` |
| 4 | Jen analytické → GA4 ano, Sklik a Meta ne | splněno (gate v JS) |
| 5 | Stránka s lištou je nadále cachovatelná | splněno |
| 6 | Souhlas nezpůsobí cache miss | splněno |
| 7 | Konverze jen pro odsouhlasené kategorie | splněno (gate v JS) |
| 8 | Nájemce bez id: žádný kód, lišta ano | splněno |
| 9 | Odkaz „Nastavení cookies" v patičce | splněno |
| 10 | Vypnutý modul: žádné kódy, lišta zůstává | splněno |

## Rozhodnutí a nálezy

### Server renderuje konfiguraci, nikdy rozhodnutí

Klíč celé vlny. Měřicí id jsou **per tenant** — stejná pro každého návštěvníka, takže smějí do cachovaného HTML. Souhlas je **per návštěvník** a do cachovaného HTML nesmí; Blade `@if` na souhlasu by z cachované stránky udělal roznašeč cizího rozhodnutí.

Server proto vloží jen `<script type="application/json" id="tracking-config">` s id a JS podle cookie rozhodne, co spustit. Test asertuje, že HTML je **byte-identické** pro nerozhodnutého, souhlasícího i odmítajícího; ruční ověření na demu to potvrdilo (jediný rozdíl je CSRF token, což je záměrné chování page cache z 3.0).

### GA4 se nenačítá s „denied", ačkoli to Google doporučuje

Consent Mode v2 dovoluje načíst gtag.js se vším zamítnutým. Nepoužíváme to: **požadavek stejně dorazí ke Googlu a nese IP adresu návštěvníka**, a právě požadavek před souhlasem je to, co ePrivacy zakazuje — bez ohledu na to, jestli se vrátí cookie. Volání `consent default denied` přesto běží první, protože bez něj se od 2024 GA4 v EU nespáruje s Google Ads.

### Lišta je v jádře, kódy v modulu

Cookie lišta je právní povinnost každého e-shopu, i toho, který nic neměří — modul, který jde vypnout, ji držet nemůže. Kódy naopak modul jsou.

### `SettingsService` umí credentials

Tabulka `settings` ukládá prostý JSON, ale Heureka API klíč je credential podle §16.5. `SettingsField` proto dostal příznak `secret`: šifrování při zápisu, nikdy se nevrací do administrace (jen `{key}_stored` boolean), a **prázdné pole při uložení znamená „neměnit"** — stejné keep-on-update pravidlo, jaké mají formuláře Comgate a Packety. Dešifrování selhává do prázdna, ne do ciphertextu: hodnota po rotaci `APP_KEY` by se jinak odeslala třetí straně jako by to byl klíč.

### Co odhalily testy a ruční ověření

- **`SettingsSchema::fields()` je `array_values()`d**, takže `array_keys(array_filter(...))` vracelo pozice, ne názvy polí — šifrování secret polí tiše nefungovalo.
- **`withCookie()` v Laravel testech cookie šifruje.** Pro cookie vyloučenou ze šifrování je potřeba `withUnencryptedCookie()`; bez toho se souhlas v testu nikdy nepřečetl a obrazovka nastavení vypadala, že ignoruje uloženou volbu.
- **`window.droidshopConsent` vzniká v deferovaném modulu**, takže inline skript na konci `<body>` ho nenajde. Tracking se odkládá na `DOMContentLoaded`.
- **Ruční ověření na demu** (poučení z 2.9): modul je v tarifu, jde zapnout, nastavení se uloží, obrazovka `/admin/nastaveni/moduly/analytics` odpovídá 200 přihlášenému ownerovi.

## Testy

**2003 testů, vše zelené** (bylo 1954). 49 nových.

| Sada | Testů |
|---|---|
| `tests/Unit` + `Auth`, `Billing`, `Consent`, `Core`, `Database`, `Domains`, `Legal` | 514 |
| `Onboarding`, `PageCache`, `Platform`, `Storefront`, `Tenancy`, `Tenant`, `Theme` + kořenové | 428 |
| `tests/Feature/Modules` | 1061 |

## Technický dluh

- **Chování JS neověřuje žádný automatický test.** PHP test umí ověřit jen to, že v HTML není nic, co by samo vyvolalo požadavek; že skript pak souhlas skutečně respektuje, je ověřeno **ručně** a čeká na Playwright ve vlně 3.4. Toto je největší mezera vlny.
- **Souhlas není doložitelný.** Cookie je u návštěvníka; nájemce nemá čím prokázat, že souhlas dostal. Server-side log by sám zpracovával osobní údaje, takže to není samozřejmá volba.
- **Změna nastavení neinvaliduje souhlasy.** `config('consent.version')` se musí zvednout ručně; nájemce, který přidá Meta Pixel, dostane souhlas udělený v době, kdy tam nebyl. Automatika čeká na to, až bude vidět, jak často nájemci nástroje mění.
- **Bez JS lišta zůstává viditelná** i po souhlasu. Bez JS se ale žádný kód nespustí, takže neblokuje nic.
- **Konverze se opakovaně pokouší** o report (5× po 400 ms), než jsou knihovny vendorů načtené. Když návštěvník zavře stránku dřív, konverze se ztratí — server-side měření je mimo rozsah.
- **Sklik `conversionHit` a Meta `Purchase`** nebyly ověřeny proti reálným účtům (v testech `Http::fake`, v prohlížeči jen náš vlastní kód).

## Pre-deploy checklist

- [ ] `php artisan modules:sync` **před** `migrate` (nový modul), pak `npm run build`
- [ ] Ověřit s **reálnými** účty: GA4 (přijde-li `page_view` a `purchase`), Sklik konverze, Meta Pixel. Testy je stubují.
- [ ] Ověřit Heureku s reálným API klíčem — endpoint je z veřejné dokumentace, ne z ověřeného volání
- [ ] Projít lištu v prohlížeči se zapnutým blokátorem reklam (blokátor může zablokovat i náš vlastní loader)
- [ ] Zkontrolovat, že nájemci, kteří si zapnou měření, doplnili odstavec o cookies do svých zásad — vzor to obsahuje, ale publikuje ho nájemce
