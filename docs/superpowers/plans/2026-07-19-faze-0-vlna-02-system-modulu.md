# Fáze 0 / vlna 0.2 — Systém modulů — implementační plán

> **Pro agenta:** Použij superpowers:executing-plans nebo subagent-driven-development. Kroky s `- [ ]`.

**Cíl:** Modul jde nasadit, zaregistrovat, per tenanta zapnout a vypnout — a když je vypnutý, jeho routy, navigace ani listenery pro toho tenanta neexistují.

**Architektura:** `nwidart/laravel-modules` dodává autoloading, scaffolding a migrace per modul. Nad tím stojí naše vrstva: manifest s validací, registr v DB, per-tenant aktivace, mountování rout podle aktivních modulů, kill switch a vazba na tarify. Komunikace mezi moduly jen přes kontrakty a eventy (spec §3.2).

**Tech stack:** Dle `docs/PROJECT-PROFILE.md` — Laravel 13, PHP 8.3, MySQL 8, Redis, PHPUnit.

**Spec:** [`docs/specs/2026-07-17-eshop-platforma-specifikace.md`](../../specs/2026-07-17-eshop-platforma-specifikace.md) — kap. 5, §15.5, §14

**Navazuje na:** vlna 0.1 ([as-is](../../as-is/2026-07-19-tenancy-jadro.md)) — `TenantContext`, `BelongsToTenant`, `AuditLog` už stojí.

---

## Rozsah

### Ve vlně 0.2

Registr modulů, manifest a jeho validace, tabulky `modules` / `tenant_modules` / `plan_modules`, lifecycle (aktivace, deaktivace, odinstalace), topologické řazení závislostí, mountování rout, kill switch, skládání admin navigace, příkaz `modules:sync`, referenční modul jako důkaz.

### Mimo vlnu 0.2

`SettingsService` s validací proti JSON schématu a `LimitsService` (vlna 0.3 — manifest na ně už bude připravený), UI sloty ve storefront šabloně (přijdou se šablonou), checkout pipeline a `PriceModifier` (s moduly `checkout` / `payments`), skutečné business moduly, superadmin UI nad registrem.

### Zásadní pravidlo, které musí být v kódu i v dokumentaci

`modules_statuses.json` od nwidart = **stav nasazení** (co je vůbec na serveru). Tabulka `tenant_modules` = **stav per tenant** (kdo to má zapnuté). Nikdy se nemíchají a nwidart `module:enable` se pro per-tenant účely nepoužívá. Bez tohoto pravidla vzniknou dva zdroje pravdy, které se rozejdou.

---

## Kroky

### A. Základ a balíček

- [ ] A1. `composer require nwidart/laravel-modules:^13.0`, publikovat config, nastavit cestu na `modules/` a PSR-4 namespace `Modules\`.
- [ ] A2. `composer.json` — přidat `Modules\` do autoloadu, ověřit `composer dump-autoload`.
- [ ] A3. Ověřit zeleně stávajících 80 testů. Commit `chore: add module package`.

### B. Datový model registru

- [ ] B1. Test `ModuleSchemaTest` — tabulky a sloupce. Červený.
- [ ] B2. Migrace `modules` (`key` PK, `version`, `core` BOOL, `level` ENUM(base,premium), `enabled_globally` BOOL, `manifest` JSON, timestamps).
- [ ] B3. Migrace `tenant_modules` (PK `(tenant_id, module_key)`, `enabled`, `settings` JSON, `activated_at`, `deactivated_at`) + `plan_modules` (PK `(plan_id, module_key)`).
- [ ] B4. Modely `Module`, `TenantModule` (+ vztahy na `Tenant`, `Plan`). `Module` **není** tenant-scoped — je platformní.
- [ ] B5. Doplnit `modules`, `plan_modules` do whitelistu `SchemaConventionTest::PLATFORM_TABLES` (jsou platformní, `tenant_modules` tam nepatří — má `tenant_id`).
- [ ] B6. Zeleně B1. Commit `feat: add module registry schema`.

### C. Manifest a jeho validace

- [ ] C1. Test `ManifestTest`: platný manifest se načte; chybějící `name` selže; neplatný semver v `requires` selže; neznámá `level` hodnota selže. Červený.
- [ ] C2. `app/Core/Modules/Manifest.php` — readonly value object dle spec §5.1 (`name`, `version`, `title`, `description`, `core`, `billable`, `requires`, `provides`, `listens`, `permissions`, `settings_schema`, `nav`).
- [ ] C3. `app/Core/Modules/ManifestValidator.php` — validace proti JSON schématu. Chyba manifestu **shodí `modules:sync`**, nikdy se nezapíše polovičatý záznam.
- [ ] C4. Zeleně. Commit `feat: add module manifest parsing and validation`.

### D. Registr a řazení závislostí

- [ ] D1. Test `ModuleRegistryTest`: `all()`, `enabledFor(tenant)`, `isEnabled()`; topologické řazení dle `requires`; **cyklická závislost hodí výjimku**; aktivace modulu s nesplněnou závislostí selže s čitelnou chybou. Červený.
- [ ] D2. `app/Core/Modules/ModuleRegistry.php` dle rozhraní ze spec §15.1. Výsledky cachovat (`modules:registry`, TTL 60 s kvůli kill switchi).
- [ ] D3. `app/Core/Modules/DependencyResolver.php` — topologické řazení, detekce cyklů, kontrola semver rozsahů.
- [ ] D4. Zeleně. Commit `feat: add module registry with dependency resolution`.

### E. Lifecycle

- [ ] E1. Test `ModuleLifecycleTest`: aktivace zapíše `tenant_modules` a zavolá `onActivate`; deaktivace **data nemaže**; odinstalace volá `onUninstall` a data maže; jádrový modul (`core: true`) nejde per tenant vypnout; každá změna je v audit logu. Červený.
- [ ] E2. Kontrakt `app/Core/Modules/Contracts/ModuleLifecycle.php` (`onActivate`, `onDeactivate`, `onUninstall`) — volitelný, modul ho nemusí implementovat.
- [ ] E3. Implementace v `ModuleRegistry` + napojení na `AuditLog` (`module.activated`, `module.deactivated`, `module.uninstalled`).
- [ ] E4. Odinstalace = destruktivní. Vyžaduje potvrzení na volající straně (CLAUDE.md pravidlo mazacích akcí) a zapisuje se do audit logu vždy.
- [ ] E5. Zeleně. Commit `feat: add per-tenant module lifecycle`.

### F. Routy, kill switch, navigace

- [ ] F1. Test `ModuleRoutingTest`: routa aktivního modulu odpoví; téhož modulu u tenanta, který ho nemá, vrátí 404; po `enabled_globally = false` zmizí všem do 60 s. Červený.
- [ ] F2. Middleware `module:{key}` — 404, pokud tenant modul nemá. **404, ne 403** — existence modulu u cizího tenanta není veřejná informace.
- [ ] F3. Mountování rout dle spec §15.5: `routes/admin.php` pod `/admin/m/{module}` (názvy `admin.{module}.*`), `routes/storefront.php`, `routes/api.php` pod `/api/m/{module}`.
- [ ] F4. Kill switch — `enabled_globally = false` odregistruje routy i listenery; UI ukáže „modul dočasně nedostupný".
- [ ] F5. `app/Core/Modules/NavigationBuilder.php` — skládá admin navigaci z `nav` sekcí aktivních modulů, řazení dle `order`. Test na řazení a na to, že vypnutý modul v navigaci není.
- [ ] F6. Zeleně. Commit `feat: mount module routes per tenant`.

### G. Příkazy a referenční modul

- [ ] G1. `php artisan modules:sync` — načte manifesty, zvaliduje, zapíše do `modules`. Idempotentní, hlásí přidané/změněné/odebrané.
- [ ] G2. **Referenční modul `Pages`** (statické stránky) — nejmenší modul, který prokáže celý řetěz: manifest, migrace s `tenant_id`, admin routa, storefront routa (Blade SSR!), `onActivate` seedující stránku „O nás", `nav` položka.
  Volba `Pages`, ne `Products`: chceme dokázat systém, ne rozjet katalog. `Products` má vlastní spec (§6.2) a zaslouží si vlastní vlnu.
- [ ] G3. Test `ReferenceModuleTest` — end-to-end: sync → aktivace u tenanta A → storefront stránka odpoví u A, 404 u B → deaktivace → 404 i u A, data zůstala.
- [ ] G4. Zeleně. Commit `feat: add modules:sync and reference Pages module`.

### H. Uzavření vlny

- [ ] H1. `./vendor/bin/pint --dirty`.
- [ ] H2. `php artisan test` celé zeleně — výstup do popisu PR.
- [ ] H3. CI: přidat `--filter='Module'` do povinného jobu? **Ne.** Izolační brána má zůstat úzká a čitelná; modulové testy běží v hlavní sadě.
- [ ] H4. `docs/as-is/2026-XX-XX-system-modulu.md` + aktualizace `STATUS.md`.
- [ ] H5. Bump `VERSION` na `0.3.0` + `CHANGELOG.md`.
- [ ] H6. Návrh k merge.

---

## Strategie testů

| Vrstva | Co |
|---|---|
| Unit | Manifest, `DependencyResolver` (cykly, semver), `NavigationBuilder` |
| Feature | Registr, lifecycle, mountování rout, kill switch, audit |
| End-to-end | Referenční modul `Pages` přes dva tenanty |

Modulové testy **musí** používat dva tenanty všude, kde jde o viditelnost. Modul zapnutý u A a vypnutý u B je přesně ta situace, kde se izolace poruší nejsnáz.

## Rizika a mitigace

| Riziko | Dopad | Mitigace |
|---|---|---|
| Dva zdroje pravdy (`modules_statuses.json` × `tenant_modules`) | vysoký | Pravidlo výše v kódu i docs; `modules:sync` nikdy nepíše per-tenant stav |
| Cyklické závislosti modulů | střední | `DependencyResolver` hodí výjimku, test na to |
| Routa modulu viditelná tenantovi bez modulu | **kritický** | Middleware `module:` + test se dvěma tenanty; 404 místo 403 |
| Kill switch se neprojeví kvůli cache | střední | TTL 60 s dle spec §15.5, test na invalidaci |
| nwidart přestane stíhat major Laravelu | nízký | Vrstva je naše; výměna balíčku by se dotkla jen autoloadingu (viz rozhodnutí 2026-07-19) |
| Odinstalace smaže data omylem | vysoký | Potvrzení, audit log, deaktivace jako výchozí a vratná operace |

## Otevřené otázky na uživatele

1. **Referenční modul** — `Pages`, jak navrhuji, nebo rovnou `Products`?
2. **Odinstalace v MVP** — implementovat celou, nebo zatím jen deaktivaci a `onUninstall` nechat na později? Spec ji chce, ale je to jediná destruktivní operace v celé vlně.
