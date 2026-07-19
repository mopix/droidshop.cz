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
- Storage: S3-kompatibilní od začátku (ne jen lokální disk)
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
- Skeleton je Laravel Breeze + Inertia — business moduly ještě nejsou.

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

## Před spuštěním (právní / provozní)
- [ ] VOP platformy (odpovědnost nájemce za obsah)
- [ ] GDPR / zpracování osobních údajů (platforma + vzor pro nájemce)
- [ ] Cookies / ePrivacy
- [ ] Platební účet platformy (předplatné)
- [ ] Wildcard DNS + TLS `*.droidshop.cz` (nebo finální doména)

## Údržba tohoto souboru
- Aktualizuj po strukturální změně, novém pravidle nebo rozhodnutí
- Detaily patří do `docs/` a kódu, ne sem
