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

## Marketing (volitelný plugin, default vypnutý)

Marketplace `marketingskills` registrovaný v `.claude/settings.json`, plugin **není** zapnutý — zapíná ho uživatel jen na
marketingovou práci. Příkazy a seznam skills: [`README.md`](README.md#agentské-pluginy-claude-code).
Kontext produktu, který marketing skills čtou první: [`.agents/product-marketing.md`](.agents/product-marketing.md) — draft v1,
ceny a metriky neověřené.

## Omezení agenta
- Při nové funkci se zeptej na role / viditelnost
- Před commitem/pushem vždy požádej o potvrzení
- Netriviální úkoly bez schváleného plánu neimplementovat

## Stav

Platforma je funkčně hotová: jádro (tenancy, moduly, kernel služby, superadmin), 15 modulů, veřejný
storefront v Blade SSR se **třemi volitelnými šablonami**, kompletní nákupní cesta včetně plateb
a dokladů, page cache, self-service onboarding se Stripe Billing, administrace nájemce a právní
minimum. Testy: ~2250 PHPUnit + 92 Playwright.

**Zbývá nasazení na VPS** a **právní revize draftů** v `docs/legal/`. Otevřené jsou jen věci vázané na
server (page cache etapa 2, Caddy TLS, cron, reálné účty Stripe/Comgate/Packeta/GA4).

Podrobný stav po oblastech: [`docs/as-is/STATUS.md`](docs/as-is/STATUS.md).
Prozaický přehled vln 0.1–3.9: [`docs/as-is/2026-08-14-prehled-vln.md`](docs/as-is/2026-08-14-prehled-vln.md).

## Rozhodnutí

Architektonická rozhodnutí a jejich odůvodnění žijí v [`docs/decisions/`](docs/decisions/) — 206 položek
rozdělených po oblastech.

**Než sáhneš na kód v některé oblasti, přečti si její soubor.** Většina těch položek nepopisuje
preferenci, ale past, do které se v tomhle projektu už jednou šláplo a která se bez přečtení zopakuje.

| Oblast | Soubor |
|---|---|
| Architektura, tenancy, modulární systém, kernel služby | [`01-architektura-a-tenancy.md`](docs/decisions/01-architektura-a-tenancy.md) |
| Katalog, produkty, ceny, DPH na produktu, CSV | [`02-katalog-a-produkty.md`](docs/decisions/02-katalog-a-produkty.md) |
| Košík, pokladna, objednávky, slevy | [`03-checkout-a-objednavky.md`](docs/decisions/03-checkout-a-objednavky.md) |
| Platby, doklady, účetní export | [`04-platby-a-doklady.md`](docs/decisions/04-platby-a-doklady.md) |
| Storefront, SEO, page cache, feedy | [`05-storefront-a-seo.md`](docs/decisions/05-storefront-a-seo.md) |
| Doprava (Zásilkovna) | [`06-doprava.md`](docs/decisions/06-doprava.md) |
| Billing platformy, Stripe, domény nájemců | [`07-billing-platformy.md`](docs/decisions/07-billing-platformy.md) |
| Administrace a UI | [`08-admin-a-ui.md`](docs/decisions/08-admin-a-ui.md) |
| Bezpečnost, ochrana údajů, právo | [`09-bezpecnost-a-pravo.md`](docs/decisions/09-bezpecnost-a-pravo.md) |
| Testy a provoz | [`10-testy-a-provoz.md`](docs/decisions/10-testy-a-provoz.md) |

Nové rozhodnutí zapiš do příslušného souboru, ne sem. Historickou položku nikdy nepřepisuj — přidej
novou, která ji ruší.

## Před spuštěním (právní / provozní)
- [x] VOP platformy (odpovědnost nájemce za obsah) — **draft hotový** (vlna 3.2), čeká na právní revizi
- [x] GDPR / zpracování osobních údajů (platforma + vzor pro nájemce) — **draft hotový** vč. zpracovatelské smlouvy podle čl. 28
- [ ] **Právní revize draftů** — začít od markerů `> **K PRÁVNÍ REVIZI:**` v `docs/legal/` (limitace náhrady škody, výpovědní doba, SLA, lhůta pro ohlášení porušení zabezpečení, rozsah auditního práva)
- [ ] `.env` produkce: `BILLING_COMPANY_ICO`, `BILLING_COMPANY_ADDRESS`, `BILLING_COMPANY_EMAIL` — bez nich se právní stránky vyrenderují s prázdnými identifikačními údaji
- [ ] Rozhodnout, zda nájemcům registrovaným před vlnou 3.2 (bez `terms_accepted_at`) předložit souhlas dodatečně
- [x] Cookies / ePrivacy — zásady platformy (3.2) i cookie lišta se třemi kategoriemi a měřicí kódy nájemce (3.3) hotové
- [ ] **Vlna 3.3 — měření:** ověřit s **reálnými** účty GA4 (přijde `page_view` i `purchase`), Sklik konverzi, Meta Pixel a Heureka API klíč — testy je stubují. Projít lištu se zapnutým blokátorem reklam (může zablokovat i náš vlastní loader). `modules:sync` před `migrate` (nový modul `analytics`)
- [ ] Platební účet platformy (předplatné)
- [ ] Wildcard DNS + TLS `*.droidshop.cz` (nebo finální doména)
- [ ] Stripe Billing Portal nakonfigurovat: povolit „switch plans" se 4 cenami (base/premium × month/year), proration = „Prorate changes"; configuration id do `config('billing.stripe.portal_config')`
- [ ] 4 Stripe Price objekty vytvořit a jejich id zapsat do `plan_prices`; povolit event `customer.subscription.updated` na webhook endpointu
- [ ] **Vlna 2.11/2.12 — účetní export:** ověřit **reálný import Pohoda XML do Pohody** (ISDOC už hlídá test proti oficiálnímu XSD, neplátce DPH je otestovaný); po nasazení zkontrolovat, že fakturační profily nájemců mají vyplněnou zemi
- [ ] **Zásilkovna — doručení na adresu:** `php artisan migrate` (rozšíření enumu `shipping_methods.provider`) + `npm run build`; **ověřit tvar odpovědi `packetCourierNumber` a `packetCourierLabelPdf` proti reálnému účtu** (v testech jsou stubované a tvar odpovědi je odhad z dokumentace); zkontrolovat, že id partnerského dopravce v nastavení metody odpovídá tomu, co má nájemce ve smlouvě povolené
- [ ] **Rich text editor:** `npm run build` (žádná migrace); po nasazení otevřít jeden existující produkt s tabulkou nebo obrázkem v popisu, uložit beze změny a ověřit, že se obsah nezměnil
- [ ] **Bezpečnost, mimo rozsah vlny:** `HtmlSanitizer::isSafeUrl` propouští protokolově relativní `//evil.com` v odkazu psaném nájemcem (open redirect maskovaný jako interní cesta) — detail a návrh opravy v `security_warnings.md`
- [ ] **Vlna 4.1 — šablony storefrontu:** `php artisan migrate` (`tenant_theme.template`, default `base`) + `npm run build` (nové Vite vstupy `themes/{editorial,retail}.css`); ověřit, že se nasadily statické soubory `public/fonts/editorial/` a `public/fonts/retail/` (nejsou součástí buildu), a po nasazení zkontrolovat, že stávající nájemci mají `template = 'base'` a jejich e-shop vypadá stejně jako předtím
- [ ] **Vlna 3.9:** `php artisan migrate` (`products.purchase_tax_rate_id`, `products.sale_percent`) + `npm run build`
- [ ] **Vlna 3.8:** `php artisan migrate` (rozměry produktu) + `npm run build`; po nasazení ověřit, že uložená cena sedí na to, co bylo do formuláře zadáno
- [ ] **Vlna 3.7:** `npm run build`; po nasazení zkontrolovat, že fakturační profily nájemců mají správně zaškrtnuté plátcovství DPH — od této vlny řídí i katalog a storefront, ne jen doklady
- [ ] **Vlna 3.6:** `php artisan migrate` (`shop_settings`, `tenants.storefront_locked`) + `npm run build`; po nasazení projít čtyři obrazovky nastavení a ověřit, že se slogan a kontakty objeví na storefrontu
- [ ] **Vlna 2.10:** `php artisan modules:sync` **před** `migrate` (nová manifestová pole a schémata; sync odmítne vadné schéma), pak `migrate` (přesun `variant_display` + drop sloupce) a `npm run build`
- [ ] **Vlna 2.1 — custom domény:** Caddy `on_demand_tls { ask http://127.0.0.1:<port>/internal/tls-check?token=<PLATFORM_TLS_CHECK_TOKEN> }`; on-demand jen pro custom domény, subdomény wildcard cert (DNS-01); Caddyfile **zamítni veřejný `/internal/*`**
- [ ] `edge.droidshop.cz` A → VPS IP (cíl CNAME custom domén nájemců)
- [ ] `.env` produkce: `PLATFORM_SERVER_IP`, `PLATFORM_EDGE_HOST`, silný `PLATFORM_TLS_CHECK_TOKEN` (stejný v Caddyfile ask URL)
- [ ] Cron `schedule:run` běží (kvůli `domains:sweep-pending` hodinově a `packeta:sync-points` denně) — runbooky `docs/as-is/2026-07-23-custom-domains.md` a `docs/as-is/2026-07-27-zasilkovna.md`
- [ ] **Vlna 2.5 — Zásilkovna:** `PACKETA_FEED_API_KEY` v produkčním `.env` (bez něj sync jede na klíči prvního nakonfigurovaného tenanta); ověřit reálná volání Packeta API s testovacím účtem (v testech `Http::fake`); zkontrolovat, že `PACKETA_TIMEOUT` je aspoň 2× menší než `submit_stale_after_minutes` (guard jinak selže hlasitě); ověřit, že widget v6 nenačítá cizí doménu dřív, než na něj zákazník klikne

## Údržba tohoto souboru
- Aktualizuj po strukturální změně nebo novém pravidle
- **Nové rozhodnutí patří do [`docs/decisions/`](docs/decisions/)**, ne sem — tenhle soubor se načítá
  agentovi do kontextu při každé session, takže roste na účet každé konverzace
- Stav vln patří do [`docs/as-is/STATUS.md`](docs/as-is/STATUS.md), ne sem
- Detaily patří do `docs/` a kódu, ne sem
