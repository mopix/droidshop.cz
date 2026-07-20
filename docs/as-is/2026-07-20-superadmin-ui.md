# As-is: superadmin management UI (Fáze 0 / vlna 0.6)

Datum: **2026-07-20** · Verze: **0.7.0** · Větev: `feat/superadmin-ui`

Plán: [`docs/superpowers/plans/2026-07-20-faze-0-vlna-06-superadmin-ui.md`](../superpowers/plans/2026-07-20-faze-0-vlna-06-superadmin-ui.md)
Spec: §6.12, §15.5, §5.4 · Navazuje na [superadmin auth](2026-07-19-superadmin-auth.md) a [systém modulů](2026-07-19-system-modulu.md)

## Co je hotové

Superadmin spravuje platformu z prohlížeče: vidí seznam tenantů s filtry, otevře detail, mění stav i tarif, zapíná a vypíná moduly per tenant, ovládá globální kill switch a přepne se do e-shopu tenanta. Bez artisan příkazů, se stopou v auditu.

### Mapa kódu

| Oblast | Soubory |
|---|---|
| Výpis, detail, stav, tarif | `app/Http/Controllers/Platform/TenantController.php`, `Requests/Platform/{TenantFilterRequest, UpdateTenantStatusRequest, UpdateTenantPlanRequest}.php` |
| Sběr dat detailu | `app/Core/Platform/TenantOverview.php` |
| Změna tarifu + úklid modulů | `app/Core/Platform/PlanSwitcher.php` |
| Moduly per tenant | `app/Http/Controllers/Platform/TenantModuleController.php` |
| Moduly globálně + kill switch | `app/Http/Controllers/Platform/ModuleController.php`, `app/Core/Modules/ModuleKillSwitch.php` |
| Povolené přechody stavů | `app/Core/Enums/TenantStatus.php` (`allowedTransitions`, `canTransitionTo`, `requiresReason`) |
| Audit na platform guardu | `app/Core/Services/AuditLog.php` |
| Sdílené props | `app/Http/Middleware/HandleInertiaRequests.php` (`admin`, `flash.success`, `flash.error`) |
| UI základ | `resources/js/Layouts/PlatformLayout.vue`, `resources/js/Components/Platform/{DataTable, Pagination, StatusBadge, ConfirmDialog, FilterBar}.vue` |
| Obrazovky | `resources/js/Pages/Platform/{Dashboard, Tenants/Index, Tenants/Show, Modules/Index}.vue` |
| Routy | `routes/platform.php` |

### Co kód drží

1. **Audit sedí na správné identitě.** `auth()->id()` se dřív vyhodnocoval proti naposledy použitému guardu — superadmin akce buď shodila cizí klíč do `users`, nebo ukázala na nesouvisejícího člověka. `user_id` je teď svázané s guardem `web`, superadmin jde do `meta.platform_admin_id` a `meta.platform_admin_email` (e-mail proto, aby záznam zůstal čitelný i po smazání účtu).
2. **Kill switch má jedinou zápisovou cestu.** `ModuleKillSwitch` je jediné místo, které mění `modules.enabled_globally`: zahodí cache registru (jinak by se změna projevila až za 60 s), vynutí důvod a zapíše akci jako platformní, ne tenantskou.
3. **Změna stavu jen po povolených hranách.** Mapa přechodů je v enumu, ne v controlleru. `deleted` nelze nastavit ručně — patří mazacímu jobu, aby stav a data neříkaly každý něco jiného.
4. **Důvod je povinný** u `suspended` a `pending_deletion`, ověřeno na serveru (prázdné bílé znaky neprojdou).
5. **Downgrade tarifu neponechá běžet placený modul.** `PlanSwitcher` v transakci vypne, co nový tarif nekryje — a s tím i všechno, co na tom viselo, v pořadí od závislých k závislostem. UI to předem ukáže přes náhled dopadu.
6. **Tenant-scoped data se čtou přes `TenantContext::runAs()`.** Detail dvou tenantů vedle sebe je pokrytý testem.
7. **Nová brána izolace.** `PlatformRouteIsolationTest` prochází routy podle názvu a trvá na `platform.host`, `auth:platform` a `platform.2fa` — každá budoucí superadmin obrazovka je tím krytá automaticky.

## Testy

**319 passed (812 assertions)** — z toho 61 nových. MySQL 8 + Redis.

| Sada | Co ověřuje |
|---|---|
| `Feature/Platform/TenantIndexTest` | auth a 2FA brány, 404 na hostu tenanta, filtry, hledání, stránkování, pevný počet dotazů |
| `Feature/Platform/TenantShowTest` | adresace přes UUID, domény, uživatelé, moduly jen tohoto tenanta, čerpání limitů, audit jen tohoto tenanta |
| `Feature/Platform/TenantStatusTest` | suspend s důvodem, 503 na storefrontu, obnovení, nemožné přechody, `deleted` ručně ne |
| `Feature/Platform/TenantPlanTest` | audit změny, downgrade vypne moduly i jejich závislé, core přežije, náhled dopadu nic nemění |
| `Feature/Platform/ModuleManagementTest` | plán jako brána aktivace, core nelze vypnout, závislosti, počty tenantů, kill switch |
| `Feature/Platform/PlatformRouteIsolationTest` | middleware na všech `platform.*` routách |
| `Feature/Modules/ModuleKillSwitchTest` | okamžitý dopad, audit, povinný důvod, dosah i na core |
| `Feature/Core/AuditLogTest` (rozšířeno) | identita platform admina, meta volajícího se nepřepisuje |

## Odchylky od plánu

| # | Odchylka | Důvod |
|---|---|---|
| 1 | **Kill switch smí vypnout i core modul** (plán chtěl zakázat) | `ModuleRegistry::enabledFor()` je od vlny 0.2 postavený tak, že kill switch přebíjí core status — je to nouzová brzda platformy a kritická díra v core modulu je přesně ten případ. UI to tvrdě varuje a ukazuje počet dotčených e-shopů. |
| 2 | **Impersonace vrací `Inertia::location()`**, ne `redirect()->away()` | Tlačítko je nově na Inertia stránce; axios by redirect následoval sám do cross-origin požadavku, který doména tenanta nezodpoví. Pro běžný POST se chová dál jako redirect (pokryto testem). |
| 3 | `PlanSwitcher` vypíná i **tranzitivně závislé** moduly, nejen ty mimo tarif | Jinak `ModuleRegistry::deactivate()` narazí na vlastní guard závislých. Alternativa (nechat závislý běžet) by znamenala živý, rozbitý modul. |
| 4 | `admin` a `flash` se sdílí v `HandleInertiaRequests`, ne per obrazovka | Layout je potřebuje na každé stránce. Sdílí se jen `name` a `email` — záznam obsahuje i 2FA secret. |
| 5 | Dashboard je rozcestník bez metrik | Rozsah vlny: fakturace neexistuje, MRR by byl teoretický údaj z ceníku, ne tržby. |

## Technický dluh a známá omezení

1. **Žádné metriky.** MRR, konverze trialu, růst — čeká na fakturaci (spec §7). Dashboard zatím jen rozcestník.
2. **Tenanty nelze z UI zakládat ani mazat.** Zakládání přijde s onboardingem, mazání s jobem, který teprve vznikne (`pending_deletion` na nic nenavazuje).
3. **Tarify se z UI needitují.** Ceník se mění seederem nebo v DB.
4. **Audit je jen výřez.** Detail ukazuje posledních 20 záznamů, bez stránkování a filtrů. Plnotextový prohlížeč auditu chybí.
5. **`LimitsService` má stále jen počítadlo `storage_mb`** — ostatní limity ukazují čerpání 0, dokud nevzniknou příslušné moduly (dluh z vlny 0.4, ne z této).
6. **Stavové změny neposílají e-mail.** Nájemce se o pozastavení z platformy nedozví — čeká na `MailService`.
7. **Superadmin nemá seznam ani správu ostatních superadminů.** Zřizuje se dál příkazem `platform:create-admin`.
8. **Staré auth obrazovky mají URL natvrdo** (`Pages/Platform/Auth/*.vue` posílají na `/superadmin/login` apod. místo `route()`). Zděděno z vlny 0.5, přepsat při první změně těch stránek.
9. **UI nebylo ověřeno v prohlížeči** — pokrytí je testy a statickou a11y kontrolou. Ruční průchod (klávesnice, čtečka, zoom 200 %) zbývá.

## Pre-deploy checklist

- [ ] Ruční průchod superadmin UI v prohlížeči (klávesnice, zoom 200 %, čtečka)
- [ ] Ověřit, že platformní host má v produkci vlastní hostname a wildcard DNS ho nepřebíjí
- [ ] Rozhodnout retenci `audit_log` (roste bez omezení)
