# Uzavření technických mezer — implementační plán

> **Pro agenta:** Použij superpowers:executing-plans nebo subagent-driven-development. Kroky s `- [ ]`.

**Cíl:** Zavřít tři mezery, které v `docs/as-is/STATUS.md` stály roky otevřené: chybějící pojistku 4 z §4.2 (per-tenant export), hledání, které nepoužívá index, a chybějící odinstalaci modulu.

**Architektura:** Export je nová kernel služba `app/Core/Export/` řízená jobem a artisan příkazem; produkuje ZIP (JSON per tabulka + soubory z obou disků `FileStorage`). Odinstalace modulu na něj staví — nájemci nesmíme smazat data, aniž si je může odnést — a rozšiřuje `ModuleLifecycle` o třetí hook. Hledání přechází z `LIKE '%term%'` na MySQL `FULLTEXT` index bez změny rozhraní `ProductCatalog`.

**Tech stack:** Dle `docs/PROJECT-PROFILE.md`

**Spec:** §4.2 pojistka 4, §4.4 (fronty a `jobs_log`), §5.2 (lifecycle modulu), §16.1

---

## Co se do plánu nedostalo a proč

**Roční interval a upgrade/downgrade tarifu** — položka je v `STATUS.md` vedená jako odložená, ale v kódu **hotová je**: `plan_prices` nese `interval`, `Plan::priceFor()` ho čte, `SubscriptionController::checkout()` ho bere z requestu, `Subscription.vue:72` má přepínač měsíčně/ročně a `StripeWebhookHandler.php:158` volá `TenantPlanSwitcher` na `customer.subscription.updated`. Testy `TenantPlanSwitcherTest` a `PlanPriceTest` to pokrývají. Jediné, co chybí, je konfigurace Stripe Billing Portalu, která už v checklistu „Před spuštěním" v `CLAUDE.md` je. **Akce: opravit řádku ve `STATUS.md`, žádný kód.**

**EventBus** — spec §4.5 ho chce jako páteř komunikace mezi moduly. Dnes moduly komunikují přes kontrakty (`ProductCatalog`, `ShippingOptions`, `DocumentLedger`), což spec připouští jako výslovnou výjimku, a Laravel events se používají tam, kde dávají smysl (`TenantStatusChanged`). Chybí tedy **registr a kontrakt**, ne mechanismus — a stavět registr bez druhého volajícího znamená hádat jeho tvar. Zůstává odložený se stejným zdůvodněním jako dosud; etapa D níže z něj bere jen to, co odinstalace modulu reálně potřebuje.

---

## Etapa A — per-tenant export (spec §4.2 pojistka 4)

Spec ji označuje jako **nutnou před produkcí**: GDPR (žádost nájemce o kopii dat), migrace pryč (nesmíme držet nájemce jako rukojmí), obnova a podklad pro odinstalaci v etapě C.

Dnes neexistuje nic — ani příkaz, ani job. Tenant-scoped je **36 modelů** (`grep -rl BelongsToTenant`) plus soubory na `tenant_public` a `tenant_private` discích.

### Otevřené otázky k rozhodnutí před kódem

1. **Kdo smí export spustit.** Návrh: superadmin pro libovolného tenanta (provozní potřeba) **a** `TENANT_ADMIN` pro vlastního tenanta (GDPR nárok, bez čekání na podporu). `TENANT_STAFF` ne — export obsahuje osobní údaje všech zákazníků.
2. **Formát.** Návrh: ZIP, uvnitř `data/<tabulka>.json` (jeden soubor na tabulku, pole objektů) + `files/public/…` a `files/private/…` v původní struktuře + `manifest.json` (verze schématu, datum, seznam tabulek a počty řádků). JSON, ne CSV: exportujeme vnořené snímky objednávek a `payload` bloků, kde by CSV lhalo.
3. **Doručení.** Návrh: soubor na `tenant_private` disk + podepsaný odkaz s expirací (vzor `FileStorage::SIGNED_ROUTE`), ne příloha mailu. Export tenanta je řádově stovky MB.

### Kroky

- [ ] A1. Kontrakt `app/Core/Export/Contracts/TenantExporter.php` + hodnotový objekt `ExportManifest`. Test: rozhraní existuje, manifest serializuje.
- [ ] A2. `app/Core/Export/TenantDataExporter.php` — zjištění tenant-scoped tabulek. **Nesmí být ruční seznam**: nový modul by z něj vypadl a export by tiše neúplný. Odvodit z registru modelů používajících `BelongsToTenant` (reflexe nad `app/Models` a `Modules/*/Models`), s testem, který selže, jakmile přibude model, který export nepokrývá.
- [ ] A3. Test tenant izolace exportu: export tenanta A neobsahuje ani jeden řádek tenanta B. Toto je nejdůležitější test celé etapy — export běží nutně napříč tabulkami, tedy přesně tam, kde globální scope nejsnáz obejdeš.
- [ ] A4. Export souborů z obou disků přes `FileStorage`; `PathGuard` musí platit i tady (žádné `..` ven z tenantova prefixu).
- [ ] A5. `app/Jobs/ExportTenantJob.php` implementující `NotTenantAware` a nastavující kontext přes `TenantContext::runAs()` — job běží *nad* tenantem, nespoléhá na to, že ho fronta nastaví.
- [ ] A6. Artisan `tenant:export {tenant}` (superadmin cesta, funguje bez UI a je tím, co se použije při incidentu).
- [ ] A7. Superadmin obrazovka: tlačítko + stav + odkaz ke stažení.
- [ ] A8. Obrazovka nájemce v `/admin/nastaveni` — „Stáhnout všechna data".
- [ ] A9. Audit log u obou cest (kdo, kdy, jakého tenanta).
- [ ] A10. Rate limit: jeden běžící export na tenanta. Bez něj deset kliknutí = deset paralelních dumpů celé DB.

### Riziko

Export je jediné místo, které čte všechny tabulky naráz. Chyba ve scope tady neuniká po jednom řádku, ale celou databází — proto A3 před vším ostatním a proto job nikdy nesmí běžet bez explicitního tenant kontextu.

---

## Etapa B — hledání na FULLTEXT indexu

`Modules/Products/Services/EloquentProductCatalog.php:143` hledá `where('search_text', 'like', '%'.$folded.'%')`. Vedoucí `%` znemožňuje použít index — každé hledání je full table scan nad `products`. Řazení podle relevance na řádku 154 dělá druhý `LIKE` v `orderByRaw`.

Argument „fulltext nejede na SQLite v testech" z rozhodnutí 2026-07-20 **už neplatí** — `phpunit.xml` jede na MySQL.

- [ ] B1. Migrace: `FULLTEXT` index nad `products.search_text`. Pozor na složený scope — index je globální, filtr `tenant_id` zůstává v `WHERE`, takže optimalizátor musí umět obojí; ověřit `EXPLAIN` na seedovaných datech, ne odhadem.
- [ ] B2. `applySearch()` na `MATCH … AGAINST` v boolean módu. Zachovat současné chování na krátkých termech: MySQL má `ft_min_word_len`/`innodb_ft_min_token_size` (default 3), takže dvouznakový dotaz by nově nevrátil nic, kde dnes vrací. Fallback na `LIKE` pod tuto hranici.
- [ ] B3. `applySort()` — relevance z `MATCH` skóre místo druhého `LIKE`.
- [ ] B4. Testy: shodné výsledky pro sadu dotazů před a po (diakritika, prefix, dvouznakový term, term s pomlčkou, prázdný), plus test tenant izolace hledání.
- [ ] B5. **Rozhodnout, zda i `Modules/Packeta/Services/EloquentPickupPointCatalog.php:30`** — stejný `LIKE '%…%'`, ale nad tabulkou s indexem a řádově tisíci řádky na katalog. Návrh: nechat být a zapsat důvod do `docs/decisions/06-doprava.md`; přepisovat kvůli konzistenci je práce bez užitku.

### Riziko

Hledání je storefront, tedy SEO a konverze. Změna, která tiše zúží výsledky (viz B2), je horší než pomalý dotaz. Sada v B4 je proto srovnávací, ne jen „vrátí něco".

---

## Etapa C — odinstalace modulu (spec §5.2)

`app/Core/Modules/Contracts/ModuleLifecycle.php:10` říká, že uninstall chybí **záměrně**: vlna 0.2 dodala jen aktivaci a deaktivaci a mazání dat mělo přijít „s prvním modulem, který má data, která stojí za smazání". Modulů je dnes 15 a data mají všechny.

Deaktivace dnes data ponechává, což je správné a zůstane výchozí. Odinstalace je něco jiného: nájemce chce data pryč.

- [ ] C1. Rozšířit `ModuleLifecycle` o `onUninstall(Tenant $tenant): void` jako **volitelný** hook (samostatné rozhraní `ModuleUninstall`, ne povinná metoda — jinak se musí dopsat do všech 15 modulů naráz).
- [ ] C2. `ModuleRegistry::uninstall()` — vynutit pořadí: modul musí být nejdřív deaktivovaný, core modul nikdy, závislosti kontrolovat přes `DependencyResolver` (odinstalace `products` pod běžícím `orders` = rozbitý e-shop).
- [ ] C3. **Export před smazáním je povinný, ne nabídnutý.** Uninstall spustí etapu A pro dotčené tabulky a teprve po úspěchu maže. Nájemce, který si omylem odinstaluje katalog, musí mít cestu zpět.
- [ ] C4. Potvrzovací dialog podle pravidla „Mazací akce" v `CLAUDE.md` — s opsáním názvu modulu, ne pouhým „Opravdu?".
- [ ] C5. Audit log + záznam, co přesně bylo smazáno (počty řádků per tabulka).
- [ ] C6. Testy: odinstalace nesmí sáhnout na data jiného tenanta; core modul odmítnut; modul se závislým odmítnut; data skutečně pryč; export existuje a je čitelný.

### Riziko

Jediná nevratná operace v celé vlně. Postup ověřovat na demu (`DemoShopSeeder`), ne na produkčních datech.

---

## Etapa D — úklid dokumentace

- [ ] D1. `STATUS.md`: opravit řádku o ročním intervalu a upgrade/downgrade (viz sekce „Co se do plánu nedostalo").
- [ ] D2. `STATUS.md`: pojistka 4 z „chybí" na hotovo, `2026-07-19-tenancy-jadro.md:32` taky.
- [ ] D3. Známé omezení o `LIKE '%term%'` vyřadit ze seznamu.
- [ ] D4. Rozhodnutí do `docs/decisions/`: 01 (export a jeho scope), 02 (fulltext, revokace rozhodnutí 2026-07-20 — **přidat novou položku, nepřepisovat starou**), 01 nebo 08 (uninstall a povinný export před ním), 06 (pokud B5 dopadne „nechat být").
- [ ] D5. As-is `docs/as-is/2026-09-XX-technicke-mezery.md` podle `.claude/rules/as-is-on-milestone.md`.

---

## Pořadí a proč

A → C je nutné (C staví na A). B je nezávislé a dá se dělat kdykoli. Doporučené pořadí A, B, C, D: etapa A je jediná, kterou spec označuje jako blokující produkci, a etapa C je jediná nevratná — ať jde poslední, až bude export odzkoušený.

## Testy

Ke každé etapě testy tenant izolace, ne jen funkční. Plnou sadu **nespouštět jedním příkazem** — po adresářích, ve foregroundu.

## Verze

Start plánu = minor bump na `0.48.0` podle `.claude/skills/versioning/SKILL.md`.
