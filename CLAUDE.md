# CLAUDE.md

Živý dokument. Průběžně aktualizuj, udržuj krátký a přehledný.

## Projekt
- Název: **DroidShop.cz**
- Firma: Miroslav Opletal — multi-tenant SaaS e-shopová platforma (typ Shoptet / Eshop-rychle)
- Klienti: **nájemci** (provozovatelé e-shopů) + jejich **koncoví zákazníci** (s námi nemají smluvní vztah)
- Popis: Registrovaný uživatel si za měsíční poplatek pronajme e-shop, naplní produkty a provozuje pod vlastní značkou. Platforma dodává software a infrastrukturu; za obsah, ceny, VOP vůči zákazníkům a daně odpovídá nájemce.
- Cíl MVP: do **10 minut** od registrace funkční e-shop na subdoméně, produkty, první objednávka.
- Brand tón: moderní, technický, spolehlivý (doplnit vizuál později)
- Jazyky UI: Čeština (primární); vícejazyčnost storefrontu = post-MVP
- Zdroj pravdy (produkt): [`docs/specs/2026-07-17-eshop-platforma-specifikace.md`](docs/specs/2026-07-17-eshop-platforma-specifikace.md)
- Repozitář: https://github.com/mopix/droidshop.cz
- Šablona AI workflow: https://github.com/mopix/claude-laravel-vue (+ vzor struktury z WooShop)

## Uživatel
- Skill level: pokročilý — používej technický žargon, navrhuj alternativy

## Stack
- Framework: Laravel 13 + Vue 3 + Inertia.js (admin) + TypeScript (postupně)
- Storefront (cíl): Blade SSR + Alpine/Vue ostrůvky (SEO) — viz specifikace §4.1
- UI: Tailwind CSS; shadcn/ui prvky dle potřeby
- Databáze: MySQL 8 / MariaDB (sdílená DB + `tenant_id`) + Redis (cache, fronty, session)
- Multi-tenancy: `stancl/tenancy` nebo `spatie/laravel-multitenancy` (rozhodnutí při implementaci jádra)
- Moduly: `nwidart/laravel-modules` + vlastní vrstva (manifest, per-tenant aktivace)
- Auth: Laravel Breeze (aktuálně v skeletonu); Fortify možná později
- Platby nájemců (platforma): karta / opakované předplatné (Stripe nebo ekvivalent — rozhodnout)
- Platby na e-shopech tenantů (MVP): dobírka, převod (+ QR), 1 brána (Comgate nebo GoPay)
- Storage: **lokální disk pro MVP** (rozhodnutí 2026-07-19, viz sekce Rozhodnutí); `FileStorage` služba drží abstrakci, přechod na S3 = změna configu
- Monitoring (cíl): Sentry + stavová stránka; Telescope jen dev
- Hosting: vlastní VPS

**Profil stacku (stručně):** [`docs/PROJECT-PROFILE.md`](docs/PROJECT-PROFILE.md)

## Role
| Role | Popis |
|------|--------|
| `SUPERADMIN` | Správa platformy, tenantů, tarifů, modulů, fakturace nájemcům |
| `TENANT_ADMIN` | Provozovatel e-shopu — produkty, objednávky, nastavení |
| `TENANT_STAFF` | Personál (post-MVP; datový model s rolemi od začátku) |
| `CUSTOMER` | Koncový zákazník tenanta (guest nákup + volitelný účet) |

**Při implementaci nové funkce se VŽDY zeptej:** která role ji smí vidět/upravovat, nebo zda je veřejná (storefront).

## Pravidla

### Prostředí
- NIKDY needituj `.env` — používej pouze `.env.local` (nebo doplň `.env.example`)
- Komunikace v chatu: česky
- Kód (proměnné, komentáře, commity): anglicky
- Dev server: `php artisan serve` + `npm run dev` (nebo `composer run dev`)
- Build: `npm run build`
- Instalace PHP: `composer require <balíček>`
- Instalace JS: `npm install <balíček>`

### Git a commity
- Trunk-based: hlavní větev `main`; práce na `dev` nebo feature branch
- Nikdy nepushuj přímo na `main` / `production`
- Před commitem a pushem se zeptej uživatele na potvrzení
- Commit zprávy: anglicky, stručné (`feat:`, `fix:`, `docs:`)

### Produkční mód
Pokud `APP_ENV=production`:
- Žádné destruktivní DB operace bez explicitního souhlasu
- Migrace jen s potvrzením
- Vždy upozorni, že běží produkce

### Knihovny a verze
- Nejnovější stabilní verze; ověř na Packagist/npm před instalací
- Nepoužívej deprecated balíčky
- Závislosti (`composer.json` / `package.json`) neměň bez souhlasu

### Testy
- Ke každé nové funkčnosti piš testy (PHPUnit teď; Pest možný později)
- Tenant izolace: CI musí ověřit, že tenant A nevidí data tenanta B
- E2E (Playwright) — zavést od prvních UI flow; konfig v `e2e/` až vznikne
- Před commitem ověř relevantní testy

### Modulární architektura (závazné)
- Každá funkční oblast = **modul**. Jádro: tenancy, users/roles, module system, sdílené služby, routing.
- Test: *„Šel by modul vypnout, aniž by spadl zbytek?"* Komunikace přes kontrakty/eventy.
- Detail: specifikace §3.2 a kap. 5.

### Storefront = Blade SSR (závazné, SEO)
Veřejné stránky e-shopu **musí** být renderované serverem. Nikdy ne SPA.
- **Blade SSR:** homepage, výpis kategorie, detail produktu, vyhledávání, statické stránky, blog, sitemap/feedy, chybové stránky
- **Blade SSR + progressive enhancement:** košík a pokladna (bez JS musí projít; ceny počítá server)
- **Vue/Inertia SPA:** pouze admin nájemce, superadmin, onboarding, fakturace — vše `noindex`
- Vue/Alpine na storefrontu jen jako **ostrůvky** nad hotovým HTML (varianty, galerie, mini-košík, našeptávač, widget Zásilkovny)

Detail a checklist: [`.claude/rules/storefront-rendering.md`](.claude/rules/storefront-rendering.md)

### Multi-tenancy
- Identita tenanta z Host hlavičky (`nazev.droidshop.cz` — finální doména dle deploye)
- Globální scope na modelech (`BelongsToTenant`); `tenant_id` ve všech doménových tabulkách
- Žádné „nahé" DB dotazy mimo Eloquent bez review

### Přístupnost
Standard: **WCAG 2.2 AA** (EAA).
- Skill: [`.claude/skills/accessibility/SKILL.md`](.claude/skills/accessibility/SKILL.md)
- Agent: [`.claude/agents/a11y-checker.md`](.claude/agents/a11y-checker.md)

### Mazací akce
- Všechny mazací akce musí mít potvrzovací dialog

### Bezpečnost
- U každé funkce: auth, validace, SQLi, XSS, CSRF, tenant izolace
- Rizika zapisuj do `security_warnings.md` v rootu
- Při nejistotě upozorni uživatele

### Grafika a UI
- Brand barvy a fonty: doplnit (zatím neutrální Tailwind; vyhnout se typickým AI fialovým/cream šablonám)
- Světlý režim primárně; dark dle potřeby
- Kontrast WCAG 2.2 AA

### Vyhledávání
- Ověřuj aktuální verze a best practices na internetu

## Dokumentace
Rozcestník: [`docs/README.md`](docs/README.md).

| Vrstva | Složka | Formát |
|--------|--------|--------|
| Produktová spec (Level 3) | `docs/specs/` | dlouhodobý dokument |
| Spec (zadání vlny) | `docs/superpowers/specs/` | `YYYY-MM-DD-nazev.md` |
| Plán | `docs/superpowers/plans/` | `YYYY-MM-DD-nazev.md` |
| Chyby | `docs/superpowers/errors/` | `YYYY-MM-DD-error-cislo-nazev.md` |
| As-is | `docs/as-is/` | `YYYY-MM-DD-nazev.md` |

Workflow: **Explore → Plan → Validate → Implement** ([`.claude/rules/structured-workflow.md`](.claude/rules/structured-workflow.md)).
Po milestone: [`docs/as-is/`](docs/as-is/) ([`.claude/rules/as-is-on-milestone.md`](.claude/rules/as-is-on-milestone.md)).

## Struktura projektu

```
/
├── app/                      # Laravel app (jádro + později Modules/)
├── resources/js/             # Inertia/Vue (admin)
├── resources/views/          # Blade (storefront cíl)
├── routes/
├── database/
├── docs/
│   ├── specs/                # Produktová specifikace platformy
│   ├── superpowers/          # specs / plans / errors
│   ├── as-is/
│   ├── design-droidshop/     # Design handoff (zatím prázdné)
│   ├── future/
│   └── legal/
├── .claude/                  # rules, agents, skills
├── .agents/skills/           # Cursor / agent skills (caveman…)
├── .cursor/rules/
├── VERSION
└── CHANGELOG.md
```

## Omezení agenta
- Při nové funkci se zeptej na role / viditelnost
- Před commitem/pushem vždy požádej o potvrzení
- Netriviální úkoly bez schváleného plánu neimplementovat

## Nuance projektu
- **My ≠ prodávající na storefrontu** — nájemce je provozovatel e-shopu; zakotvit ve VOP (spec kap. 11).
- MVP: jedna šablona storefrontu; šablona = modul (rozšiřitelné).
- Vlastní domény + SSL = fáze 2 (brzy po MVP).
- Licence / digitální produkty s aktivačním API = premium, fáze 2 (spec kap. 17).
- Stojí jádro (tenancy, moduly, kernel služby, superadmin), moduly `categories` + `products` a veřejný storefront katalogu (Blade SSR, SEO výstupy, 301/410). Chybí košík, pokladna a objednávky.

## Rozhodnutí
- 2026-07-17: Produktová spec v1.1 (draft) — multi-tenant SaaS, modularita, shared DB + `tenant_id`
- 2026-07-19: AI workflow z `claude-laravel-vue` + struktura dokumentace ve stylu WooShop
- 2026-07-19: Aktuální kód = Laravel 13 skeleton (Breeze/Inertia); tenancy a moduly teprve
- 2026-07-19: **Storefront povinně Blade SSR** — SEO a marketing nájemce je produktová hodnota; SPA jen admin (viz `.claude/rules/storefront-rendering.md`)
- 2026-07-19: Košík a pokladna také Blade SSR + ostrůvky (ne SPA) — drží AK „checkout funkční bez JS", cenová logika jen na serveru
- 2026-07-19: **URL produktu ploché `/produkt/{slug}`** (odchylka od spec §16.2) — URL se nemění při reorganizaci katalogu; kategorie `/kategorie/{slug}`
- 2026-07-19: `stripe/stripe-php` = zbytek po šabloně, odstranit při první implementační vlně; brána se rozhodne u modulu `billing`
- 2026-07-19: **PHP 8.3** zatím stačí (Laravel 13 vyžaduje `^8.3`, tj. 8.4 je volitelná). Pokud narazíme na funkci vyžadující 8.4 (property hooks, lazy objects, `array_find`), upozorni uživatele — zvedneme constraint na `^8.4`
- 2026-07-19: Page cache — zrušena cookie `has_cart`; mini-košík je ostrůvek, cachované HTML nesmí obsahovat osobní obsah (spec §15.6)
- 2026-07-19: **Multi-tenancy = `spatie/laravel-multitenancy` ^4.1** — navržený pro shared DB + `tenant_id`. `stancl/tenancy` zamítnut (těžiště v DB-per-tenant). Kernel služby dle spec §15.1 píšeme sami
- 2026-07-19: **Moduly = `nwidart/laravel-modules` ^13.0 + vlastní manifest vrstva** — nwidart dá autoloading, scaffolding, migrace per modul; naše vrstva manifest schema, per-tenant aktivaci, route mounting, kill switch, tarify. Kompromis: `modules_statuses.json` = deploy stav, tabulka `tenant_modules` = per-tenant stav. Revidovatelné (alternativa = plně vlastní systém)
- 2026-07-19: **Fáze 0 rozdělena na vlny.** Vlna 0.1 = tenancy jádro + izolace + CI (bez modulů, bez superadmin UI)
- 2026-07-19: **Úložiště = lokální disk pro MVP**, ne S3 (změna původního rozhodnutí „S3 od začátku"). Soubory zůstávají na naší VPS. `FileStorage` služba jádra drží abstrakci — modul nikdy nesahá na disk přímo, takže přechod na S3 je pak jen změna configu. Háček: lokální disk váže na jeden server (víc app serverů = nutné S3) a soubory musí být v záloze VPS. Platí, dokud běžíme na jedné VPS.
- 2026-07-20: **Admin routy modulů jsou za `module:{key}` → `tenant.member`.** Laravelí alias `auth` se schválně nepoužívá — sedí v middleware priority listu a byl by přeřazen před modulový gate, čímž by z jeho 404 udělal redirect na login a prozradil, které moduly e-shop provozuje. Autentizaci dělá `EnsureTenantMember` sám (vyhodí `AuthenticationException`)
- 2026-07-20: **Oprávnění se odvozují z manifestů modulů, které tenant běží** (`TenantPermissions` + `Gate::before`). Právo vypnutého modulu nedostane nikdo, ani vlastník — jinak by deaktivace modulu nechala jeho autorizační plochu otevřenou
- 2026-07-20: **Převody DPH sedí na `TaxRate`, ne na `Money`** (odchylka od plánu vlny 1.1). `Money` je nejprimitivnější hodnotový typ jádra a nesmí znát daň; závislost míří jen jedním směrem
- 2026-07-20: **Inertia stránky modulů leží v `resources/js/Pages/Modules/<Modul>/`**, ne uvnitř modulu. Inertia view finder skládá cestu `{page_path}/{component}.vue`, takže krátký název na cestu uvnitř modulu nenamapuješ bez vlastního finderu. Blade views, routy, controllery a migrace v modulu zůstávají
- 2026-07-20: **Sanitizace tenantem psaného HTML vlastní** (`app/Core/Html/HtmlSanitizer.php` nad `DOMDocument`), bez `htmlpurifier`. Čistí se **při zápisu**, ne při renderu — jinak by se politika rozhodovala znovu na každém call site
- 2026-07-20: **Drag&drop nikdy jako jediná cesta.** Řazení kategorií i obrázků má tlačítka ovladatelná klávesnicí (WCAG 2.1.1). Tažení lze doplnit jako nadstavbu
- 2026-07-20: **Šablona storefrontu = modul `storefront` (core), ale bez `requires` na katalog.** Core modul nejde vypnout a nic pod ním taky ne, takže deklarovaná závislost by z `products` udělala nevypnutelný modul. Šablona se ptá za běhu (`ShopModules`) a vykreslí, co e-shop běží
- 2026-07-20: **Kořenová routa `/` zůstává v jádře a deleguje přes kontrakt `StorefrontHome`.** Core web routy se matchují dřív než modulové, takže modulová routa pro `/` by se nikdy netrefila. Jádro se implementace ptá na modulový klíč, takže kill switch i per-tenant aktivace platí dál
- 2026-07-20: **Redirecty se servírují z handleru `NotFoundHttpException`, ne middlewarem.** Middleware by stál DB dotaz na každém zobrazení produktu; redirect má smysl jen tam, kde už žádná routa nesedí. Responder si přitom sám dohledá tenanta z hostu — nenamatchovaná cesta neprojde `web` skupinou, takže tenant kontext v ní ještě není nastavený
- 2026-07-20: **Vyhledávání přes normalizovaný sloupec `products.search_text` + `LIKE`, ne InnoDB fulltext** (odchylka od §16.1). Fulltext neumí české skloňování ani diakritiku a nejede na SQLite v testech. Normalizace při zápisu je stejně povinná podle §4.1. Háček: `LIKE '%term%'` nepoužije index — u velkých katalogů se bude přepisovat
- 2026-07-19: **Redis je vědomá závislost, ne pohodlí.** Session/fronty/základní cache jdou přepnout na `database` driver bez zásahu do kódu a izolace tenantů zůstane (prefix cache funguje na každém storu). Ale **tagy umí jen Redis** (`database` a `file` je neumí — ověřeno), takže invalidace page cache dle §15.6 by se bez Redisu musela přepsat. Pokud hosting Redis nemá, je to rozhodnutí do specifikace, ne tichý fallback. Navíc: absence Redisu obvykle značí sdílený hosting, kde nejde držet `queue:work` démona — a to bolí víc než cache.
- 2026-07-20: **Identita odesílatele e-mailu sedí na `tenants`, ne v `settings`.** `SettingsService` validuje proti manifestu modulu a mail je jádrová služba bez modulu — průchod přes settings by znamenal vymyslet falešný modul. Obálková adresa zůstává vždy naše (SPF/DKIM), tenant dostává jen display name a reply-to
- 2026-07-20: **Limit `emails_month` nikdy nezastaví transakční poštu.** `MailKind` je povinný argument kontraktu (`Transactional` / `Bulk`), bez výchozí hodnoty — aby se do špatného chování nedalo dojít opomenutím. Vyčerpaný tarif nesmí utnout potvrzení objednávky ani reset hesla: nájemcův nedoplatek by pak odnášel jeho zákazník, který se nedostane do účtu a nedozví se, že má zaplatit. Transakční zprávy se počítají, jen neblokují. Počítadlo bere `queued` i `sent` — čtení jen odeslaných šlo obejít dávkou, která se do fronty vejde celá dřív, než worker doručí první kus
- 2026-07-20: **Finální selhání e-mailu hlásí hook `failed()`, ne počítadlo pokusů.** `SyncJob::attempts()` vrací natvrdo 1, takže podmínka na poslední pokus by na sync driveru nikdy nesepnula a zpráva by navždy zůstala ve stavu `queued`. Během opakování se ukládá jen text chyby, stav se nemění
- 2026-07-20: **Reset hesla zákazníka jede na vlastních tokenech, ne na Laravelím brokeru hesel.** `password_reset_tokens` má primární klíč `email` a repozitář hledá token jen podle adresy. `customers.email` je ale unikátní jen v rámci tenanta, takže zákazníci dvou e-shopů se stejnou adresou by si tokeny tiše přepisovali napříč tenanty — cizí vyžádání resetu by zneplatnilo odkaz někoho jiného. Modul `customers` proto drží vlastní `CustomerTokens` nad tabulkou `customer_tokens` klíčovanou `(tenant_id, email, purpose)`, ukládá jen hash tokenu a stejný mechanismus obsluhuje i verifikaci e-mailu
- 2026-07-20: **GDPR výmaz zákazníka anonymizuje řádek, nemaže ho.** Modul `orders` bude na zákazníka odkazovat cizím klíčem — smazání řádku by buď spadlo na omezení klíče, nebo nechalo viset odkaz a z žádosti o výmaz udělalo rozbitou historii objednávek. `CustomerEraser` přepíše jméno, e-mail (na neuhodnutelný placeholder v doméně `anonymized.invalid`), telefon a adresy, zahodí heslo a orazítkuje `anonymised_at`; běží transakčně, je idempotentní a zapisuje do `AuditLog`
- 2026-07-20: **Anonymizovaného zákazníka odmítá guard, ne middleware ani globální scope na modelu.** `AnonymisedCustomerProvider` (driver `customer-eloquent`) filtruje `whereNull('anonymised_at')` ve všech třech cestách, kterými guard `customer` dohledává uživatele — session, remember-me, přihlášení. Admin dál vidí anonymizované zákazníky beze změny, protože filtr sedí jen na autentizační cestě, ne na modelu. Efekt: zákazník smazaný uprostřed živé session dostane při dalším requestu z guardu `null` a `auth:customer` ho vyhodnotí jako hosta — bez `AuthenticateSession` middlewaru nebo ručního odhlašování
- 2026-07-21: **Prázdná matice doprava×platba znamená „všechny platby povoleny", ne žádná.** Pivot `shipping_method_payment_method` zapisuje omezení, ne povolení. Nájemce, který matici nikdy neotevře, musí mít funkční checkout — doprava bez řádků v pivotu proto nabídne všechny aktivní platby. Opačná volba (prázdná = nic) by z nedotčené obrazovky udělala e-shop, který nepřijme objednávku
- 2026-07-21: **Platební nastavení s tajemstvím je šifrované (`encrypted:array`), dopravní ne.** Výdejní adresa a otevírací doba se tisknou na storefrontu — nejsou tajné a zůstávají prostým JSONem. Bankovní účet pro QR je credential podle §16.5: `payment_methods.settings` je šifrované, v adminu maskované, mění se opětovným zadáním. První tenant-scoped použití `encrypted` castu (dosud jen `PlatformAdmin`)

## Před spuštěním (právní / provozní)
- [ ] VOP platformy (odpovědnost nájemce za obsah)
- [ ] GDPR / zpracování osobních údajů (platforma + vzor pro nájemce)
- [ ] Cookies / ePrivacy
- [ ] Platební účet platformy (předplatné)
- [ ] Wildcard DNS + TLS `*.droidshop.cz` (nebo finální doména)

## Údržba tohoto souboru
- Aktualizuj po strukturální změně, novém pravidle nebo rozhodnutí
- Detaily patří do `docs/` a kódu, ne sem
