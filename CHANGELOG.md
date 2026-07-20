# Changelog

Historie verzí projektu DroidShop.cz. Aktuální verze je vždy v souboru [`VERSION`](VERSION).

Formát: [Keep a Changelog](https://keepachangelog.com/), verzování [SemVer](https://semver.org/).
Pravidla: [`.claude/skills/versioning/SKILL.md`](.claude/skills/versioning/SKILL.md).

- **patch** (`+0.0.1`) — každý commit (až bude `pre-commit` hook)
- **minor** (`+0.1.0`) — start nového implementačního plánu
- **major** (`+1.0.0`) — jen na explicitní pokyn

> CHANGELOG vede milníky (minor/major). Detail patchů je v `git log`.

## [0.9.2] – 2026-07-20

**Fáze 1 / vlna 1.3 — etapa 1: MailService.** Jádrová služba pro odesílání e-mailu jménem tenanta — první konkrétní volající pro `emails_month` v `LimitsService`.

- Kontrakt `MailService` + implementace `QueuedMailService` — tenant se dořeší (explicitní argument vyhrává nad ambientním kontextem) a celý běh (kvóta, log, identita odesílatele) jede uvnitř `TenantContext::runAs()`
- `SendTenantMail` — fronta doručení; při chybě během opakování se zapisuje jen text chyby, stav `failed` nastaví jedině Laravelí `failed()` hook (na sync driveru `attempts()` vrací natvrdo 1, takže by podmínka na poslední pokus nikdy nesepnula)
- `TenantSender` — obálková adresa vždy platformní (SPF/DKIM), tenant dodává jen display name a reply-to; nové sloupce `tenants.mail_from_name` a `tenants.mail_reply_to`
- `MailKind` — povinný argument kontraktu, `Transactional` nebo `Bulk`. Vyčerpaný limit nikdy nezastaví potvrzení objednávky ani reset hesla; transakční pošta se počítá, ale neblokuje. Druh se ukládá do `mail_messages.kind`, aby log ukázal, proč zpráva odešla přes strop
- `MailLimitCounter` — počítadlo `emails_month` nad `queued` i `sent` v aktuálním kalendářním měsíci (klíčem `queued_at`), zaregistrované v `AppServiceProvider`
- Model `MailMessage` nad tabulkou `mail_messages` (tenant-scoped)
- **Mimo rozsah etapy:** šablony e-mailů (verifikace, reset hesla, potvrzení objednávky) — přijdou s moduly `customers` a `orders`; `EventBus` zůstává odloženo

## [0.9.0] – 2026-07-20

**Fáze 1 / vlna 1.2 — storefront katalogu.** E-shop nájemce je poprvé veřejně dostupný: homepage, kategorie, detail produktu a vyhledávání renderované serverem, se SEO výstupy podle závazného pravidla storefrontu.

### Nový modul `storefront`

- Layout e-shopu (skip link, navigace kořenových kategorií, hledání, patička), homepage, `/hledani`
- Blade komponenty `seo-meta`, `json-ld`, `breadcrumbs`, `product-card`, `product-grid`, `sort-form`
- Chybové stránky v šabloně e-shopu; bez tenanta se degraduje na prostý HTML
- `sitemap.xml` a `robots.txt` per tenant; e-shop, který neobchoduje, dostane `Disallow: /`

### Veřejný katalog

- `/kategorie/{slug}` — výpis celého podstromu, stránkování 24, řazení a filtr „skladem" přes query parametry (funguje bez JS)
- `/produkt/{slug}` — galerie, cena s DPH i bez, dostupnost, popis
- JSON-LD `Product`+`Offer`, `BreadcrumbList`, `ItemList`, `Organization`+`WebSite`; canonical, OG a Twitter meta, `rel=prev/next`
- `noindex` na výsledky hledání a na filtrované kombinace

### SEO a chybové stavy

- **Přejmenovaný slug konečně odpovídá 301.** `redirects` se zapisovaly od vlny 1.1, ale nic je neservírovalo — obsluha visí na handleru 404, takže úspěšná cesta nenese DB dotaz navíc
- Stažený (soft-deleted) produkt vrací **410** se stránkou „produkt už není v nabídce" a odkazem do kategorie
- 404 se renderuje v šabloně e-shopu

### Jádro

- Kontrakt `StorefrontHome` — kořenová routa zůstává v jádře a deleguje ji šabloně
- `ProductQuery` + rozšíření `ProductCatalog` o `latest()` a `paginate()`; `CatalogProduct` o obrázek, krátký popis a URL
- `RedirectResponder` — servírování redirectů včetně dohledání tenanta z hostu

### Modul `products`

- Normalizovaný sloupec `search_text` (lowercase, bez diakritiky) plněný při zápisu + command `products:reindex-search`
- Vyhledávání ho používá, takže „cerna bunda" najde „Černá bunda"

### Assety

- Samostatný storefront bundle (JS 250 B gzip, CSS 9,8 kB gzip), Tailwind vidí Blade v `Modules/`

## [0.8.0] – 2026-07-20

**Fáze 1 / vlna 1.1 — jádro katalogu.** Nájemce spravuje strom kategorií a produkty s cenami, DPH, skladem, obrázky a SEO poli ve vlastním adminu.

### Bezpečnost

- **Opravena díra v admin routách modulů.** Byly montované jen s `web` a modulovým gate, takže kdokoli bez přihlášení mohl číst a zapisovat cizí e-shop. Týkalo se i nasazeného modulu `Pages`. Nový middleware `EnsureTenantMember` ověřuje přihlášení a členství v e-shopu, na jehož hostu request dorazil.
- **Oprávnění z manifestů začala platit.** `TenantPermissions` odvozuje sadu práv e-shopu z manifestů modulů, které běží; `Gate::before` z ní odpovídá na `$user->can()`. Právo vypnutého modulu nedostane nikdo, ani vlastník.
- **Vlastní `HtmlSanitizer`** (whitelist tagů, atributů a URL schémat nad `DOMDocument`). Popisy produktů se čistí při zápisu.
- **Nákupní cena** se zahazuje z validovaných dat a neopouští server bez práva `products.costs`.
- **Obrázky se při nahrání otevírají**, ne jen kontrolují podle přípony — HTML soubor přejmenovaný na `.jpg` by se jinak servíroval z originu e-shopu.

### Jádro

- Číselník sazeb DPH (`tax_rates`, promile jako integer); převody `net`/`gross`/`vat` na `TaxRate`
- Tabulka a služba `redirects` — 301 po přejmenování, řetězce se kolabují při zápisu
- `AdminLayout` — shell adminu nájemce, navigace z manifestů modulů, sdílené Inertia props
- Kontrakt `ProductCatalog` + `CatalogProduct` v jádře, implementace v modulu
- Service providery modulů se načítají z disku (`Modules/*/Providers/ModuleProvider.php`)
- Sdílené UI komponenty přesunuty z `Components/Platform` do `Components/Ui`

### Modul `categories`

- Strom (adjacency list + materializovaná cesta), max 4 úrovně, bez cyklů
- Admin: výpis, inline editace, přesun, řazení tlačítky (ovladatelné klávesnicí), mazání s povinným cílem pro podkategorie

### Modul `products`

- Produkty, výrobci, obrázky, vazba na kategorie s hlavní kategorií
- Cena hrubá + sazba; net a DPH se dopočítávají
- Atomický `decrementStock` jedním podmíněným `UPDATE`
- Soft delete; smazané produkty nepočítají do limitu tarifu
- Admin: seznam s filtry a stránkováním, karta se záložkami Základní / Ceny / Obrázky / Sklad / SEO
- Validace EAN-8/13 včetně kontrolní číslice

### Mimo rozsah vlny

Varianty, CSV import/export, generování řezů obrázků, hromadné operace, storefront rendering.

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
