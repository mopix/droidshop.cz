# Vlna 2.2 — Šablona storefrontu + branding nájemce — design

Datum: 2026-07-25 · Fáze 2 · Navazuje na: modul `storefront` (Blade SSR), `FileStorage`, per-tenant nastavení.

## Cíl

Dát storefrontu **prodejný vzhled** místo dnešní holé šablony a umožnit nájemci **přebrandovat** e-shop (logo, favicon, barvy). Jedna výchozí šablona v čistém/minimalistickém stylu, konzistentní přes všechny veřejné stránky. Zákazník i nadále projde celý nákup **bez JS** (Blade SSR, pravidlo storefront-rendering).

Bloková homepage (page builder) = samostatná **vlna 2.3** (mimo tento spec). Marketingová a technická šablona = `docs/future/`.

## Rozsah (co je uvnitř)

1. **Designový systém** — Tailwind design tokeny (barvy, typografie, mezery, radius, stíny) v čistém stylu; komponentní třídy pro tlačítka, karty, formuláře, badge, tabulky.
2. **Přebarvení všech storefront stránek** — layout (header/footer/nav), homepage, výpis kategorie, detail produktu, hledání, košík, pokladna (doprava + údaje), děkovná stránka, účet zákazníka (přehled, objednávky, adresy, auth stránky), statické stránky (Pages), chybové (404/410/503).
3. **Branding nájemce** — logo, favicon, primární + akcentní barva; injektované do layoutu.
4. **Admin obrazovka „Vzhled"** — upload loga/favicon, výběr barev, kontrola kontrastu, odkaz na náhled.

## Architektura

### Kde šablona žije
Zůstává v modulu `storefront` (`Modules/Storefront/Resources/views/`). Žádné SPA, žádný nový povinný JS na storefrontu (drží `.claude/rules/storefront-rendering.md`). Vylepšení jsou čistě Blade + CSS.

### Injektování brandingu — CSS custom properties
Barvy nájemce se vloží jako inline `<style>` s CSS proměnnými do `<head>` layoutu:
```html
<style>
  :root {
    --brand-primary: #0f766e;
    --brand-primary-contrast: #ffffff; /* dopočítaný text na primární barvě */
    --brand-accent: #f59e0b;
  }
</style>
```
Tailwind tokeny se namapují na proměnné (`bg-brand`, `text-brand`, `ring-brand`…) přes `tailwind.config` (storefront preset). Tvrdě zadané `slate-900` / `bg-slate-900` v dnešní šabloně se nahradí tokeny.

**Proč CSS proměnné, ne per-tenant zkompilované CSS:** jedno sdílené CSS pro všechny nájemce (jeden build, dlouhá cache), branding je jen pár proměnných v `<head>`. Přechod na per-tenant build by znamenal N buildů a invalidaci cache při každé změně barvy.

### Napojení dat
Existující `View::composer('storefront::layouts.shop', …)` v `Modules/Storefront/Providers/ModuleProvider.php` (dnes plní `shopName`, `navCategories`, `cartEnabled`) se rozšíří o `theme` (logo URL, favicon URL, barvy). **Jediné místo** — composer běží na každé storefront stránce.

### Cache-safe
Branding je **per-tenant, ne per-návštěvník** — cachované HTML ho smí obsahovat (na rozdíl od mini-košíku). Žádný osobní obsah se nepřidává. Drží spec §15.6.

## Datový model

Nastavení vzhledu na úrovni nájemce (ne per-návštěvník). Volba: sloupce na `tenants` vs. samostatná tabulka `tenant_theme` vs. `SettingsService`.

**Rozhodnutí:** samostatná tabulka **`tenant_theme`** (1:1 s tenantem), ne sloupce na `tenants` (to je identita/fakturace, ne prezentace) a ne `SettingsService` (ten validuje proti manifestu modulu; vzhled je jádrová prezentační vrstva, ne modulový setting — stejná úvaha jako fakturační profil je core, ne modul).

Pole:
- `tenant_id` (FK, unique)
- `logo_path` (nullable, `FileStorage` `tenant_public`)
- `favicon_path` (nullable)
- `primary_color` (hex, default neutrální — např. `#0f172a`)
- `accent_color` (hex, default)
- timestamps

Obrázky přes `FileStorage` (veřejný disk — logo je na veřejném storefrontu). Barvy validované jako hex.

## Admin obrazovka „Vzhled"

Nová core tenant route (vzor `BillingProfileController` + `routes/tenant.php`):
- `GET /admin/nastaveni/vzhled` → `admin.appearance.edit`
- `POST /admin/nastaveni/vzhled` → `admin.appearance.update` (barvy + upload)
- `DELETE /admin/nastaveni/vzhled/logo` (a favicon) → smazání obrázku

Controller `Tenant/AppearanceController` (core, `['web','tenant.member']`, write-freeze platí). Inertia stránka `Tenant/Appearance.vue`:
- Upload loga + favicon (náhled, mazání s potvrzením).
- Color picker pro primární + akcentní barvu.
- **Kontrola kontrastu** primární barvy vůči bílé/textu (WCAG 2.2 AA 4.5:1) — varování, když nájemce zvolí nízký kontrast; dopočítá se `--brand-primary-contrast` (bílá/černá text dle luminance).
- Odkaz „Zobrazit e-shop" (náhled na storefrontu).
- Discoverability: nav odkaz v adminu (vzor domény/fakturace).

## Přístupnost + SEO

- **WCAG 2.2 AA:** kontrast se hlídá při zadání barvy; text nikdy nesmí být nesen jen barvou; focus stavy z tokenů; skip-link zůstává.
- **Kontrast fallback:** `--brand-primary-contrast` (text na primární barvě) se dopočítá z luminance primární barvy — tlačítka zůstanou čitelná i při špatné volbě.
- **SEO beze změny:** stejné title/meta/canonical/OG/JSON-LD/sitemap; theme nemění strukturu ani obsah, jen vzhled. Logo dostane `alt` = název e-shopu.
- **Výkon:** žádný nový JS na storefrontu; jedno sdílené CSS; obrázky přes `FileStorage` s rozumnou velikostí (logo doporučená max velikost, validace).

## Testy

- Rendering všech storefront stránek s theme (smoke + přítomnost brand proměnných v HTML).
- **Tenant izolace:** nájemce A vidí své barvy/logo, ne barvy nájemce B (composer bere z tenant kontextu).
- Admin „Vzhled": update barev, upload/smazání loga (potvrzení), kontrast varování, write-freeze pro suspended.
- Fallback: bez nastaveného theme → neutrální default barvy, žádný crash.
- WCAG přes `a11y-checker` na `Appearance.vue` + vzorové storefront stránce.
- Curl kontrola (storefront-rendering checklist): produkt/cena v surovém HTML, průchod nákupem bez JS.

## Mimo rozsah (YAGNI / pozdější vlny)

- **Bloková homepage / page builder** (hero, vybrané produkty, dlaždice kategorií, text) = vlna 2.3.
- **Další šablony** (marketingová, technická) = `docs/future/` → samostatné vlny; dnešní práce staví token systém tak, aby další šablona = nový preset, ne přepis.
- Vlastní fonty nájemce, dark mode storefrontu, per-kategorie bannery, theme marketplace.

## Odchylky / háčky

- **Barvy jako CSS proměnné v inline `<style>`** (ne per-tenant CSS soubor) — vědomé kvůli sdílené cache; háček: nelze měnit strukturu/spacing per tenant, jen barvy (což je záměr MVP).
- **`tenant_theme` je tenant-scoped, ale čte se i v composeru bez plného request cyklu** — composer běží v tenant kontextu (storefront už tenanta resolvnul), takže scope platí; ověřit v testech izolace.
- Logo na **veřejném** disku (je viditelné na storefrontu) — na rozdíl od faktur (privátní). Žádné tajemství.
