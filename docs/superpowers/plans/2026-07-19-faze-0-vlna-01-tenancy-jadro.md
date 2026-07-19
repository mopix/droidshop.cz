# Fáze 0 / vlna 0.1 — Tenancy jádro, izolace, CI — implementační plán

> **STAV: dokončeno 2026-07-19**, verze 0.2.0, větev `feat/tenancy-core`.
> Bloky A–H hotové, 80 testů zelených. Skutečný stav a odchylky:
> [`docs/as-is/2026-07-19-tenancy-jadro.md`](../../as-is/2026-07-19-tenancy-jadro.md).
> Kroky níže zůstávají v původním znění; kde se realizace lišila, je to
> poznamenáno u dotčeného bloku.

> **Pro agenta:** Použij superpowers:executing-plans nebo subagent-driven-development. Kroky s `- [ ]`.

**Cíl:** Request na `nazev.droidshop.cz` rozpozná tenanta, nastaví kontext a od té chvíle je datově nemožné sáhnout na data cizího tenanta — ověřeno testy v CI.

**Architektura:** Shared DB + `tenant_id` (spec §4.2 varianta B) se třemi pojistkami: globální scope na modelech (`BelongsToTenant`), middleware pipeline rozpoznávající tenanta z Host hlavičky (`domains` lookup), a povinné CI testy izolace. Tenant kontext se propaguje do jobů. Kernel služby dle spec §15.1 píšeme sami; `spatie/laravel-multitenancy` dodá jen resolver a task pipeline.

**Tech stack:** Dle `docs/PROJECT-PROFILE.md` — Laravel 13, PHP 8.3, MySQL 8, Redis, PHPUnit.

**Spec:** `docs/specs/2026-07-17-eshop-platforma-specifikace.md` — §4.2, §4.3, §6.0, §15.1–15.4, §15.6

**Rozhodnutí:** CLAUDE.md 2026-07-19 (spatie/laravel-multitenancy, nwidart odložen do vlny 0.2)

---

## Rozsah

### Ve vlně 0.1

Tenant resolution z Host hlavičky, stavový automat tenanta (jen přechody + gating, ne billing triggery), `BelongsToTenant`, propagace do jobů, cache prefix, `TenantContext`, `AuditLog`, testy izolace, GitHub Actions CI, přechod na MySQL + Redis.

### Mimo vlnu 0.1 (vlna 0.2+)

Systém modulů (nwidart, manifesty, `tenant_modules`, kill switch), superadmin guard + `platform_admins`, onboarding průvodce a registrace, S3 `FileStorage`, `LimitsService`, `SequenceService`, `SettingsService`, outbox `pending_events`, webhooky, storefront, deploy pipeline.

**Poznámka k `plans`:** tabulku zakládáme (FK `tenants.plan_id`), ale bez `plan_modules` a bez billing logiky — jen seed dvou tarifů, aby šlo tenanta založit.

---

## Kroky

### A. Prostředí a závislosti — HOTOVO 2026-07-19

- [x] A1. Lokální konfigurace žije v `.env.local` (MySQL `droidshop`, Redis pro cache/queue/session, `PLATFORM_DOMAIN=droidshop`, `APP_URL=https://droidshop`). `SESSION_DOMAIN` zůstává `null` — **oproti původnímu návrhu**: host-only cookie znamená, že session tenanta A nedoputuje na doménu tenanta B (spec §15.4).
- [x] A2. Načítání `.env.local` řešeno v `bootstrap/app.php` přes `loadEnvironmentFrom()` pod `file_exists()` guardem. **Původní návrh (`export APP_ENV=local`) zamítnut** — je to proměnná pro celý účet a na stroji je 7 dalších projektů s `.env.local`, kterým by tiše přepnula konfiguraci. Bootstrap varianta má působnost jen tento projekt a pokrývá web, artisan, queue i testy jednotně. Pozor na sémantiku: Laravel načte `.env.local` **místo** `.env`, ne jako překryv — musí být kompletní.
- [x] A3. `composer remove stripe/stripe-php`.
- [x] A4. `composer require spatie/laravel-multitenancy:^4.1`. Publikace configu a napojení na `Tenant` model + `DomainTenantFinder` **přesunuto do bloku C** (model ještě neexistuje).
- [x] A5. `phpunit.xml` → MySQL `droidshop_testing`.
- [x] A6. `php artisan migrate` + `php artisan test` → **25 passed (61 assertions)**.

**Poznámka k A6:** původní znění bylo `migrate:fresh`. Změněno na `migrate` — v DB `droidshop` byla v době psaní plánu cizí aplikace (21 tabulek, 8 uživatelů, time-tracking data). Uživatel ji před spuštěním vyčistil. `migrate:fresh` v tomto plánu už nepoužívat.

**Stav prostředí navíc (mimo původní plán):**
- PHP rozšíření `redis` chybělo; `pecl` ho uložil mimo `extension_dir`. Vyřešeno symlinkem jen verzované složky `20230831` (php@8.0 a php@8.4 na stroji zůstávají nedotčené).
- nginx `server_name droidshop *.droidshop`, mkcert certifikát se SAN `droidshop` + `*.droidshop`.
- **Známé omezení:** `curl` odmítne wildcard `*.droidshop` (OpenSSL nepovoluje wildcard nad jedinou úrovní). Prohlížeč projde, `curl` potřebuje `-k`. Dopadá na kontrolní seznam ve `storefront-rendering.md` a na budoucí Playwright E2E. Trvalá oprava = přejmenovat lokální doménu na dvouúrovňovou (`droidshop.test`); uživatel 2026-07-19 zvolil zůstat u `droidshop`.

### B. Datový model jádra

Migrace přesně dle spec §15.3. Konvence: `tenant_id BIGINT UNSIGNED NOT NULL` první ve všech composite indexech, FK na `tenants` s `ON DELETE RESTRICT`.

- [ ] B1. Test `tests/Feature/Core/CoreSchemaTest.php` — očekává existenci tabulek a klíčových sloupců. Červený.
- [ ] B2. Migrace `plans` (id, key UQ, name, price_month, price_year, level, is_public, limits JSON).
- [ ] B3. Migrace `tenants` dle §15.3 — pozor: `status` ENUM(trial,active,past_due,suspended,pending_deletion,deleted), `uuid` UQ, `plan_id` FK, `currency` DEF 'CZK', `country` DEF 'CZ'.
- [ ] B4. Migrace `domains` (domain UQ, type ENUM(subdomain,custom), `is_primary`, `ssl_status`) + `tenant_users` (PK composite, role ENUM(owner,staff), `permissions` JSON NULL).
- [ ] B5. Migrace `audit_log` (`tenant_id` NULL — platformní akce nemají tenanta) + `jobs_log`.
- [ ] B6. Modely `Tenant`, `Domain`, `Plan`, `AuditLogEntry` s vztahy a return type hinty; `Tenant` extends spatie `IsTenant`. Enum třídy `TenantStatus`, `DomainType`, `TenantRole` (PHP backed enums, cast na modelu).
- [ ] B7. Factories pro `Tenant`, `Domain`, `Plan`, `User`. Seeder `PlanSeeder` (base, premium).
- [ ] B8. Zeleně B1. Commit `feat: add tenancy core schema`.

### C. Rozpoznání tenanta a middleware pipeline

Pipeline dle spec §15.2.

- [ ] C1. Test `tests/Feature/Core/TenantResolutionTest.php`: známý host → kontext nastaven; neznámý host → 404; hlavní doména platformy → kontext prázdný (ne 404). Červený.
- [ ] C2. `app/Core/Tenancy/TenantContext.php` — `current(): ?Tenant`, `id(): ?int`, `runAs(Tenant $t, Closure $fn)`. `runAs` musí kontext v `finally` vrátit na původní hodnotu (i při výjimce) — pokrýt testem.
- [ ] C3. `app/Core/Tenancy/DomainTenantFinder.php` — lookup `domains.domain` podle Host, s cache `domain:{host}` (TTL 5 min, invalidace při zápisu do `domains`).
- [ ] C4. Middleware `ResolveHost` — rozliší platformu vs. tenanta; rezervované subdomény (`www`, `admin`, `api`, `mail`) nikdy neřeší jako tenanta.
- [ ] C5. Middleware `CheckTenantStatus` — `suspended` / `pending_deletion` / `deleted` → 503 stránka „e-shop nedostupný"; `trial` / `active` / `past_due` → průchod. Šablona 503 zatím minimální Blade.
- [ ] C6. Middleware `SetTenantContext` — naplní `TenantContext`, nastaví cache prefix `tenant:{id}`, locale a měnu z tenanta.
- [ ] C7. Registrace v `bootstrap/app.php` do skupiny `web` ve správném pořadí (Resolve → CheckStatus → SetContext, před `HandleInertiaRequests`).
- [ ] C8. Zeleně C1. Commit `feat: resolve tenant from host header`.

### D. Datová izolace — hlavní pojistka

- [ ] D1. Test `tests/Feature/Core/TenantIsolationTest.php` s dvěma tenanty a testovacím modelem: čtení, `find()`, `update`, `delete` a agregace nesmí přesáhnout hranici tenanta. Zvlášť ověřit, že `Model::create()` doplní `tenant_id` sám a že explicitní cizí `tenant_id` v `create()` je **přepsán, ne respektován**. Červený.
- [ ] D2. `app/Core/Tenancy/BelongsToTenant.php` — trait: global scope `where tenant_id`, `creating` hook doplní `tenant_id` z kontextu, `withoutTenantScope()` escape hatch pro systémové joby.
- [ ] D3. Chování bez kontextu: dotaz na tenant-scoped model bez aktivního tenanta **hodí výjimku** `MissingTenantContextException`, nikdy nevrátí data všech tenantů. Test na to.
- [ ] D4. `app/Core/Tenancy/Concerns/UsesTenantConnection.php` není potřeba (shared DB) — místo toho **architektonický test** `tests/Unit/Core/SchemaConventionTest.php`: projde migrace, a každá tabulka mimo whitelist platformních tabulek musí mít `tenant_id`. Chrání proti zapomenutému sloupci v budoucích modulech.
- [ ] D5. Zeleně D1–D4. Commit `feat: enforce tenant isolation on models`.

### E. Propagace kontextu do jobů a cache

- [ ] E1. Test `tests/Feature/Core/TenantJobContextTest.php`: job dispatchnutý v kontextu tenanta A vidí při zpracování tenanta A, i když se mezitím kontext změnil. Červený.
- [ ] E2. Zapnout spatie tasky (`SwitchTenantDatabaseTask` **vypnout** — shared DB; použít prefix cache task) + vlastní `TenantAwareJob` middleware serializující `tenant_id` do payloadu.
- [ ] E3. Job **bez** tenant kontextu smí sahat jen na platformní tabulky — zajištěno D3 (výjimka), doplnit test.
- [ ] E4. Cache: ověřit, že klíč zapsaný v kontextu tenanta A není čitelný v kontextu tenanta B. Test.
- [ ] E5. Zeleně E1–E4. Commit `feat: propagate tenant context into queued jobs`.

### F. Audit log

- [ ] F1. Test `tests/Feature/Core/AuditLogTest.php` — zápis doplní tenant, usera a IP automaticky. Červený.
- [ ] F2. `app/Core/Services/AuditLog.php` — `log(string $action, ?Model $subject, array $meta = [])`.
- [ ] F3. Napojit na změnu stavu tenanta (spec §6.0 AK: každá změna stavu se zapíše do audit logu). E-mail o změně stavu **odložen** do vlny s `MailService` — v plánu explicitně jako známá mezera.
- [ ] F4. Zeleně. Commit `feat: add audit log service`.

### G. CI

- [ ] G1. `.github/workflows/ci.yml` — PHP 8.3, services MySQL 8 + Redis 7, kroky: `composer install`, `npm ci && npm run build`, `./vendor/bin/pint --test`, `php artisan test`.
- [ ] G2. Samostatný job **`tenant-isolation`**, který pouští jen `--filter=Isolation` a je v branch protection povinný (spec §4.2 bod 2 — izolace je povinná součást CI).
- [ ] G3. Ověřit zelený běh na feature branch. Commit `ci: add github actions with tenant isolation gate`.

### H. Uzavření vlny

- [ ] H1. `./vendor/bin/pint` na dirty soubory.
- [ ] H2. `php artisan test` celé zeleně — výstup vložit do PR popisu (žádné tvrzení „prochází" bez výstupu).
- [ ] H3. `docs/as-is/2026-XX-XX-tenancy-jadro.md` + aktualizace `docs/as-is/STATUS.md` (řádek Multi-tenancy: není → hotovo).
- [ ] H4. Bump `VERSION` + `CHANGELOG.md` dle skillu `versioning`.
- [ ] H5. Návrh k merge — uživatel potvrzuje.

---

## Strategie testů

| Vrstva | Co |
|---|---|
| Unit | `TenantContext` (`runAs` + obnova v `finally`), enum přechody stavů, `SchemaConventionTest` |
| Feature | Resolution z hostu, status gating, izolace A/B, kontext v jobech, cache izolace, audit log |
| CI gate | `--filter=Isolation` jako samostatný povinný job |

Izolační testy stavíme jako **datový** test (dva tenanti, křížové dotazy), ne jako HTTP test per endpoint — endpointy zatím neexistují. Až přijdou moduly, test se rozšíří o HTTP vrstvu.

## Rizika a mitigace

| Riziko | Dopad | Mitigace |
|---|---|---|
| Zapomenutý `tenant_id` v budoucí tabulce = únik mezi tenanty | kritický | `SchemaConventionTest` (D4) selže při migraci bez sloupce |
| Dotaz bez kontextu tiše vrátí data všech tenantů | kritický | D3 — výjimka místo prázdného scope |
| `runAs` nechá po výjimce viset cizí kontext | vysoký | `finally` + test |
| Přechod sqlite → MySQL rozbije stávající Breeze testy | nízký | A6 běží před další prací |
| spatie tasky předpokládají DB-per-tenant | střední | E2 — `SwitchTenantDatabaseTask` explicitně vypnout |
| Wildcard subdomény lokálně | nízký | Herd/`dnsmasq` na `*.droidshop.test`; testy jedou přes `withServerVariables`, DNS nepotřebují |

## Známé mezery po vlně (do backlogu)

- E-mail při změně stavu tenanta (čeká na `MailService`)
- Stavový automat zatím nemá časové triggery (trial → expired-grace) — chce scheduler, vlna s billing
- `platform_admins` guard a impersonace
- Per-tenant export dat (spec §4.2 bod 4) — GDPR, nutné před produkcí
