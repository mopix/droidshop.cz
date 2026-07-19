# As-is: kernel služby (Fáze 0 / vlna 0.3)

Datum: **2026-07-19** · Verze: **0.4.0** · Větev: `feat/kernel-services`

Plán: [`docs/superpowers/plans/2026-07-19-faze-0-vlna-03-kernel-sluzby.md`](../superpowers/plans/2026-07-19-faze-0-vlna-03-kernel-sluzby.md)
Spec: §15.1, §5.4 · Navazuje na [systém modulů](2026-07-19-system-modulu.md)

## Co je hotové

Pět služeb jádra, o které se moduly mají opírat místo vlastních řešení. A hlavní důvod vlny: **aktivace modulu teď respektuje tarif** — mezera vytčená v as-is vlny 0.2 je zavřená.

### Mapa kódu

| Služba | Soubory |
|---|---|
| `Money` | `app/Core/Money/Money.php`, `MoneyCast.php`, `Exceptions/CurrencyMismatch.php` |
| `SettingsService` | `app/Core/Settings/SettingsService.php`, `Exceptions/InvalidSetting.php` |
| `LimitsService` | `app/Core/Limits/LimitsService.php`, `LimitResult.php`, `LimitOutcome.php`, `Contracts/LimitCounter.php` |
| `SequenceService` | `app/Core/Sequences/SequenceService.php` |
| `FeatureFlags` | `app/Core/Features/FeatureFlags.php`, `config/features.php` |
| Vynucení tarifu | `app/Core/Modules/ModuleRegistry.php` (`guardPlan`), `Exceptions/PlanDoesNotIncludeModule.php` |

### Plnění spec §15.1 po službách

| Služba | Stav |
|---|---|
| `Money` | hotovo — integer haléře, dělení bez ztráty, zákaz míchání měn |
| `SettingsService` | hotovo — per tenant, validace proti schématu z manifestu, cache |
| `LimitsService` | hotovo — allow/warn/block, počítadla přes kontrakt `LimitCounter`, override z `plan_modules` |
| `SequenceService` | hotovo — bez děr, dokázáno souběhovým testem |
| `FeatureFlags` | hotovo — global / whitelist / deterministické procento |
| `TenantContext`, `AuditLog`, `ModuleRegistry` | z předchozích vln |
| `FileStorage`, `MailService`, `EventBus`+outbox, `JobMonitor` | **odloženo** — viz níže |

## Testy

**189 passed (372 assertions)** — z toho 52 nových v této vlně.

| Sada | Co ověřuje |
|---|---|
| `MoneyTest` | aritmetika, dělení se zbytkem, měny, formátování |
| `SettingsServiceTest` | per tenant, validace, cache invalidace |
| `LimitsServiceTest` | prahy, override, tenant bez tarifu = blok |
| `ModuleActivationRespectsPlanTest` | modul mimo tarif se neaktivuje |
| `SequenceServiceTest` + `SequenceConcurrencyTest` | bez děr, souběh 4 procesů |
| `FeatureFlagsTest` | determinismus procent, whitelist |

## Odchylky od plánu

| # | Odchylka | Důvod |
|---|---|---|
| 1 | `FileStorage`, `MailService`, `EventBus`, `JobMonitor` **neimplementovány** | Rozhodnutí uživatele 2026-07-19: psát abstrakci až s prvním skutečným volajícím. `FileStorage` a `MailService` navíc čekají na výběr S3 provideru a odesílatele. |
| 2 | `SequenceService` používá atomický `UPDATE ... LAST_INSERT_ID`, ne `SELECT ... FOR UPDATE` z plánu | Původní návrh deadlockoval při prvním čísle řady (zámek na neexistujícím řádku nedrží nic). Odhalil souběhový test. Nová verze zamyká jen jeden řádek. |
| 3 | Tenant bez tarifu = **blok** aktivace i limitů | Rozhodnutí uživatele 2026-07-19. Bezpečnější default než „neomezeno". |

## Technický dluh a známá omezení

1. **`LimitsService` nemá zaregistrovaná žádná počítadla.** Kontrakt `LimitCounter` stojí, ale konkrétní počítadla (`products` = řádky v tabulce produktů) přijdou s příslušnými moduly. Do té doby `usage()` vrací 0.
2. **Vynucení tarifu se netýká modulů aktivovaných dřív.** Žádná produkce neexistuje, takže migrace stavu není potřeba — ale až vznikne, kontrola je jen při `activate()`, ne zpětně.
3. **`SettingsService` validuje proti Laravel validačním pravidlům z manifestu**, ne proti plnému JSON Schema. Pro MVP dostačuje; plné JSON Schema je nadstavba, až bude potřeba.
4. **`Money::format()` závisí na rozšíření `intl`.** Je na dev i v CI (setup-php), ale je to závislost.
5. **Odloženo z minulé vlny stále platí:** odinstalace modulů, UI sloty, superadmin.

## Pre-deploy checklist (nesplněno)

- [ ] `FileStorage` na S3 (blokuje výběr provideru)
- [ ] `MailService` (blokuje výběr odesílatele + podoba šablon)
- [ ] `EventBus` + outbox `pending_events` (s modulem objednávek)
- [ ] Konkrétní `LimitCounter` počítadla (s moduly)
- [ ] Superadmin UI pro tarify a limity
