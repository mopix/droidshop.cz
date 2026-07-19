# Fáze 0 / vlna 0.3 — Kernel služby — implementační plán

> **STAV: dokončeno 2026-07-19**, verze 0.4.0, větev `feat/kernel-services`.
> Bloky A–F hotové, 189 testů zelených. Skutečný stav a odchylky:
> [`docs/as-is/2026-07-19-kernel-sluzby.md`](../../as-is/2026-07-19-kernel-sluzby.md).
>
> **Změna oproti plánu (blok D):** `SequenceService` používá atomický
> `UPDATE ... LAST_INSERT_ID`, ne `SELECT ... FOR UPDATE` — původní návrh
> deadlockoval při prvním čísle řady, odhalil souběhový test.

> **Pro agenta:** Použij superpowers:executing-plans nebo subagent-driven-development. Kroky s `- [ ]`.

**Cíl:** Moduly mají k dispozici služby jádra, o které se mají opírat místo vlastních řešení — a aktivace modulu konečně respektuje tarif.

**Architektura:** Služby dle spec §15.1, každá s jasným kontraktem a bez znalosti konkrétního modulu. Modul se ptá jádra; jádro nikdy nevolá modul napřímo.

**Tech stack:** Dle `docs/PROJECT-PROFILE.md` — Laravel 13, PHP 8.3, MySQL 8, Redis, PHPUnit.

**Spec:** kap. 15.1, §5.4, §16.6 · **Navazuje na:** [vlna 0.2](../../as-is/2026-07-19-system-modulu.md)

---

## Rozsah

### Ve vlně 0.3

`Money`, `SettingsService`, `LimitsService` **včetně vynucení tarifu při aktivaci modulu**, `SequenceService`, `FeatureFlags`.

Tyhle služby spojuje jedna vlastnost: **nepotřebují žádnou vnější infrastrukturu.** Jdou napsat a otestovat celé proti MySQL a Redisu, které už běží.

### Mimo vlnu 0.3 — a proč

| Služba | Blokováno čím |
|---|---|
| `FileStorage` | Není vybraný S3 provider. CLAUDE.md říká „S3-kompatibilní od začátku", ale ne který. Rozhodnutí uživatele. |
| `MailService` | Není vybraný poskytovatel odesílání ani podoba šablon. Bez toho by vznikla abstrakce nad ničím. |
| `EventBus` + outbox `pending_events` | Dává smysl až s prvním modulem, který na eventy skutečně reaguje (objednávky). Teď by to byla infrastruktura bez uživatele. |
| `JobMonitor` | Tabulka `jobs_log` stojí od vlny 0.1; služba dává smysl s prvním dlouhým jobem (import produktů). |

**Zásada:** nepsat abstrakci dřív, než existuje aspoň jeden skutečný volající. Jinak vznikne rozhraní odhadnuté podle spec, ne podle potřeby.

---

## Kroky

### A. `Money`

- [ ] A1. Test `MoneyTest`: konstrukce z haléřů i z korun; sčítání a odčítání; násobení množstvím; dělení se zbytkem (rozúčtování bez ztráty haléře); porovnání; formátování v CZK; **součet dvou různých měn hodí výjimku**; zákaz float na vstupu. Červený.
- [ ] A2. `app/Core/Money/Money.php` — readonly value object, `int $amount` v haléřích + `string $currency`.
- [ ] A3. `app/Core/Money/MoneyCast.php` — Eloquent cast, aby modely mohly mít `'price' => MoneyCast::class`.
- [ ] A4. Zeleně. Commit `feat: add Money value object`.

**Pozor na dělení.** Rozdělit 100 haléřů na 3 části musí dát 34+33+33, ne 3×33 se ztraceným haléřem. Test na to je povinný — tohle je klasický zdroj rozdílů v účetnictví.

### B. `SettingsService`

- [ ] B1. Test `SettingsServiceTest`: `get` vrací default, když nic není; `set` uloží a `get` přečte; hodnoty jsou **per tenant** (A nevidí nastavení B); validace proti schématu z manifestu odmítne špatný typ; cache se invaliduje při zápisu. Červený.
- [ ] B2. Migrace `settings` dle spec §15.3 (`tenant_id`, `module`, `key`, `value` JSON, PK složený).
- [ ] B3. `app/Core/Settings/SettingsService.php` — `get(module, key, default)`, `set(module, key, value)`, `all(module)`, `schemaFor(module)`. Cache `settings:{tenant}:{module}`.
- [ ] B4. Validace proti `settings_schema` z manifestu modulu. Schéma chybí → hodnota se uloží bez validace, ale **zaloguje se varování** (tichý průchod by schéma udělal dekorací).
- [ ] B5. Zeleně. Commit `feat: add per-tenant settings service`.

### C. `LimitsService` a vynucení tarifu

Tohle je hlavní důvod vlny — mezera vytčená v as-is vlny 0.2.

- [ ] C1. Test `LimitsServiceTest`: `check` vrací `allow` pod 80 %, `warn` mezi 80 a 100 %, `block` na hranici; `usage` počítá skutečný stav; limit chybí v tarifu → neomezeno; tenant bez tarifu → **blok, ne neomezeno**. Červený.
- [ ] C2. `app/Core/Limits/LimitResult.php` — enum `allow|warn|block` + zpráva pro UI a zbývající kapacita.
- [ ] C3. `app/Core/Limits/LimitsService.php` — `check(string $limit, int $delta = 1)`, `usage(string $limit)`. Zdroj limitů: `plans.limits`, přepis z `plan_modules.limits`.
- [ ] C4. Registr počítadel: modul deklaruje, čím se limit měří (např. `products` = počet řádků v `products`). Kontrakt `LimitCounter`, aby jádro nemuselo znát tabulky modulů.
- [ ] C5. **Test `ModuleActivationRespectsPlanTest`:** aktivace modulu, který tarif tenanta neobsahuje, selže. Premium modul na base tarifu selže. Core modul projde vždy.
- [ ] C6. Napojení na `ModuleRegistry::activate()` — kontrola `plan_modules` před zápisem.
- [ ] C7. Zeleně. Commit `feat: add limits service and enforce plan on module activation`.

**Rozhodnutí k potvrzení:** tenant **bez** přiřazeného tarifu (`plan_id = null`, což umožňuje onboarding) — navrhuji **blokovat** aktivaci volitelných modulů. Bezpečnější než default „neomezeno".

### D. `SequenceService`

- [ ] D1. Test `SequenceServiceTest`: po sobě jdoucí čísla bez děr; řady jsou **per tenant** (A a B mají vlastní číslování od 1); prefix se aplikuje; **souběžný přístup nevydá stejné číslo dvakrát**. Červený.
- [ ] D2. Migrace `sequences` dle §15.3 (`tenant_id`, `series`, `prefix`, `next_number`, PK složený).
- [ ] D3. `app/Core/Sequences/SequenceService.php` — `next(string $series)`, zamčení řádku přes `SELECT … FOR UPDATE` uvnitř transakce.
- [ ] D4. Test souběhu — dva procesy, žádná duplicita. Bez něj je zámek nedokázaný.
- [ ] D5. Zeleně. Commit `feat: add gap-free sequence service`.

**Proč bez děr:** čísla faktur a objednávek musí být souvislá kvůli účetnictví. `AUTO_INCREMENT` díry dělá (rollback transakce číslo spotřebuje), proto vlastní řada.

### E. `FeatureFlags`

- [ ] E1. Test: flag vypnutý globálně; zapnutý pro whitelist tenantů; zapnutý pro procento tenantů **deterministicky** (stejný tenant dostane stejnou odpověď napříč requesty).
- [ ] E2. `app/Core/Features/FeatureFlags.php` — `enabled(string $flag, ?Tenant $tenant = null)`. Konfigurace v `config/features.php`.
- [ ] E3. Zeleně. Commit `feat: add feature flags`.

**Determinismus procent** přes hash `tenant_id + flag`, ne přes náhodu. Flag, který se u téhož tenanta mezi requesty přepíná, je nepoužitelný a mizerně se ladí.

### F. Uzavření vlny

- [ ] F1. `./vendor/bin/pint --test` na **celém projektu**, ne jen dirty — CI kontroluje vše.
- [ ] F2. `php artisan test` zeleně, výstup do popisu PR.
- [ ] F3. `docs/as-is/2026-XX-XX-kernel-sluzby.md` + `STATUS.md` (vyškrtnout mezeru „aktivace nekontroluje tarif").
- [ ] F4. `VERSION` → `0.4.0` + `CHANGELOG.md`.
- [ ] F5. Merge.

---

## Strategie testů

| Vrstva | Co |
|---|---|
| Unit | `Money` (aritmetika, dělení se zbytkem, měny), `LimitResult` |
| Feature | `SettingsService`, `LimitsService`, `SequenceService`, `FeatureFlags` — všude proti **dvěma tenantům** |
| Souběh | `SequenceService` — dva souběžné požadavky |

## Rizika a mitigace

| Riziko | Dopad | Mitigace |
|---|---|---|
| Ztracený haléř při dělení | vysoký (účetnictví) | Test na rozúčtování zbytku, A1 |
| Díry v číselné řadě | vysoký (účetnictví) | `FOR UPDATE`, test souběhu D4 |
| Limity počítané dotazem na každý request | střední | Cache s invalidací při zápisu; `usage()` nikdy v hot path bez cache |
| Tarif se nekontroluje u modulů aktivovaných dřív | střední | Migrace stavu není potřeba — zatím žádná produkce; zapsat do as-is |
| Abstrakce bez volajícího | střední | `FileStorage`, `MailService`, `EventBus` vědomě odloženy |

## Otevřené otázky na uživatele

1. **Tenant bez tarifu** — blokovat aktivaci volitelných modulů (navrhuji), nebo povolit?
2. **S3 provider** — až bude vybraný, `FileStorage` je první věc do vlny 0.4. Kandidáti: Hetzner Object Storage, Wasabi, Backblaze B2, AWS S3.
