# As-is: tenancy jádro (Fáze 0 / vlna 0.1)

Datum: **2026-07-19** · Verze: **0.2.0** · Větev: `feat/tenancy-core`

Plán: [`docs/superpowers/plans/2026-07-19-faze-0-vlna-01-tenancy-jadro.md`](../superpowers/plans/2026-07-19-faze-0-vlna-01-tenancy-jadro.md)
Spec: [`docs/specs/2026-07-17-eshop-platforma-specifikace.md`](../specs/2026-07-17-eshop-platforma-specifikace.md) §4.2, §4.3, §6.0, §15.1–15.4

## Co je hotové

Request na `nazev.droidshop` rozpozná tenanta, ověří jeho stav, nastaví kontext — a od té chvíle je dotaz na cizí data buď prázdný, nebo výjimka. Nikdy tichý únik.

### Mapa kódu

| Oblast | Soubory |
|---|---|
| Kontext tenanta | `app/Core/Tenancy/TenantContext.php` |
| Rozpoznání domény | `app/Core/Tenancy/DomainTenantFinder.php` |
| Izolace | `app/Core/Tenancy/BelongsToTenant.php`, `TenantScope.php`, `Exceptions/MissingTenantContext.php` |
| Middleware | `app/Http/Middleware/{ResolveHost,CheckTenantStatus,SetTenantContext}.php` |
| Modely | `app/Models/{Tenant,Domain,Plan,AuditLogEntry}.php` |
| Enumy | `app/Core/Enums/*.php` |
| Audit | `app/Core/Services/AuditLog.php` |
| Konfigurace | `config/tenancy.php`, `config/multitenancy.php` |
| Migrace | `database/migrations/2026_07_19_1647*` |
| CI | `.github/workflows/ci.yml` |

### Plnění spec po sekcích

| Sekce | Stav | Poznámka |
|---|---|---|
| §4.2 varianta B + pojistky 1–3 | hotovo | scope, testy izolace v CI, `tenant_id` + composite indexy |
| §4.2 pojistka 4 (per-tenant export) | **chybí** | GDPR, nutné před produkcí |
| §4.3 rozpoznání z Host | hotovo | vlastní domény = fáze 2 |
| §15.2 middleware pipeline | hotovo | admin větev zatím neexistuje |
| §15.3 datový model | hotovo | bez `tenant_modules`, `plan_modules`, `sequences`, `settings`, `webhook_*`, `pending_events`, `redirects` (vlna 0.2+) |
| §15.4 auth a role | částečně | `tenant_users` s `permissions JSON` stojí; `platform_admins`, 2FA a impersonace chybí |
| §15.1 `TenantContext`, `AuditLog` | hotovo | `LimitsService`, `SequenceService`, `SettingsService`, `FileStorage`, `MailService`, `EventBus` outbox chybí |
| §15.6 cache prefix per tenant | hotovo | page cache storefrontu zatím není |

## Testy

**80 passed (159 assertions)**, MySQL 8 + Redis.

| Sada | Co ověřuje |
|---|---|
| `CoreSchemaTest` | tabulky a sloupce dle §15.3, ceny jako integer |
| `SchemaConventionTest` | doménová tabulka bez `tenant_id` shodí build; `tenant_id` vede composite index |
| `TenantIsolationTest` | čtení, `find`, update, delete, agregace a mass assignment nepřekročí hranici tenanta |
| `TenantResolutionTest` | známý/neznámý/platformní host, rezervované subdomény, velikost písmen, stavy tenanta |
| `TenantContextTest` | `runAs` obnoví kontext i po výjimce |
| `TenantJobContextTest` | job běží pod tenantem, který ho odeslal; cache izolovaná |
| `AuditLogTest` | doplnění tenanta/uživatele/IP, změna stavu tenanta |

CI má izolaci jako **samostatný job** `tenant-isolation`, ne jako jeden tik mezi ostatními.

## Odchylky od specifikace

| # | Odchylka | Důvod |
|---|---|---|
| 1 | `SESSION_DOMAIN` zůstává `null` místo `.droidshop` | Host-only cookie drží session tenanta na jeho doméně (§15.4). Sdílená cookie napříč subdoménami by šla proti tomu. |
| 2 | `plan_id` na `tenants` je nullable | Spec ho má jako FK bez zmínky o nullable, ale onboarding (§6.0) zakládá tenanta dřív, než se vybere tarif. |
| 3 | Stav `past_due` nechává storefront běžet | Spec §6.0 to explicitně neříká. Rozhodnutí: spor je mezi námi a nájemcem, vypnutí e-shopu trestá jeho zákazníky. |
| 4 | `CheckTenantStatus` gatuje jen storefront | Admin read-only pro `suspended` (§6.0) přijde s admin routami ve vlně 0.2. |
| 5 | Lokální doména je jednoúrovňová `droidshop` | Uživatelské rozhodnutí 2026-07-19. Důsledek níže. |

## Technický dluh a známá omezení

1. **`curl` na subdoménách vyžaduje `-k`.** OpenSSL nepovoluje wildcard `*.droidshop` nad jedinou úrovní. Prohlížeč projde, `curl` ne. Dopadá na kontrolní seznam ve `storefront-rendering.md` a na budoucí Playwright E2E. Trvalá oprava = přejmenovat lokální doménu na `droidshop.test`.
2. **Tenant-aware fronty tiše zahazují joby bez tenanta.** Každý platformní job (billing, purge, reporty) **musí** implementovat `Spatie\Multitenancy\Jobs\NotTenantAware`, jinak nikdy neproběhne a nikde se to neobjeví. Chování je zapinované testem `test_tenant_aware_job_dispatched_without_a_tenant_is_discarded`.
3. **E-mail při změně stavu tenanta chybí** (§6.0 AK). Čeká na `MailService`.
4. **Stavový automat nemá časové triggery.** `trial → expired-grace → suspended` potřebuje scheduler; přijde s billingem.
5. **Per-tenant export dat chybí** (§4.2 bod 4). GDPR, nutné před produkcí.
6. **`platform_admins` guard a impersonace** nejsou (§15.4).
7. `Schema::getTables()` na MySQL vrací všechna schémata, která uživatel vidí. Každý budoucí schéma test musí filtrovat podle `DB::connection()->getDatabaseName()`.

## Pre-deploy checklist (nesplněno, pro pořádek)

- [ ] Wildcard DNS + TLS pro produkční doménu
- [ ] Per-tenant export dat (GDPR)
- [ ] `platform_admins` + povinné 2FA superadmina
- [ ] Zálohy a nanečisto obnova
- [ ] Sentry
