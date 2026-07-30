# Demo — co proklikat (URL + přihlašovací údaje)

Klikací seznam pro lokální náhled. **Jen dev, ne produkce.** Podrobnosti setupu a pasti: [`docs/DEMO-LOCAL.md`](DEMO-LOCAL.md).

Stav: vlny 1.3–1.9 + 2.1–2.9 v `main` (v0.27.0). Poslední vlna 2.9 přidala **XML feedy** pro Heureku a Zboží.cz (viz níže).

## 0. Rozjezd (jednou)

```bash
php artisan migrate
php artisan modules:sync
CACHE_STORE=array SESSION_DRIVER=file QUEUE_CONNECTION=sync php artisan db:seed --class=DemoShopSeeder --force
npm run build
CACHE_STORE=array SESSION_DRIVER=file QUEUE_CONNECTION=sync php artisan serve --port=8000 --no-reload
```

`/etc/hosts` (jednou):
```
127.0.0.1 obchod.droidshop droidshop admin.droidshop
```
> `CACHE_STORE=array` obchází rozbitou cache serializaci na dev mašině (viz DEMO-LOCAL past #1). Chrome + `.droidshop`: vypni DoH a HTTPS-First, viz past #3.
>
> **Port musí sedět s běžícím `serve`** (výchozí `8000`). Po `git pull`/merge **restartuj server** (`kill` + `php artisan optimize:clear` + znovu `serve`) — stará instance nemá nové routy a hodí 404.

## Přihlašovací údaje (všude heslo `password`)

| Role | Kde | E-mail | Heslo |
|------|-----|--------|-------|
| **Superadmin** (platforma) | `droidshop:8000/superadmin/login` | `super@droidshop.cz` | `password` |
| **Nájemce / owner** (admin e-shopu) | `obchod.droidshop:8000/login` | `admin@demo.cz` | `password` |
| **Zákazník** (storefront) | registruj se sám, nebo `test@example.com` (z `DatabaseSeeder`) | — / `password` | — |

> Superadmin projde při prvním loginu **2FA setupem** (TOTP — načti QR do authenticatoru).

---

## 1. Storefront — zákazník · `http://obchod.droidshop:8000`

| Co | URL |
|----|-----|
| Homepage | http://obchod.droidshop:8000/ |
| Produkt — klávesnice | http://obchod.droidshop:8000/produkt/mechanicka-klavesnice-droid-k1 |
| Produkt — myš | http://obchod.droidshop:8000/produkt/bezdratova-mys-droid-m2 |
| Produkt — dok | http://obchod.droidshop:8000/produkt/usb-c-dokovaci-stanice-droid-d3 |
| Produkt — sluchátka | http://obchod.droidshop:8000/produkt/sluchatka-droid-h4 |
| Vyhledávání | http://obchod.droidshop:8000/hledani?q=droid |
| Košík | http://obchod.droidshop:8000/kosik |
| Pokladna — doprava/platba | http://obchod.droidshop:8000/pokladna/doprava |
| Pokladna — údaje | http://obchod.droidshop:8000/pokladna/udaje |
| Registrace zákazníka | http://obchod.droidshop:8000/registrace |
| Přihlášení zákazníka | http://obchod.droidshop:8000/prihlaseni |
| Sitemap | http://obchod.droidshop:8000/sitemap.xml |
| robots.txt | http://obchod.droidshop:8000/robots.txt |
| **Feed Heureka (2.9)** | http://obchod.droidshop:8000/feed/heureka.xml |
| **Feed Zboží.cz (2.9)** | http://obchod.droidshop:8000/feed/zbozi.xml |

> Slug produktu se odvozuje z názvu, ne z SKU (`kdroid-k1` je SKU). Aktuální seznam: `php artisan tinker --execute="app(App\Core\Tenancy\TenantContext::class)->runAs(App\Models\Tenant::first(), fn () => print(implode(PHP_EOL, Modules\Products\Models\Product::pluck('slug')->all())));"`
>
> Vypnutý feed (nebo vypnutý modul `feeds`) vrací **404**, ne prázdné XML — porovnávač si prázdný feed vyloží jako „e-shop nemá zboží".

**Nákupní tok:** produkt → do košíku → `/kosik` → `/pokladna/doprava` (kurýr / osobní odběr; dobírka / převod QR / karta Comgate test) → `/pokladna/udaje` → děkovná stránka → faktura ke stažení v účtu. Vše funguje **bez JS**. Comgate je test mód — reálná platba neproběhne, ale redirect/návrat/admin je vidět.

> Kategorie a statické stránky (`/kategorie/...`, `/stranka/...`) demo neseeduje — přidej je v adminu nájemce.

## 2. Admin nájemce · `http://obchod.droidshop:8000` (login `admin@demo.cz`)

| Co | URL |
|----|-----|
| Login | http://obchod.droidshop:8000/login |
| Přehled adminu | http://obchod.droidshop:8000/admin |
| Produkty | http://obchod.droidshop:8000/admin/m/products |
| **CSV import produktů (2.8)** | http://obchod.droidshop:8000/admin/m/products/import |
| **CSV export produktů (2.8)** | http://obchod.droidshop:8000/admin/m/products/export |
| Kategorie | http://obchod.droidshop:8000/admin/m/categories |
| Objednávky | http://obchod.droidshop:8000/admin/m/orders |
| Ruční objednávka | http://obchod.droidshop:8000/admin/m/orders/vytvorit |
| Zákazníci | http://obchod.droidshop:8000/admin/m/customers |
| Doprava a platby | http://obchod.droidshop:8000/admin/m/shipping |
| Matice doprava × platba | http://obchod.droidshop:8000/admin/m/shipping/matice |
| **Expediční fronta Zásilkovny (2.5)** | http://obchod.droidshop:8000/admin/m/packeta/expedice |
| **Slevy a kupóny (2.6)** | http://obchod.droidshop:8000/admin/m/discounts |
| **Nová sleva (2.6)** | http://obchod.droidshop:8000/admin/m/discounts/nova |
| **Feedy — Heureka / Zboží (2.9)** | http://obchod.droidshop:8000/admin/m/feeds |
| **Bloková homepage (2.3)** | http://obchod.droidshop:8000/admin/m/storefront/homepage |
| Doklady (faktury) | http://obchod.droidshop:8000/admin/m/docs |
| CSV VAT export | http://obchod.droidshop:8000/admin/m/docs/dph-export |
| Statické stránky | http://obchod.droidshop:8000/admin/m/pages |
| Fakturační profil | http://obchod.droidshop:8000/admin/nastaveni/fakturace |
| **Vlastní doména (2.1)** | http://obchod.droidshop:8000/admin/nastaveni/domena |
| **Vzhled — logo/barvy (2.2)** | http://obchod.droidshop:8000/admin/nastaveni/vzhled |
| **Nastavení modulů (2.10)** | http://obchod.droidshop:8000/admin/nastaveni/moduly |
| Předplatné (Stripe) | http://obchod.droidshop:8000/admin/predplatne |
| Faktury předplatného | http://obchod.droidshop:8000/admin/predplatne/faktury |
| „Moje e-shopy" (dashboard) | http://obchod.droidshop:8000/dashboard |

> **Vlastní doména** lokálně jen ukáže formulář + DNS instrukce + stavový badge. Reálné ověření/emise certu potřebuje veřejnou VPS + Caddy + DNS (runbook `docs/as-is/2026-07-23-custom-domains.md`), lokálně se doména neověří.

## 3. Superadmin — platforma · `http://droidshop:8000` (login `super@droidshop.cz`)

| Co | URL |
|----|-----|
| Login | http://droidshop:8000/superadmin/login |
| Dashboard | http://droidshop:8000/superadmin |
| Tenanti | http://droidshop:8000/superadmin/tenanti |
| Moduly (kill switch) | http://droidshop:8000/superadmin/moduly |
| **Tarify — složení modulů (2.10)** | http://droidshop:8000/superadmin/tarify |

Odtud přes navigaci: tenanti (stavy, tarify, moduly, kill switch), platformní faktury, impersonace nájemce. Detaily rout: `php artisan route:list | grep superadmin`.

> `/superadmin/moduly` je jen přehled + globální kill switch. Zapnutí modulu **konkrétnímu** nájemci je na detailu tenanta; složení tarifu se od vlny 2.10 edituje na `/superadmin/tarify` (uložení rekonciluje moduly všem e-shopům tarifu, odebrání vyžaduje důvod). Nastavení uvnitř modulu (`settings_schema`) má od 2.10 obrazovku v adminu nájemce (`/admin/nastaveni/moduly`).
>
> **Demo tenant je starší než pozdější vlny**, takže moduly přidané po jeho založení (`docs`, `discounts`, `packeta`, `pages`, `feeds`) mu musí být dozapnuté — buď na detailu tenanta v superadminu, nebo tinkerem přes `ModuleRegistry::activate()`. Nový tenant z `TenantProvisioner` je dostane rovnou.

## 4. Onboarding nového nájemce (self-service)

Na platform hostu `http://droidshop:8000` se přihlas jako User a jdi na `http://droidshop:8000/onboarding` (registrace → subdoména s live checkem → tarif → auto-login na admin subdomény). Registrace nového účtu: `http://droidshop:8000/register`.
