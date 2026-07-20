# Changelog

Historie verzí projektu DroidShop.cz. Aktuální verze je vždy v souboru [`VERSION`](VERSION).

Formát: [Keep a Changelog](https://keepachangelog.com/), verzování [SemVer](https://semver.org/).
Pravidla: [`.claude/skills/versioning/SKILL.md`](.claude/skills/versioning/SKILL.md).

- **patch** (`+0.0.1`) — každý commit (až bude `pre-commit` hook)
- **minor** (`+0.1.0`) — start nového implementačního plánu
- **major** (`+1.0.0`) — jen na explicitní pokyn

> CHANGELOG vede milníky (minor/major). Detail patchů je v `git log`.

## [0.7.0] – 2026-07-20

**Fáze 0 / vlna 0.6 — superadmin management UI.** Platformu lze spravovat z prohlížeče: tenanti, stavy, tarify, moduly, kill switch.

- Výpis tenantů s filtry (stav, tarif, hledání dle jména/domény/IČO) a stránkováním; detail adresovaný přes UUID
- Detail tenanta: stav, tarif, domény, uživatelé, moduly, čerpání limitů, posledních 20 záznamů auditu
- Změna stavu podle mapy povolených přechodů v `TenantStatus`; důvod povinný u pozastavení a čekání na smazání; `deleted` nelze nastavit ručně
- Změna tarifu přes `PlanSwitcher` — **downgrade vypne moduly, které nový tarif nekryje**, i jejich závislé; UI ukáže dopad předem
- Aktivace a deaktivace modulů per tenant přes `ModuleRegistry` (plán, závislosti a core status dál hlídá registry)
- **`ModuleKillSwitch`** — jediná zápisová cesta k `modules.enabled_globally`; zahodí cache registru, vynutí důvod, zapíše audit. Přebíjí i core moduly (nouzová brzda)
- **Oprava:** `AuditLog` bral `user_id` z naposledy použitého guardu, takže superadmin akce shodila cizí klíč nebo ukázala na cizí osobu. Nyní guard `web` + identita superadmina v `meta`
- Impersonace vrací `Inertia::location()` — spouští se z Inertia stránky
- Vlastní UI komponenty (`PlatformLayout`, `DataTable`, `Pagination`, `StatusBadge`, `ConfirmDialog`, `FilterBar`) — žádná nová JS závislost
- Nová brána izolace: `PlatformRouteIsolationTest` trvá na `platform.host`, `auth:platform` a `platform.2fa` u každé `platform.*` routy
- **Odloženo:** metriky a MRR (čeká na fakturaci), zakládání a mazání tenantů z UI, editace tarifů, prohlížeč auditu
- **As-is:** [`docs/as-is/2026-07-20-superadmin-ui.md`](docs/as-is/2026-07-20-superadmin-ui.md)

## [0.6.0] – 2026-07-19

**Fáze 0 / vlna 0.5 — superadmin auth jádro.** Správce platformy s odděleným účtem, povinným 2FA a auditovanou impersonací.

- Oddělená tabulka `platform_admins` + guard `platform` — sdílí nic s `users`
- Přihlášení jen na platformním hostu (na doméně tenanta 404), rate limit 5/min + lockout
- Povinné 2FA (TOTP + jednorázové recovery kódy, šifrované/hashované), dvě brány přes middleware
- **Impersonace** přes podepsaný handoff mezi hosty (různé session cookies); 30 min expirace, `impersonated_by` v každém audit zápisu, banner v UI
- `platform:create-admin` — interaktivní zřízení superadmina (žádné údaje v seederu)
- Balíček `pragmarx/google2fa`
- **Odloženo:** management UI (výpis tenantů, metriky), HIBP kontrola hesla, IP allowlist
- **As-is:** [`docs/as-is/2026-07-19-superadmin-auth.md`](docs/as-is/2026-07-19-superadmin-auth.md)

## [0.5.0] – 2026-07-19

**Fáze 0 / vlna 0.4 — FileStorage.** Modul umí uložit a servírovat soubor přes službu jádra, aniž zná disk. Soubory zůstávají na naší VPS (lokální disk, ne S3).

- `FileStorage` — dva disky (`tenant_public` web-served, `tenant_private` jen přes podpis); každá cesta vynuceně pod `tenants/{id}/`
- `PathGuard` — odmítá traversal ve všech podobách (samostatná pojistka)
- Privátní soubory přes `URL::temporarySignedRoute` na doméně tenanta; podpis váže host i tenant param
- `StorageLimitCounter` — první konkrétní počítadlo pro `LimitsService`; upload nad limit tarifu se odmítne
- **Rozhodnutí 2026-07-19:** úložiště lokální, ne S3 (změna „S3 od začátku"); abstrakce drží swap na S3 jako změnu configu
- **As-is:** [`docs/as-is/2026-07-19-filestorage.md`](docs/as-is/2026-07-19-filestorage.md)

## [0.4.0] – 2026-07-19

**Fáze 0 / vlna 0.3 — kernel služby.** Pět služeb jádra a vynucení tarifu při aktivaci modulu.

- `Money` — integer haléře, dělení bez ztráty haléře, zákaz míchání měn
- `SettingsService` — per-tenant nastavení, validace proti schématu z manifestu, cache
- `LimitsService` — allow/warn/block, počítadla přes kontrakt `LimitCounter`, override z `plan_modules`
- `SequenceService` — číselné řady bez děr, dokázáno souběhovým testem 4 procesů; atomický `UPDATE ... LAST_INSERT_ID`
- `FeatureFlags` — global / whitelist / deterministické procento
- **Aktivace modulu respektuje tarif** — zavřená mezera z vlny 0.2; tenant bez tarifu si zapne jen core moduly
- **Odloženo:** `FileStorage`, `MailService`, `EventBus` — čekají na výběr provideru a prvního skutečného volajícího
- **As-is:** [`docs/as-is/2026-07-19-kernel-sluzby.md`](docs/as-is/2026-07-19-kernel-sluzby.md)

## [0.3.0] – 2026-07-19

**Fáze 0 / vlna 0.2 — systém modulů.** Modul jde nasadit, zaregistrovat, per tenanta zapnout a vypnout; když ho tenant nemá, jeho routy pro něj neexistují.

- Manifest (`module.json`) s validací — neplatný manifest shodí `modules:sync` celý, nikdy nezapíše polovičatý záznam
- `DependencyResolver` — topologické, deterministické řazení; cykly a nesplněné semver rozsahy hlásí chybu
- `ModuleRegistry` — aktivace dotáhne závislosti, deaktivace nic nemaže, kill switch přebíjí i core moduly
- Routy z disku, povolení z DB; middleware `module:{key}` vrací **404, ne 403**
- `NavigationBuilder` skládá admin menu z manifestů
- Referenční modul **Pages** — důkaz celého řetězu včetně Blade SSR a serverem renderovaných SEO tagů
- Balíček `composer/semver` přidán
- **Odchylka:** odinstalace modulu (`onUninstall`) odložena — rozhodnutí 2026-07-19
- **As-is:** [`docs/as-is/2026-07-19-system-modulu.md`](docs/as-is/2026-07-19-system-modulu.md)

## [0.2.0] – 2026-07-19

**Fáze 0 / vlna 0.1 — tenancy jádro.** Rozpoznání tenanta z Host hlavičky, datová izolace vynucená na modelech, propagace kontextu do jobů, audit log, CI s izolací jako samostatnou branou.

- Datový model jádra dle spec §15.3 (`tenants`, `domains`, `tenant_users`, `plans`, `audit_log`, `jobs_log`)
- Middleware pipeline `ResolveHost` → `CheckTenantStatus` → `SetTenantContext` (spec §15.2)
- `BelongsToTenant` + `TenantScope`; dotaz bez kontextu hodí `MissingTenantContext` místo tichého vrácení dat všech tenantů
- `SchemaConventionTest` shodí build, když doménová tabulka přijde bez `tenant_id`
- Balíčky: `spatie/laravel-multitenancy ^4.1` přidán, `stripe/stripe-php` odstraněn
- Lokální konfigurace přes `.env.local` (načítá `bootstrap/app.php`)
- **As-is:** [`docs/as-is/2026-07-19-tenancy-jadro.md`](docs/as-is/2026-07-19-tenancy-jadro.md)
- **Plán:** [`docs/superpowers/plans/2026-07-19-faze-0-vlna-01-tenancy-jadro.md`](docs/superpowers/plans/2026-07-19-faze-0-vlna-01-tenancy-jadro.md)

## [0.1.0] – 2026-07-19

**Bootstrap.** Laravel skeleton + napojení na GitHub + AI/docs struktura (`claude-laravel-vue` + WooShop vzor) + produktová specifikace v `docs/specs/`.

- **As-is:** [`docs/as-is/2026-07-19-bootstrap.md`](docs/as-is/2026-07-19-bootstrap.md)
