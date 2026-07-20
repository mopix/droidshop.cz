# Fáze 0 / vlna 0.6 — Superadmin: management UI — implementační plán

> **STAV: dokončeno 2026-07-20**, verze 0.7.0, větev `feat/superadmin-ui`.
> Bloky A–I hotové, 319 testů zelených. Skutečný stav a odchylky:
> [`docs/as-is/2026-07-20-superadmin-ui.md`](../../as-is/2026-07-20-superadmin-ui.md).

> **Pro agenta:** superpowers:executing-plans / subagent-driven-development. Kroky `- [x]`.

**Cíl:** Superadmin spravuje tenanty a moduly z prohlížeče — vidí seznam, otevře detail, změní stav i tarif, zapne/vypne moduly per tenant, sáhne na globální kill switch a přepne se do e-shopu tenanta — bez artisan příkazů a se stopou v auditu.

**Architektura:** Inertia/Vue SPA pod `/superadmin/*` na platformním hostu (dle `.claude/rules/storefront-rendering.md` oddíl C, vše `noindex`). Backend = tencí `Platform\*Controller` nad už existujícími službami (`Tenant::changeStatus`, `ModuleRegistry::activate/deactivate`, `LimitsService::usage`). Nová je jen jedna doménová služba — `ModuleKillSwitch` — protože zápisová cesta ke `modules.enabled_globally` dosud neexistuje. Vlastní minimální UI komponenty (žádná nová JS závislost).

**Tech stack:** Laravel 13, PHP 8.3, Inertia 3 + Vue 3.5, Tailwind 3.4, PHPUnit 12. Dle [`docs/PROJECT-PROFILE.md`](../../PROJECT-PROFILE.md).

**Spec:** §15.4, §15.5, §6.12 · Navazuje na [superadmin auth](../../as-is/2026-07-19-superadmin-auth.md) a [systém modulů](../../as-is/2026-07-19-system-modulu.md).

**Role/viditelnost:** Vše výhradně `SUPERADMIN` (guard `platform` + `platform.2fa` + `platform.host`). Žádná routa není veřejná ani dostupná guardem `web`. Vše `noindex, nofollow`.

---

## Rozsah

**Uvnitř:** výpis tenantů (filtr stavu/tarifu, hledání, stránkování), detail tenantu (stav, tarif, domény, limity a čerpání, uživatelé, moduly, posledních N záznamů auditu), změna stavu s povinným důvodem, změna tarifu, per-tenant aktivace/deaktivace modulu, seznam modulů + globální kill switch, spuštění impersonace z detailu.

**Mimo:** metriky a MRR (fakturace zatím neexistuje — čísla by byla teoretická), zakládání tenantů z UI, editace tarifů, správa platformních adminů, plnotextový prohlížeč auditu.

---

## Bezpečnostní jádro (čte se první)

1. **Audit musí sedět na platform guard.** `AuditLog::log()` bere `user_id` z výchozího guardu (`app/Core/Services/AuditLog.php:27`), takže superadmin akce by se zapsaly jako anonymní. Bez opravy je celá vlna auditně bezcenná — proto je to blok A, ne poznámka na konec.
2. **Kill switch má jedinou zápisovou cestu.** Přímý `Module::update()` nechá zastaralou cache (`ModuleRegistry` TTL 60 s, klíče `modules:registry` a `modules:enabled:{id}`). Služba `ModuleKillSwitch` je jediné místo, které smí sloupec měnit, a vždy volá `ModuleRegistry::flush()`.
3. **Změna stavu tenantu jde jen přes `Tenant::changeStatus()`** — sama zapisuje audit a nastavuje `suspended_at` / `deletion_requested_at`. Controller nesmí sahat na `status` přímo.
4. **Důvod je povinný** u suspendu a u `pending_deletion`. Bez důvodu Form Request neprojde.
5. **Route binding přes UUID** (`Tenant::getRouteKeyName()`), nikdy přes autoincrement ID — nechceme enumerovatelné URL.
6. **Čtení dat tenantů je záměrně mimo `TenantScope`.** Každý dotaz, který v superadmin controlleru vytáhne tenant-scoped model (`TenantModule`, uživatele), musí běžet přes `TenantContext::runAs()` — jinak buď spadne, nebo (horší) vrátí data z jiného kontextu.
7. **Změna tarifu nesmí tiše nechat běžet moduly nad rámec nového tarifu.** Po přepnutí `plan_id` dolů projít aktivní volitelné moduly tenantu a ty, které nový tarif nedovoluje, deaktivovat — auditovaně a s výpisem v UI potvrzení.
8. **Destruktivní a stavové akce mají potvrzovací dialog** (CLAUDE.md) a jsou POST/PATCH, nikdy GET.

---

## Kroky

### A. Audit na platform guardu

- [x] A1. Test `AuditLogTest`: akce provedená přihlášeným `platform` adminem zapíše jeho identitu; akce tenant usera se nezmění; anonymní akce zůstane `null`. Červený.
- [x] A2. Rozšířit `AuditLog::log()` — pokud `auth('platform')->check()`, zapiš do meta `platform_admin_id` (+ `platform_admin_email` pro čitelnost i po smazání účtu). `user_id` nechat na `users`, aby FK zůstal konzistentní.
- [x] A3. Zeleně. Commit `fix: record platform admin identity in audit log`.

### B. Kill switch služba

- [x] B1. Test `ModuleKillSwitchTest`: vypnutí modulu ho odstraní z `ModuleRegistry::available()` **okamžitě** (bez čekání na TTL); zapíše audit `module.globally_disabled` s důvodem; zapnutí obnoví; **core modul nelze vypnout**; tenant s aktivním, ale globálně vypnutým modulem ho nemá v `enabledFor()`. Červený.
- [x] B2. `app/Core/Modules/ModuleKillSwitch.php` — `disable(Module, string $reason)`, `enable(Module)`; audit + `ModuleRegistry::flush()`.
- [x] B3. Zeleně. Commit `feat: add module kill switch service`.

### C. UI základ (layout a komponenty)

- [x] C1. `resources/js/Layouts/PlatformLayout.vue` — hlavička s navigací (Tenanti, Moduly), jméno admina, odhlášení, `noindex` meta, slot pro flash zprávy. Odstranit inline CSS z `Platform/Dashboard.vue`.
- [x] C2. Komponenty v `resources/js/Components/Platform/`: `DataTable.vue` (sloupce ze slotů, prázdný stav, `<caption>` pro čtečky), `Pagination.vue` (nad Laravel paginátorem, `aria-label`), `StatusBadge.vue` (barvy z `TenantStatus`, nikdy jen barva — vždy i text), `ConfirmDialog.vue` (nad Breeze `Modal`, focus trap, `Esc`), `FilterBar.vue`.
- [x] C3. A11y kontrola komponent agentem `a11y-checker` (WCAG 2.2 AA: kontrast, focus visible, klávesnice, popisky).
- [x] C4. Commit `feat: add platform admin layout and base components`.

### D. Výpis tenantů

- [x] D1. Test `Platform/TenantIndexTest`: routa vyžaduje `auth:platform` + potvrzené 2FA; na hostu tenanta 404; vrací komponentu `Platform/Tenants/Index` se stránkovaným seznamem; filtr podle stavu a tarifu; hledání dle jména/domény/IČO; **žádné N+1** (eager load `plan`, `primaryDomain`). Červený.
- [x] D2. `app/Http/Controllers/Platform/TenantController.php@index` + Form Request pro filtry (whitelist hodnot, ne slepé `$request->all()`).
- [x] D3. `resources/js/Pages/Platform/Tenants/Index.vue` — tabulka (název, doména, stav, tarif, založeno, konec trialu), filtry drží stav v URL query.
- [x] D4. Zeleně. Commit `feat: add tenant listing to superadmin`.

### E. Detail tenantu

- [x] E1. Test `Platform/TenantShowTest`: detail dle UUID; obsahuje domény, uživatele s rolemi, aktivní moduly, čerpání limitů z `LimitsService::usage()`, posledních 20 záznamů auditu; neexistující UUID → 404; tenant-scoped dotazy běží přes `TenantContext::runAs()`. Červený.
- [x] E2. `TenantController@show` + `app/Core/Platform/TenantOverview.php` (sesbírá data, aby controller zůstal tenký).
- [x] E3. `Pages/Platform/Tenants/Show.vue` — karty: základ, limity (progress + varování nad 80 %, hodnota vždy i číslem), domény, uživatelé, moduly, audit.
- [x] E4. Zeleně. Commit `feat: add tenant detail to superadmin`.

### F. Změna stavu a tarifu

- [x] F1. Test `Platform/TenantStatusTest`: suspend s důvodem změní stav, zapíše audit i `suspended_at`; **bez důvodu 422**; suspendovaný tenant má storefront na 503 (`CheckTenantStatus`); obnovení vrátí `active`; přechod do `pending_deletion` vyžaduje důvod; nesmyslný přechod (`deleted` → `trial`) odmítnut. Červený.
- [x] F2. `TenantController@updateStatus` + `UpdateTenantStatusRequest` (enum + povinný důvod). Povolené přechody jako mapa, ne volný enum.
- [x] F3. Test `Platform/TenantPlanTest`: změna tarifu přepíše `plan_id` a zapíše audit; **downgrade deaktivuje moduly, které nový tarif nedovoluje** (audit `module.deactivated`), a controller vrátí jejich seznam do flash. Červený.
- [x] F4. `TenantController@updatePlan` + `app/Core/Platform/PlanSwitcher.php` (přepnutí + úklid modulů v transakci).
- [x] F5. UI: `ConfirmDialog` s textareou na důvod; u downgradu předem zobrazit, které moduly zhasnou (GET náhled dopadu).
- [x] F6. Zeleně. Commit `feat: allow superadmin to change tenant status and plan`.

### G. Moduly — per tenant a globálně

- [x] G1. Test `Platform/TenantModulesTest`: aktivace modulu tenantovi projde jen když ho tarif dovoluje (jinak 422 z `guardPlan`); deaktivace core modulu odmítnuta; deaktivace modulu, na kterém visí jiný aktivní (`guardDependents`), odmítnuta se srozumitelnou hláškou. Červený.
- [x] G2. `app/Http/Controllers/Platform/TenantModuleController.php` (store/destroy) nad `ModuleRegistry`, přes `TenantContext::runAs()`.
- [x] G3. Test `Platform/ModuleIndexTest`: výpis modulů (klíč, název, verze, core/volitelný, počet tenantů s aktivací, `enabled_globally`); kill switch přes UI vyžaduje důvod; core modul nemá ovládací prvek. Červený.
- [x] G4. `Platform/ModuleController.php` (index, updateGlobalState) nad `ModuleKillSwitch`.
- [x] G5. `Pages/Platform/Modules/Index.vue` + sekce modulů v detailu tenantu. Kill switch = červený ConfirmDialog s důvodem a explicitním varováním, kolika tenantů se dotkne.
- [x] G6. Zeleně. Commit `feat: add module management to superadmin`.

### H. Impersonace z detailu a dashboard

- [x] H1. Test: tlačítko „Přihlásit se jako" v detailu tenantu spustí existující `ImpersonationController@start`; suspendovaný tenant impersonaci nedovolí (nebo dovolí s výrazným varováním — rozhodnout u implementace a zapsat do as-is).
- [x] H2. `Pages/Platform/Dashboard.vue` — nahradit placeholder rozcestníkem (počty tenantů dle stavu, odkazy na sekce). Bez finančních metrik.
- [x] H3. Zeleně. Commit `feat: wire impersonation into tenant detail`.

### I. Uzavření vlny

- [x] I1. Celá sada testů zeleně na MySQL 8 + Redis (`php artisan test --compact`), `./vendor/bin/pint` na dirty files, `npm run build`.
- [x] I2. Agent `a11y-checker` na nové stránky; nálezy priority high opravit.
- [x] I3. As-is `docs/as-is/2026-07-20-superadmin-ui.md` + aktualizace `docs/as-is/STATUS.md`.
- [x] I4. Bump VERSION a CHANGELOG (skill `versioning`).
- [x] I5. Merge do `main` po potvrzení uživatele.

---

## Strategie testů

Feature testy (PHPUnit 12, `RefreshDatabase`) v `tests/Feature/Platform/`, vzor setupu z `PlatformAuthTest.php:17` — `withoutVite()`, `config()->set('tenancy.platform_domain', 'droidshop')`, absolutní URL. Inertia asserty přes `assertInertia()`. Unit testy pro `ModuleKillSwitch`, `PlanSwitcher`, `TenantOverview`.

**Brána izolace:** ke stávající CI kontrole přidat test, že žádná platform routa není dosažitelná guardem `web` ani na hostu tenanta.

---

## Rizika

| Riziko | Mitigace |
|--------|----------|
| Superadmin čte tenant-scoped modely mimo kontext → prázdno nebo cizí data | Vše přes `TenantContext::runAs()`; test na detail s dvěma tenanty |
| Zastaralá cache po kill switchi | Jediná zápisová cesta `ModuleKillSwitch`, vždy `flush()`; test ověřuje okamžitý dopad |
| Downgrade tarifu nechá běžet placený modul | `PlanSwitcher` v transakci + test |
| Vlastní komponenty nesplní WCAG 2.2 AA | `a11y-checker` v kroku C3 i I2; barva nikdy jediný nositel informace |
| N+1 na výpisu tenantů | Eager loading + test počtu dotazů |
| Rozlezení rozsahu do metrik a fakturace | Rozsah zafixován výše; MRR mimo vlnu |
