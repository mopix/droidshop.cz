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
- Stojí jádro (tenancy, moduly, kernel služby, superadmin), moduly `categories` + `products` + `shipping` + `customers` + `checkout` + `orders` + `payments` + `docs` a veřejný storefront katalogu (Blade SSR, SEO výstupy, 301/410). Zákazník projde nákup od katalogu po děkovnou stránku bez JS včetně platby kartou přes Comgate a dostane fakturu (ruční i automatické vystavení, PDF, e-mail, stažení v účtu); nájemce vidí, edituje a stornuje objednávky v adminu. Faktury, dobropis, proforma a CSV VAT export hotové (vlna 1.6). Self-service onboarding (registrace → wizard → e-shop na subdoméně do 10 minut, cross-host signed auto-login), trial lifecycle scheduler (`billing:sweep-lifecycle`, config `trial_days`/`grace_days`, trial→past_due→suspended), platformní fakturační ledger (netenantový), fakturační profil nájemce hotové (vlna 1.7). **Vlna 1.8 uzavřena:** reálné inkaso platformního předplatného přes Stripe Billing (hostovaný Checkout + Billing Portal, žádné karetní údaje u nás), webhook-driven aktivace (`invoice.paid`/`invoice.payment_failed`/`customer.subscription.deleted`), synchronní `SubscriptionActivator` retirovaný, superadmin manuální aktivace retirovaná (read-only stav). Další vlna dle roadmapy (`docs/superpowers/plans/`).

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
- 2026-07-21: **Odebrání položky z košíku se maže hned, bez potvrzovacího dialogu.** Pravidlo „všechny mazací akce mají potvrzovací dialog" míří na destruktivní perzistentní akce (smazání produktu, storno objednávky, GDPR výmaz) — ne na reverzibilní obsah košíku. Košík je přechodný stav, který zákazník sám plní a odebírá; mezistránka na potvrzení odebrání položky (bez JS by musela být plnohodnotná GET stránka jako `address-delete`) je proti standardnímu e-shop UX a škodí konverzi. Storno a mazání v adminu potvrzení mají dál.
- 2026-07-21: **QR platby jedou na `endroid/qr-code:^6.0`, ne `^6.1`** (odchylka od plánu vlny 1.3). `^6.1` vyžaduje PHP 8.4, projekt drží PHP 8.3 (rozhodnutí „PHP 8.3 zatím stačí"). `^6.0` (6.0.9) má stejný v6 `SvgWriter` bez GD, běží na PHP `^8.2`, SPAYD QR se kreslí jako inline SVG. Až projekt zvedne constraint na PHP 8.4, lze bumpnout na `^6.1`.
- 2026-07-21: **`checkout` nedeklaruje `requires` na `orders` ani `shipping`.** Objednávku zakládá přes `app(OrderPlacement::class)` (jádrový null binding ji odmítne, pokud `orders` neběží) a dopravu/platbu přes `ShippingOptions`/`PaymentOptions` (null → vestavěná nouzovka „osobní odběr zdarma"). Runtime gate přes `ShopModules`, ne manifestová závislost — stejný precedent jako `CustomerIdentity` v etapě 2. Deklarovaná závislost by ze `orders`/`shipping` udělala nevypnutelné moduly, jakmile by je e-shop jednou zapnul spolu s checkoutem
- 2026-07-21: **Cenová autorita je vždy `ProductCatalog::price()`, `cart_items.unit_price` je jen zobrazovací snímek.** `OrderPlacer` každý řádek při odeslání přepočítá z katalogu a nikdy nedůvěřuje snímkované ceně v košíku ani ničemu z POST dat. Neshoda snímku se skutečnou cenou vyhodí `PriceChanged` (banner + přepočet), místo tichého naúčtování staré částky
- 2026-07-21: **Odpis skladu běží uvnitř téže transakce jako zápis objednávky.** `OrderPlacer::place()` volá `ProductCatalog::decrementStock()` (atomický `UPDATE`) dřív, než vloží řádek do `orders` — objednávka, která nevezme sklad, nesmí vzniknout, a naopak sklad vzatý bez uložené objednávky se musí vrátit. Souběh na posledním kusu prohraje na `UniqueConstraintViolationException`, ne na chybějícím skladu: `order_idem_unique` rozhodne, který požadavek dostane existující objednávku místo 500
- 2026-07-21: **Dvojitý stavový automat (`fulfillment_status` × `payment_status`) vynucuje service `OrderWorkflow`, ne UI.** Nezávislé grafy přechodů, nezávislé `order_events` záznamy — objednávka označená „zaplaceno" neříká nic o tom, jestli je zabalená, a naopak (dobírka je běžný případ, kdy „odesláno" nastane dávno před „zaplaceno"). Kontrola nelegálního přechodu proběhne čistě v paměti (lookup do pole) před jakýmkoli dotazem, takže odmítnutý přechod nemá co vracet
- 2026-07-21: **`CatalogProduct` rozšířen o `catalogTaxRatePercent()`.** Snímek řádku objednávky (`order_items.tax_rate`) i VAT rekapitulace v košíku (`CartPricer`) potřebují sazbu DPH produktu v okamžiku nákupu z katalogu, ne přes samostatný dotaz na `TaxRate` — stejná pravda pro cenu i sazbu z jednoho místa
- 2026-07-21: **Kernel drží tvary (`CartShape`/`PlacedOrder`/`OrderView`), moduly je implementují.** Stejný vzor jako `CatalogProduct` u `products` — `checkout` a `orders` vystavují svým modelům (`Cart`, `Order`) tyto shapes přímo, takže volající mimo modul (druhý modul, budoucí `payments`, účet zákazníka) nikdy nesahá na Eloquent model cizího modulu, jen na kontrakt v `app/Core/`
- 2026-07-21: **Platební brány = registry/driver od začátku, ne jeden binding** (vlna 1.4). `PaymentGatewayRegistry::for($provider)` v `app/Core/Payments/` vrací driver podle klíče `payment_methods.provider`; víc bran koexistuje per tenant. Vlna 1.4 registruje jediný driver `ComgateGateway`; GoPay/Stripe = pozdější drivery bez zásahu do checkoutu/webhooku. `NullPaymentGatewayRegistry` = guest-safe (vypnutý modul → `for()` null, `available()` prázdné → online platba se vůbec nenabídne, pokladna jede na offline). Stripe v repu (`stripe/stripe-php`) je pro billing platformy, nemíchat se storefront platbami tenantů
- 2026-07-21: **Verify-before-trust je jediná autorita o zaplacení.** `payment_status = paid` se nastaví výhradně po server-to-server `PaymentGateway::verify()` dotazu na status API brány — nikdy z query návratu (`/platba/navrat`) ani z těla webhooku. Podvržený `?status=paid` nezaplatí nic. Navíc kontrola částky: `paid` výsledek s jinou částkou než `orderTotal()` se nesettluje. Referenci brány váže `orders.payment_reference` uložená při `initiate()`, takže callback re-verifikuje TU referenci, ne referenci z requestu — cizí zaplacená transakce nejde napojit na jinou objednávku
- 2026-07-21: **Settlement přes kontrakt `OrderSettlement`, ne saháním do `OrderWorkflow`.** Modul `payments` verifikuje (má driver), ale přechod stavu + návrat skladu dělá modul `orders` přes `App\Core\Orders\Contracts\OrderSettlement` (`attachReference`/`settlePaid`/`settleFailed`), vedle placement logiky, která sklad vzala. Idempotence: `transitionPayment` je no-op při `from==to` (duplicitní webhook + návrat = jedna změna, jeden `order_events`), settlement běží pod `lockForUpdate`. Graf plateb rozšířen o `failed` (`unpaid→{paid,failed}`, `failed→unpaid`)
- 2026-07-21: **Webhook `/platba/notifikace` mimo CSRF, autentizace podpisem brány.** S2S request nemá session/token; route má `withoutMiddleware(VerifyCsrfToken)` a autenticitu ověřuje `PaymentGateway::verifyNotification()` (Comgate: shared secret v těle). Tenant se řeší z hostu (každý nájemce má vlastní Comgate účet nakonfigurovaný na svou doménu) přes `web`+`module:payments` skupinu. Webhook vždy vrací 2xx po zpracování (i „neznámá objednávka") aby Comgate přestal opakovat; 4xx jen na neověřený/malformovaný
- 2026-07-21: **Expirace neuhrazených online objednávek = odložený queue job, ne cron.** `ExpireUnpaidOrder` dispatchne `ComgateGateway::initiate()` s delay (`config('payments.reservation_ttl_minutes')`, default 30); na běhu označí objednávku `failed` a vrátí sklad, ale jen když je stále `unpaid` (jinak no-op). Tenant-aware fronta. **Háček:** na `sync` driveru by odložený job běžel hned, proto se tam neplánuje a expirace = ruční storno (vrací sklad taky). Retry po failed platbě = nová objednávka, ne in-place re-pay — cancelled platba už vrátila sklad, který mohl mezitím zmizet
- 2026-07-21: **Comgate driver přes `Http` fasádu, bez composer balíčku.** E-commerce v1.0 HTTP-POST protokol (`/create`, `/status`, form-encoded); credentials (merchant/secret/test) per-tenant v `payment_methods.settings` (`encrypted:array`, maskované v adminu, keep-on-update jako QR účet), nikdy v `.env`/configu. `GatewayError` je jádrová výjimka (`app/Core/Payments/`), aby ji checkout chytil bez importu modulu
- 2026-07-22: **Modul `docs` je base; čte a píše přes oddělené kontrakty.** `DocumentIssuer` (write: `issue()`, jádrový null binding `NullDocumentIssuer` vyhazuje) a `DocumentBook` (read: `forOrder()`, `NullDocumentBook` vrací prázdno) — stejný read/write split jako `OrderBook`/`OrderPlacement`. Cizí modul nikdy nesahá na model `Document`
- 2026-07-22: **Doklad je immutable snapshot; oprava jen dobropisem.** Model povolí update jen `pdf_path`/`sent_at`, delete vždy vyhodí. Dobropis = vlna 1.6
- 2026-07-22: **Auto-vystavení přes doménový event z `OrderWorkflow`, deferovaný `DB::afterCommit`.** `settlePaid` nestuje `transitionPayment` do vnější transakce, takže inline dispatch by běžel před commitem — `DB::afterCommit` posune vystřel na skutečný commit. Payments/orders nezná modul `docs`
- 2026-07-22: **PDF přes `barryvdh/laravel-dompdf` (^3.1), ne mpdf/Browsershot.** Pure PHP, bez systémové binárky — sedí k local-first VPS. Faktura A4 = tabulkový layout (dompdf nemá flex/grid)
- 2026-07-22: **Neplátce DPH = render distinkce, ne nový typ.** Plátce → „Faktura – daňový doklad" (DIČ, DPH rekapitulace), neplátce → „Faktura" bez DIČ, `vat_summary` nulové. Enum `type` má invoice/proforma/credit_note od začátku, 1.5 vystavuje jen `invoice`
- 2026-07-22: **Snapshot dodavatele z `tenants.billing_*` v okamžiku vystavení.** Pozdější změna profilu tenanta nemění vystavený doklad
- 2026-07-22: **Faktura PDF na privátní disk (`FileStorage` `tenant_private`), stažení jen přes gated route.** Admin: `docs.manage`; zákazník: `auth:customer` + `customer.session` + vlastník objednávky (`OrderBook::findForCustomer`, cizí = 404 bez leaku), `noindex`. Nikdy veřejné URL
- 2026-07-22: **Registry + `DocumentWriter` (vlna 1.6).** `DocumentIssuer` deleguje per typ přes `DocumentIssuerRegistry`; sdílená write mechanika (číslo, immutable insert, idempotence, PDF dispatch, unique-violation fallback) v `DocumentWriter`; per-typ pravidlo v `TypedDocumentIssuer` (`InvoiceIssuer`/`CreditNoteIssuer`/`ProformaIssuer`). Precedent `PaymentGatewayRegistry` (2026-07-21)
- 2026-07-22: **Číslování — series klíč nese rok (vlna 1.6).** `SequenceService` series `invoices:2026` → čítač se každý rok resetuje (nový rok = nový řádek `sequences`, žádná migrace). Číslo `{PREFIX}{YYYY}{NNNN}` skládá nový core `App\Core\Documents\DocumentNumber`; přidán syrový `SequenceService::nextNumber()`, `next()` beze změny zůstává pro `orders` (číslo objednávky)
- 2026-07-22: **Dobropis = jen plný storno, ruční, gated.** Tlačítko v detailu objednávky, žádný automat. Podmínka: faktura existuje **a** objednávka `cancelled` nebo `refunded`, jinak `CreditNoteNotAllowed` → 422 (tlačítko navíc skryté). Snímek = negace faktury (peníze mění znaménko, sazba DPH `rate` zůstává), odkaz na originál přes `corrects_document_id`/`corrects_number`. Vlastní řada `credit_notes`, PDF bez QR (dobropis nežádá platbu)
- 2026-07-22: **Proforma = ruční, nedaňový doklad.** `taxable_at` = null (bez DUZP), patička „Není daňový doklad", vlastní řada `proformas`, QR pro převod (žádost o platbu). Koexistuje s fakturou na jedné objednávce — unique přepsán na `(tenant_id, type, number)`, takže obě řady smí sdílet stejné číslo
- 2026-07-22: **CSV VAT export dle DUZP, jen `invoice`+`credit_note`.** Nový kontrakt `App\Core\Documents\Contracts\DocumentLedger` (`taxableBetween()`), proforma vyloučena (není daňový doklad), dobropis záporně. Streamovaný, UTF-8 BOM, oddělovač `;` (české Excel locale). CSV formula injection (CWE-1236) neutralizována v `VatCsvWriter` — volné textové sloupce (jméno, IČO, DIČ) escapované vedoucí uvozovkou, peněžní sloupce vědomě ne (záporná částka dobropisu by se jinak zalomila jako text a rozbila `SUM()`)
- 2026-07-22: **Schéma `documents` upraveno pro dobropis/proformu.** `total` `UNSIGNED BIGINT`→`BIGINT` (dobropis je záporný), `taxable_at` NOT NULL→nullable (proforma bez DUZP), unique `(tenant_id, number)`→`(tenant_id, type, number)` (číselné řady jsou per typ, prázdné prefixy by jinak kolidovaly na stejném čísle). Migrace jako alter na již nasazenou tabulku z 1.5, ne přepis historie
- 2026-07-22: **Zakládání tenanta = jeden recept `TenantProvisioner` (vlna 1.7).** Transakčně: tenant (`trial`, `trial_ends_at`), primární subdoména (validace formátu + rezervovaných + unikátnost), owner do `tenant_users`, aktivace modulů tarifu, audit. `DemoShopSeeder` volá tuto službu — žádná druhá cesta. Audit `tenant.provisioned` běží v `runAs($tenant)`, protože `AuditLog` bere `tenant_id` z ambient kontextu, který při zakládání ještě není nastavený
- 2026-07-22: **Onboarding = registrace → Inertia wizard → shop; cross-host přechod přes signed URL.** Owner se registruje na platform hostu, admin běží na subdoméně; `SESSION_DOMAIN=null` (host-only cookie) znamená, že platform session na subdoméně neplatí. Přechod řeší krátkodobá signed URL na cílovém hostu (`onboarding.enter`), která tam založí web-guard session — vzor `ImpersonationController::begin`. Autorita: podpis kryje celou absolutní URL (proto `URL::forceRootUrl` na tenant host před podpisem, ne přepis hotové URL), 5 min TTL, membership check (cizí user → 403), `session()->regenerate()`. Schéma vždy `https` (za TLS-terminating proxy by `getScheme()` vrátil `http` a rozbil podpis). Landing `/admin` = jádrová routa `admin.home` → první položka `NavigationBuilder`, fallback `admin.billing.edit`
- 2026-07-22: **Subdoména se validuje server-side vždy; availability endpoint je jen pohodlí.** `SubdomainName::fromInput()` (formát RFC label 3–63, rezervované z configu) je autorita v `CreateShopRequest`; `GET /onboarding/subdomena/check` (`no-store, private`) jen předběžně informuje wizard. Kolizi řeší DB unique + `SubdomainTaken`, ne kontrola-pak-zápis
- 2026-07-22: **Trial lifecycle = denní command `billing:sweep-lifecycle` (`NotTenantAware`).** Config `trial_days=14`/`grace_days=7`; `trial` po expiraci → `past_due` (storefront běží dál, spec odchylka §2), `past_due` po `trial_ends_at + grace_days` → `suspended` (+ e-mail ownerovi). Přechody přes `Tenant::changeStatus` v `runAs($tenant)` (audit tenant_id). `NotTenantAware` je pro Command inertní (hook jede jen na queued job) — držen jako marker pro budoucí převod na job; skutečná ochrana = command nemá ambient tenant a `MailService` dostává `$tenant` explicitně. **Háček 1.8:** `past_due` je kotvený na `trial_ends_at`; až přibude cesta `active→past_due` (zmeškaná platba), bude potřeba vlastní `past_due_at`
- 2026-07-22: **Platformní billing = samostatný netenantový ledger (`app/Core/Billing/`), docs modul se nešahá.** My fakturujeme nájemci za předplatné — jiná kniha než docs (kde nájemce fakturuje svým zákazníkům). `platform_invoices`/`platform_sequences` bez `tenant_id` (odběratel = `billed_tenant_id`, ne scope), allowlistované v `SchemaConventionTest`. Vlastní gap-free `PlatformSequenceService` (tenant-scoped `SequenceService` by bez tenant kontextu vyhodil). Číslo `PF{YYYY}{NNNN}` přes sdílený `DocumentNumber`. Dodavatel = `config('billing.company')`, odběratel = snímek `tenants.billing_*`; immutable model (update jen `pdf_path`/`sent_at`). VAT split řídí *náš* `vat_payer` (config), ne nájemcův
- 2026-07-22: **`PlatformInvoiceWriter` idempotentní per období.** Klíč `(billed_tenant_id, period_from, period_to)` (unique index + pre-check + `UniqueConstraintViolationException` catch); alokace čísla + insert v `DB::transaction` (gap-free při selhání); `MissingBillingProfile` (prázdné `billing_name`) padne před alokací čísla. PDF renderuje **po** commitu, best-effort (`try/catch` → `report`, `pdf_path` zůstane null) — queued regen job je follow-up
- 2026-07-22: **Reálné inkaso za předplatné je za kontraktem `SubscriptionGateway` (vlna 1.7 jen `NullSubscriptionGateway`).** `charge()` vrací `ChargeResult`; null driver = dev auto-success bez pohybu peněz. `SubscriptionActivator`: charge → (úspěch) → vystav fakturu → `changeStatus(Active)` → prodluž období; faktura je *důsledek* zúčtování, ne naopak. Superadmin akce „Aktivovat předplatné" guarduje `PendingDeletion`/`Deleted` (nelze) a `Active` (žádná re-aktivace = žádné dvojité inkaso), `changeStatus` sám přechody nevaliduje. Stripe driver = vlna 1.8 (žádný zásah do onboardingu/scheduleru/ledgeru). **Háček 1.8:** activator commituje fakturu ve vlastní transakci a teprve pak mění stav mimo ni — u reálné brány by charge-success-then-issue-fail vzal peníze bez aktivace
- 2026-07-22: **Fakturační profil nájemce = jádrová admin obrazovka `/admin/nastaveni/fakturace` (nová core route skupina `routes/tenant.php` pod `['web','tenant.member']`).** Slouží dvěma pánům: dodavatel na fakturách nájemce (docs snímek) + odběratel na naší platformní faktuře. Bez ní nešel vystavit ani docs doklad (dodavatel byl prázdný). Sdílený Inertia prop `billingProfileComplete` + banner v adminu = cesta k obrazovce (není v modulové nav)
- 2026-07-22: **Vlastní doména nájemce = fáze 2, ne 1.7.** Datový model připraven (`domains.type=custom`, `ssl_status`, `DomainTenantFinder` řeší libovolný host), chybí ověření vlastnictví (DNS TXT/CNAME), automatická emise TLS a stavové UI — netriviální infra na VPS, samostatná vlna
- 2026-07-22: **Platformní předplatné = Stripe Billing, ne PaymentIntents (vlna 1.8).** Stripe řídí opakovaný fakturační cyklus, dunning a SCA/3DS retry; my jen reagujeme webhooky. Karta se sbírá přes hostovaný Stripe Checkout (`subscription` mode), správa (karta, zrušení, historie) přes hostovaný Billing Portal — žádné karetní údaje u nás, PCI SAQ-A. `SubscriptionGateway` přepsán na `startCheckout(Tenant, Plan): string` + `billingPortalUrl(Tenant): string`; synchronní `charge()`/`SubscriptionActivator`/`ChargeResult`/`ChargeFailed` retirovány — `SubscriptionCharge`/`MissingBillingProfile`/`PlatformInvoiceWriter` zůstávají
- 2026-07-22: **Aktivace předplatného je webhook-driven, ne superadmin pull.** Superadmin manuální „Aktivovat předplatné" retirováno (jen read-only stav v detailu tenanta) — self-service nájemce přes Checkout, aktivaci potvrzuje Stripe, ne administrátorský klik. Uzavírá háček 1.7 (charge-success-then-issue-fail): peníze a aktivace teď vždy jdou ve stejném pořadí (zaplaceno → doklad → status), protože obojí spouští jeden webhook handler
- 2026-07-22: **Náš netenantový ledger je autoritativní na `invoice.paid`, ne na Stripe invoice.** Stripe invoice je jen inkasní doklad; český daňový doklad vystavuje `PlatformInvoiceWriter` na `StripeWebhookHandler::onInvoicePaid`, idempotentně per fakturační období (stejný klíč jako 1.7: `billed_tenant_id`+`period_from`+`period_to`) — duplicitní doručení webhooku nevystaví druhou fakturu
- 2026-07-22: **Idempotence webhooku = claim `stripe_events.event_id` atomicky se zpracováním v jedné `DB::transaction`, ne odděleně.** Odděleně (claim-commit-pak-zpracuj) by mid-processing selhání nechalo claim zapsaný a Stripe retry by narazil na unique constraint a event by se ztratil navždy bez efektu. V jedné transakci: duplicitní event ztratí unique insert a celá transakce (včetně claimu) se vrátí; skutečné selhání zpracování vrátí i claim, takže retry projde znovu
- 2026-07-22: **Lifecycle sweeper (`billing:sweep-lifecycle`) přeskakuje tenanty s `stripe_subscription_id`.** Jakmile má tenant Stripe subscription, jeho lifecycle řídí webhook (`invoice.payment_failed`→`past_due`, `customer.subscription.deleted`→`suspended`), ne sweeperův odpočet od `trial_ends_at` — bez guardu by sweeper mohl suspendnout tenanta, kterého Stripe teprve retriuje v rámci vlastní dunning politiky
- 2026-07-22: **Paid-through datum reuse `trial_ends_at`, ne nový sloupec.** `invoice.paid` přepíše `trial_ends_at` na `current_period_end` — stejné pole čte trial banner (1.7), tenant-facing subscription screen i superadmin read-only blok jako „platí do", bez ohledu na to, jestli je tenant ještě v trialu nebo už placený. **Háček:** název pole je matoucí mimo kontext; zvážit vlastní `paid_through_at`, pokud přibude potřeba nezávisle trackovat historii období
- 2026-07-22: **Webhook endpoint `POST /superadmin/stripe/webhook` autentizovaný jen podpisem, ne session.** Bez CSRF (`withoutMiddleware(VerifyCsrfToken)`), bez `auth`, autenticita = `Stripe-Signature` header ověřený `\Stripe\Webhook::constructEvent()` proti signing secretu (vzor Comgate webhook 1.4). Vždy 2xx po úspěšném ověření podpisu (i „neznámý zákazník"), aby Stripe přestal opakovat; 4xx jen na neplatný/chybějící podpis. Cesta v `/superadmin/*`, ne `/platform/*` z návrhu specu — sedí do existující konvence netenantových platformních rout
- 2026-07-22: **`CheckTenantStatus` vynucuje admin write-freeze přes `allowsAdminWrite()`, ne jen read-gate.** Admin routy: `Deleted` → 503 (žádný read); Suspended/PendingDeletion vidí read-only admin (stáhnout/exportovat data, §6.0) ale nebezpečné metody (POST/PATCH/DELETE) → 503, **výjimka `admin.subscription.checkout`/`admin.subscription.portal`** (jinak by se suspendovaný nájemce nemohl zaplatit zpět). Trial/Active/PastDue zapisují normálně (`allowsAdminWrite()` true). Háček z 1.8: read-gate split se nejdřív svezl v nesouvisejícím fix commitu bez write-gate → final review odhalil, že suspendovaný nájemce mohl zapisovat v adminu; write-freeze doplněn. Enforcement je verb-based (`isMethodSafe()`), takže mutující GET (např. dev-only `devComplete`) ho obejde — dořešit, pokud přibude mutující GET v produkci

## Před spuštěním (právní / provozní)
- [ ] VOP platformy (odpovědnost nájemce za obsah)
- [ ] GDPR / zpracování osobních údajů (platforma + vzor pro nájemce)
- [ ] Cookies / ePrivacy
- [ ] Platební účet platformy (předplatné)
- [ ] Wildcard DNS + TLS `*.droidshop.cz` (nebo finální doména)

## Údržba tohoto souboru
- Aktualizuj po strukturální změně, novém pravidle nebo rozhodnutí
- Detaily patří do `docs/` a kódu, ne sem
