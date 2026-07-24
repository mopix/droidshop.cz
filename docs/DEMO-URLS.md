# Demo — co proklikat (URL + přihlašovací údaje)

Klikací seznam pro lokální náhled. **Jen dev, ne produkce.** Podrobnosti setupu a pasti: [`docs/DEMO-LOCAL.md`](DEMO-LOCAL.md).

Stav: vlny 1.3–1.9 + 2.1 v `main` (v0.19.0). Vlna 2.1 přidala obrazovku **Vlastní doména** (viz níže).

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
| Produkt — klávesnice | http://obchod.droidshop:8000/produkt/kdroid-k1 |
| Produkt — myš | http://obchod.droidshop:8000/produkt/mysh-m2 |
| Produkt — dok | http://obchod.droidshop:8000/produkt/dok-d3 |
| Produkt — sluchátka | http://obchod.droidshop:8000/produkt/sluch-h4 |
| Vyhledávání | http://obchod.droidshop:8000/hledani?q=droid |
| Košík | http://obchod.droidshop:8000/kosik |
| Pokladna — doprava/platba | http://obchod.droidshop:8000/pokladna/doprava |
| Pokladna — údaje | http://obchod.droidshop:8000/pokladna/udaje |
| Registrace zákazníka | http://obchod.droidshop:8000/registrace |
| Přihlášení zákazníka | http://obchod.droidshop:8000/prihlaseni |
| Sitemap | http://obchod.droidshop:8000/sitemap.xml |
| robots.txt | http://obchod.droidshop:8000/robots.txt |

**Nákupní tok:** produkt → do košíku → `/kosik` → `/pokladna/doprava` (kurýr / osobní odběr; dobírka / převod QR / karta Comgate test) → `/pokladna/udaje` → děkovná stránka → faktura ke stažení v účtu. Vše funguje **bez JS**. Comgate je test mód — reálná platba neproběhne, ale redirect/návrat/admin je vidět.

> Kategorie a statické stránky (`/kategorie/...`, `/stranka/...`) demo neseeduje — přidej je v adminu nájemce.

## 2. Admin nájemce · `http://obchod.droidshop:8000` (login `admin@demo.cz`)

| Co | URL |
|----|-----|
| Login | http://obchod.droidshop:8000/login |
| Přehled adminu | http://obchod.droidshop:8000/admin |
| Produkty | http://obchod.droidshop:8000/admin/m/products |
| Kategorie | http://obchod.droidshop:8000/admin/m/categories |
| Objednávky | http://obchod.droidshop:8000/admin/m/orders |
| Ruční objednávka | http://obchod.droidshop:8000/admin/m/orders/vytvorit |
| Zákazníci | http://obchod.droidshop:8000/admin/m/customers |
| Doprava a platby | http://obchod.droidshop:8000/admin/m/shipping |
| Matice doprava × platba | http://obchod.droidshop:8000/admin/m/shipping/matice |
| Doklady (faktury) | http://obchod.droidshop:8000/admin/m/docs |
| CSV VAT export | http://obchod.droidshop:8000/admin/m/docs/dph-export |
| Statické stránky | http://obchod.droidshop:8000/admin/m/pages |
| Fakturační profil | http://obchod.droidshop:8000/admin/nastaveni/fakturace |
| **Vlastní doména (2.1)** | http://obchod.droidshop:8000/admin/nastaveni/domena |
| Předplatné (Stripe) | http://obchod.droidshop:8000/admin/predplatne |
| Faktury předplatného | http://obchod.droidshop:8000/admin/predplatne/faktury |
| „Moje e-shopy" (dashboard) | http://obchod.droidshop:8000/dashboard |

> **Vlastní doména** lokálně jen ukáže formulář + DNS instrukce + stavový badge. Reálné ověření/emise certu potřebuje veřejnou VPS + Caddy + DNS (runbook `docs/as-is/2026-07-23-custom-domains.md`), lokálně se doména neověří.

## 3. Superadmin — platforma · `http://droidshop:8000` (login `super@droidshop.cz`)

| Co | URL |
|----|-----|
| Login | http://droidshop:8000/superadmin/login |
| Dashboard | http://droidshop:8000/superadmin |

Odtud přes navigaci: tenanti (stavy, tarify, moduly, kill switch), platformní faktury, impersonace nájemce. Detaily rout: `php artisan route:list | grep superadmin`.

## 4. Onboarding nového nájemce (self-service)

Na platform hostu `http://droidshop:8000` se přihlas jako User a jdi na `http://droidshop:8000/onboarding` (registrace → subdoména s live checkem → tarif → auto-login na admin subdomény). Registrace nového účtu: `http://droidshop:8000/register`.
