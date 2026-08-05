# As-is: technické dluhy (stale tenant, feed cache, e-mail o stavu, routa stránek) — vlna 3.1

Datum: 2026-08-05 · Verze: **0.36.0** (minor otevírá vlnu 3.1) · Větev: `feature/vlna-31-technicke-dluhy`

Plán: [`docs/superpowers/plans/2026-08-05-vlna-31-technicke-dluhy.md`](../superpowers/plans/2026-08-05-vlna-31-technicke-dluhy.md)

Samostatná spec neexistuje — vlna zavírá dluhy evidované v [`STATUS.md`](STATUS.md) sekce „Známá omezení" a v rozhodnutích CLAUDE.md z 2026-08-03.

## Co vlna přinesla

Čtyři nesené dluhy, každý jiného druhu:

1. **Stale instance tenanta.** `TenantContext::set()` dostal ve vlně 3.0 obezličku kolem short-circuitu `spatie/laravel-multitenancy`, ale `runAs()` a `runWithoutTenant()` volaly `makeCurrent()` napřímo — díra tedy zůstala otevřená pro každou cestu, která přes ně přepíná.
2. **Feed držel zrušenou dopravu.** `ShippingMethod` chyběl v mapě `PageCacheObserver`, takže přejmenovaný nebo zdražený dopravce zůstal ve feedu Heureky až hodinu.
3. **Tichá změna stavu e-shopu.** Poštu posílal jen lifecycle sweeper, ze svých dvou míst. Superadmin pozastavení i selhaná platba přes Stripe byly němé — nájemce se to dozvěděl tím, že mu přestal fungovat admin.
4. **Statické stránky na `/stranka/{slug}`.** Odchylka od závazného pravidla storefrontu, která blokovala vlnu 3.3 (VOP nájemce nemá končit na `/stranka/obchodni-podminky`).

## Mapa změn

29 souborů, +1300/−56 (`git diff --stat a0bbe26..HEAD`).

| Soubor | Změna |
|--------|-------|
| `app/Core/Tenancy/TenantContext.php` | `runAs()`/`runWithoutTenant()` přepínají přes `set()`/`forget()`, ne přes `makeCurrent()`/`forgetCurrent()` |
| `app/Core/PageCache/PageCacheObserver.php` | `ShippingMethod` → `Dimension::Catalog` + odůvodnění, proč Catalog a proč ne `PaymentMethod` |
| `app/Providers/AppServiceProvider.php` | observer i pro `ShippingMethod`; `Event::listen(TenantStatusChanged → SendTenantStatusMail)` |
| `app/Core/Tenancy/Events/TenantStatusChanged.php` | **nový** doménový event, nese `from` i `to` a důvod |
| `app/Models/Tenant.php` | `changeStatus()` dispatchne event přes `DB::afterCommit` |
| `app/Core/Billing/Listeners/SendTenantStatusMail.php` | **nový** jediný listener; mapa `(from, to)` → zpráva, vždy `MailKind::Transactional` |
| `app/Core/Billing/Mail/PaymentFailedMail.php` | **nový** — selhaná platba (odlišný od expirace trialu na stejný cílový stav) |
| `app/Core/Billing/Mail/ShopReactivatedMail.php` | **nový** — návrat z `past_due`/`suspended` |
| `app/Core/Billing/Mail/PendingDeletionMail.php` | **nový** — nese i důvod |
| `resources/views/billing/mail/{payment-failed,shop-reactivated,pending-deletion}.blade.php` | **nové** šablony |
| `app/Console/Commands/SweepTenantLifecycle.php` | **ztratil** obě vlastní odeslání i závislost na `MailService` |
| `Modules/Pages/routes/storefront.php` | `Route::fallback()` na kořeni + `legacy` 301 z `/stranka/{slug}` |
| `Modules/Pages/Http/Controllers/PageController.php` | `show()` čte cestu z requestu a odmítá víc segmentů; nová `legacy()` |
| `Modules/Storefront/Http/Controllers/SitemapController.php` | `url('/'.$page->slug)` |
| `resources/js/Pages/Modules/Pages/Index.vue` | náhled URL v adminu |
| `tests/Feature/Tenancy/TenantContextTest.php` | **nový** — 6 testů |
| `tests/Feature/Tenancy/TenantStatusMailTest.php` | **nový** — 9 testů |
| `tests/Feature/Modules/Pages/PageRoutingTest.php` | **nový** — 20 testů (13 metod, z toho jedna parametrizovaná 8×) |
| `tests/Feature/Platform/TenantStatusTest.php` | + test, že superadmin pozastavení píše vlastníkovi |
| `tests/Feature/Billing/SweepTenantLifecycleTest.php` | + `assertSent(…, 1)` proti návratu duplicity |
| `tests/Feature/PageCache/XmlOutputInvalidationTest.php` | + test na dopravu ve feedu; sitemap očekává nové URL |
| `tests/Feature/Modules/ModuleRoutingTest.php`, `tests/Feature/PageCache/PageCacheInvalidationTest.php` | přepsané cesty stránek |

## Plnění po úkolech

### Task 1 — `runAs()` a `runWithoutTenant()`

`spatie/laravel-multitenancy` má v `makeCurrent()` krátký obvod klíčovaný **jen na primárním klíči** (`isCurrent()`). Předaný čerstvě načtený model téhož nájemce se proto nikdy nesvázal a callback četl atributy staré instance. Trefilo by to `TenantController::updateStatus`, oba Stripe handlery, `SweepTenantLifecycle`, `TenantProvisioner` i každý zápis do `AuditLog` — na kterémkoli workeru, který přežije víc než jeden request (dnes testová sada, později případně Octane).

Obě metody teď přepínají přes `set()`, který výměnu instance dělá bez spuštění switch-task pipeline (ta při stejném id nemá co dělat, a `PrefixCacheTask` by při každém přepnutí zapomněl cache driver — u `array` driveru by z každého requestu udělal cache miss).

**Nezměněno:** `set()` sám. Jeho obezlička z vlny 3.0 platí dál a je teď jediným místem, kde se short-circuit řeší.

### Task 2 — doprava ve feedu

`FeedController` čte `ShippingOptions` a šablony obou feedů tisknou blok `DELIVERY` per způsob dopravy. Klíč feedu nese razítko `Catalog`, takže stačil jeden řádek v mapě observeru.

`Catalog` je široká dimenze na jednoho dopravce — bump vyprázdní i produktové a kategorijní stránky, sitemap a druhý feed. Vědomý kompromis: dopravu nájemce mění řádově jednou za měsíce, kdežto nastavení feedu ladí v sériích, a právě proto má `FeedAdminController` cílený `Cache::forget` místo bumpu. `PaymentMethod` se nepřidával: žádný feed ho nenese a jediná stránka, kde se objevuje, je pokladna (`no-store`).

### Task 3 — pošta o změně stavu

Event dispatchuje `changeStatus()` sám, ne volající. Volajících je dnes pět a přibývají; instrumentovat je po jednom je stejná chyba jako instrumentovat writery místo observeru.

Dispatch je odložený přes `DB::afterCommit`, protože `StripeWebhookHandler` mění stav uvnitř transakce, která nese i idempotenční claim — inline odeslání by informovalo o změně, která se pak vrátila zpět. Mimo transakci Laravel callback spustí okamžitě, takže superadmin cesta se nemění.

Mapa čte **oba konce** přechodu:

| from | to | zpráva |
|---|---|---|
| `trial` | `past_due` | `TrialExpiredMail` |
| jiný | `past_due` | `PaymentFailedMail` |
| libovolný | `suspended` | `ShopSuspendedMail` |
| `past_due`/`suspended` | `active` | `ShopReactivatedMail` |
| libovolný | `pending_deletion` | `PendingDeletionMail` (+ důvod) |
| libovolný | `deleted` | žádná |
| `trial` | `active` | žádná |

`trial → active` mlčí schválně: první platbu potvrzuje Stripe vlastní účtenkou a `PlatformInvoiceWriter` českým daňovým dokladem. `→ deleted` mlčí, protože `pending_deletion` už poslední slovo mělo.

Vždy `MailKind::Transactional` — zpráva o nezaplacené faktuře nesmí být to, co nezaplacená faktura zablokuje. Nájemce bez vlastníka (žádný řádek `tenant_users` s rolí owner) listener tiše přeskočí.

### Task 4 — `/{page-slug}`

Mechanismus je `Route::fallback()`, ne catch-all `/{slug}` a ne blacklist rezervovaných segmentů. Laravel vyhodnocuje fallback až po všech ostatních routách bez ohledu na pořadí registrace — a to je load-bearing, protože `ModuleRouteRegistrar` iteruje `glob()` abecedně, takže `pages` se registruje **před** `products` i `storefront` a catch-all by opravdu spolkl `/kosik`, `/hledani` a zbytek. Blacklist by naopak musel někdo ručně rozšiřovat při každé nové routě a chyběl by tiše.

Cena fallbacku je, že matchuje i víceúrovňové cesty a `/admin/*`. Controller proto odmítne cokoli s lomítkem a `abort(404)` — čímž request předá `RedirectResponder`u, který obsluhuje přejmenované slugy (301, spec §15.3) a od vlny 3.0 tyhle 404 i cachuje. Na tuhle vazbu je vlastní test.

Stará cesta `/stranka/{slug}` odpovídá 301 a **nedotazuje databázi**: redirect platí i pro slug, který nikdy neexistoval (nový tvar tam vrátí 404 sám), a dotaz by z routy udělal oracle na to, které stránky nájemce má.

Gating zůstává modulový (`module:pages`), takže platform host i e-shop bez modulu dostanou 404 jako dřív.

## Testy

**1913 testů, vše zelené** (136 unit + 1777 feature). Před vlnou 1882.

| Sada | Testů |
|---|---|
| `tests/Unit` | 136 |
| `tests/Feature/{Auth,Billing,Core,Database,Domains}` | 335 |
| `tests/Feature/{Onboarding,PageCache,Platform,Storefront,Tenancy,Tenant,Theme}` + kořenové | 423 |
| `tests/Feature/Modules` | 1019 |

Plná sada se **nepouští jedním příkazem** — přeteče timeout a shodí sdílenou MySQL testovací databázi.

Nová pokrytí:
- výměna instance při `runAs()` i `runWithoutTenant()`, včetně obnovy po výjimce
- doprava ve feedu bez čekání na TTL
- pošta pro každý přechod, včetně no-op přechodu, vráceného přechodu (rollback) a nájemce bez vlastníka
- **parametrizovaný test nad všemi jednosegmentovými storefront cestami** — `/kosik`, `/hledani`, `/registrace`, `/prihlaseni`, `/ucet`, `/zapomenute-heslo`, `/sitemap.xml`, `/robots.txt`. Každá se navíc testuje se stránkou, která má **stejný slug**, aby bylo jisté, že fallback nikdy nepřebije skutečnou routu.
- přejmenovaný slug produktu dál 301uje i s fallbackem v cestě

## Odchylky od plánu

1. **Superadmin test bydlí jinde.** Plán ho měl v novém `TenantStatusMailTest`; sedí v `tests/Feature/Platform/TenantStatusTest.php`, kde už je `ActsAsPlatformAdmin`, `usePlatformHost()` a `platformUrl()`. Postavit tu infrastrukturu podruhé v jiné složce by nepřineslo nic.
2. **`ShippingMethod` nedostal vlastní kontrakt.** Plán o něm uvažoval; jeden řádek v existující mapě nepotřebuje vrstvu bez druhého volajícího.
3. **`TenantContext::set()` byl už opravený** před touto vlnou (vlna 3.0). Plán s tím počítal — Task 1 zavíral jen zbytek díry v `runAs()`.

## Nálezy během implementace

- **`Modules\Pages\Lifecycle` už seeduje tři stránky při aktivaci modulu** (`obchodni-podminky`, `ochrana-osobnich-udaju`, `kontakt`) — prázdné a nepublikované. Vlna 3.3 tedy nezakládá stránky od nuly, jen je naplní obsahem a publikuje.
- **Git heredoc v commit zprávě neunese apostrof** v `tenants'` — commit zpráva se musela předat souborem (`-F`). Poznámka pro příště.

## Technický dluh, který vlna nese dál

- **Rekonciliace pošty s `MailLimitCounter`.** Zprávy o stavu se počítají do měsíčního limitu (jsou `Transactional`, takže neblokují, ale započítají se). To je záměr, ne dluh — jen to není nikde v adminu vidět.
- **`Modules\Pages\Lifecycle` seeduje nepublikované stránky s prázdným tělem.** Nájemce, který je nikdy neotevře, má tři neviditelné stránky. Vlna 3.3 to řeší obsahem.
- **Fallback matchuje i `POST`/`PUT` na neznámou cestu?** Ne — `Route::fallback()` registruje jen `GET`/`HEAD`. Ověřeno; poznamenáno, aby se to nemuselo hledat znovu.
- **Řádek „Doprava a poplatky" v účetním exportu** (dluh z 2.11) zůstává; vlna 2.12 ho vyřešila pro nové doklady, historické si ho dopočítávají dál.

## Pre-deploy checklist

Nic nového. Vlna nemění schéma (žádná migrace), nepřidává závislost ani konfiguraci.

Jediná provozní poznámka: **`/stranka/{slug}` po nasazení začne 301ovat.** Pokud má nájemce tuhle cestu v obsahu vlastní stránky nebo v e-mailu, odkaz dál funguje, jen přes jeden skok navíc.
