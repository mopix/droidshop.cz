# As-is: systém modulů (Fáze 0 / vlna 0.2)

Datum: **2026-07-19** · Verze: **0.3.0** · Větev: `feat/module-system`

Plán: [`docs/superpowers/plans/2026-07-19-faze-0-vlna-02-system-modulu.md`](../superpowers/plans/2026-07-19-faze-0-vlna-02-system-modulu.md)
Spec: kap. 5, §15.5, §14 · Navazuje na [tenancy jádro](2026-07-19-tenancy-jadro.md)

## Co je hotové

Modul jde nasadit, zaregistrovat, per tenanta zapnout a vypnout. Když ho tenant nemá, jeho routy pro něj **neexistují** — 404, ne 403.

### Mapa kódu

| Oblast | Soubory |
|---|---|
| Manifest | `app/Core/Modules/Manifest.php`, `ManifestValidator.php`, `Exceptions/InvalidManifest.php` |
| Závislosti | `app/Core/Modules/DependencyResolver.php`, `Exceptions/UnresolvableDependencies.php` |
| Registr | `app/Core/Modules/ModuleRegistry.php`, `Contracts/ModuleLifecycle.php` |
| Routy | `app/Core/Modules/ModuleRouteRegistrar.php`, `app/Http/Middleware/EnsureModuleEnabled.php` |
| Navigace | `app/Core/Modules/NavigationBuilder.php` |
| Příkaz | `app/Console/Commands/ModulesSync.php` |
| Registrace | `app/Providers/ModuleServiceProvider.php` |
| Modely | `app/Models/Module.php`, `TenantModule.php` |
| Referenční modul | `Modules/Pages/**` |

### Dvě hranice, které kód drží

**1. Disk vs. databáze.** Co je nasazené, se čte z disku (manifesty, routy, migrace, pohledy). Kdo to má zapnuté, se čte z databáze (`tenant_modules`). Registrace rout nesmí sahat do registru — běží při bootu, kdy ještě není znám tenant a na čerstvé databázi ani neexistuje tabulka. Kill switch přesto funguje bez redeploye, protože middleware se registru ptá při každém requestu.

**2. Platformní vs. tenantský stav.** `modules` a `plan_modules` jsou platformní tabulky. `tenant_modules` je tenant-scoped jako každá jiná doménová tabulka. `modules:sync` do `tenant_modules` nikdy nesahá.

## Testy

**137 passed (260 assertions)** — z toho 57 nových v této vlně.

| Sada | Co ověřuje |
|---|---|
| `ManifestTest` | validace manifestu, čitelné chybové hlášky |
| `DependencyResolverTest` | topologické řazení, determinismus, cykly, semver |
| `ModuleRegistryTest` | aktivace, dotažení závislostí, deaktivace, core moduly, kill switch, audit |
| `ModuleRoutingTest` | E2E přes dva tenanty: storefront, admin, kill switch, seedování, SEO tagy |
| `NavigationBuilderTest` | řazení, skrytí vypnutých, oddělení admin/storefront |
| `ModuleSchemaTest` | schéma registru, tenant-scoping `tenant_modules` |

## Odchylky od specifikace

| # | Odchylka | Důvod |
|---|---|---|
| 1 | **Odinstalace (`onUninstall`) neimplementována** | Rozhodnutí uživatele 2026-07-19. Deaktivace je vratná a nic nemaže; to MVP stačí. Mazání dat se napíše, až budou existovat data, proti kterým se dá otestovat. Spec §5.2 ji chce — doplnit později. |
| 2 | Adresář `Modules/` velkým písmenem, spec §5.1 píše `modules/` | Sedí s PSR-4 namespace `Modules\` a s konvencí nwidart. Kosmetické. |
| 3 | Storefront routa modulu Pages je `/stranka/{slug}`, ne `/{page-slug}` | Catch-all v kořeni by spolkl všechny ostatní storefront routy. Řazení rout napříč moduly se vyřeší s modulem šablony; **do té doby to zůstává provizorium**. |
| 4 | `audit_log.subject_id` je nyní `string(64)`, ne `unsignedBigInteger` | `Module` má řetězcový primární klíč, audit modulu by jinak neprošel. Opraveno novou migrací. |
| 5 | `plan_modules` má navíc sloupec `limits` | Spec §5.4 zmiňuje limity per tarif; sloupec je připravený, `LimitsService` přijde ve vlně 0.3. |

## Technický dluh a známá omezení

1. **Odinstalace chybí** (viz odchylka 1).
2. **`SettingsService` a `LimitsService` nejsou.** Manifest umí deklarovat `settings_schema` a `permissions`, ale nic je zatím nevyhodnocuje. `tenant_modules.settings` je připravený sloupec.
3. **Admin modulu Pages vrací JSON, ne UI.** Inertia admin přijde s vlastní vlnou; teď jde jen o důkaz, že se routa mountuje a je správně hlídaná.
4. **UI sloty a hooky (spec §5.3) nejsou** — přijdou se šablonou storefrontu.
5. **`TenantModule` obchází Eloquent** kvůli složenému primárnímu klíči (`setKeysForSaveQuery`). Funguje, ale je to místo, kde se dá snadno šlápnout vedle — každý nový model se složeným klíčem to musí udělat taky.
6. **Routy modulů se registrují pro všechny moduly na disku**, i pro ty, které nikdo nemá zapnuté. Při stovkách modulů by to chtělo cache; při jednotkách je to levnější než složitost.

## Pre-deploy checklist (nesplněno)

- [ ] Odinstalace modulu + potvrzovací dialog
- [ ] `SettingsService` s validací proti schématu z manifestu
- [ ] `LimitsService` a vazba aktivace na tarif (`plan_modules` se zatím nekontroluje!)
- [ ] Superadmin UI nad registrem (kill switch se teď přepíná jen v DB)
