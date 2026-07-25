# As-is — Šablona storefrontu + branding nájemce (vlna 2.2)

Datum: **2026-07-25** · Branch: `feat/wave-2.2-storefront-theme` · Spec: [`docs/superpowers/specs/2026-07-25-vlna-22-storefront-theme-design.md`](../superpowers/specs/2026-07-25-vlna-22-storefront-theme-design.md) · Plán: [`docs/superpowers/plans/2026-07-25-vlna-22-storefront-theme.md`](../superpowers/plans/2026-07-25-vlna-22-storefront-theme.md)

## Co vlna přináší

Storefront dostal **jednu čistou prodejnou šablonu** (místo holé) a nájemce si e-shop **přebranduje** — logo, favicon, primární + akcentní barva. Konzistentní vzhled přes všechny veřejné stránky, pořád **Blade SSR bez JS** (nákup projde bez JavaScriptu).

## Mapa kódu

### Data + jádro
- `database/migrations/..._create_tenant_theme_table.php` + `app/Models/TenantTheme.php` — 1:1 s tenantem: `logo_path`, `favicon_path`, `primary_color`, `accent_color`; konstanty `DEFAULT_PRIMARY`/`DEFAULT_ACCENT`, accessory s fallbackem. Bez `BelongsToTenant` scope (čte se z `Tenant::current()`).
- `app/Core/Theme/Contrast.php` — WCAG relative luminance: `ratio()`, `textOn()` (dopočítá čitelnou barvu textu na pozadí).
- `app/Core/Theme/ThemeData.php` (readonly DTO) + `ThemeResolver.php` — `forCurrentTenant()` sestaví barvy (s defaulty), `primaryContrast`, logo/favicon URL. **`sanitizeHex()` (regex `/^#[0-9a-fA-F]{6}$/D`) odmítne ne-hex → default** — obrana proti CSS injekci před vstupem do inline `<style>`.

### Storefront (Blade SSR)
- `Modules/Storefront/Providers/ModuleProvider.php` — composer na `storefront::layouts.shop` rozšířen o `theme` (jediné místo, běží na každé stránce).
- `Modules/Storefront/Resources/views/layouts/shop.blade.php` — `<head>` inline `<style>` s brand CSS proměnnými z `$theme`, favicon link, logo v headeru.
- `tailwind.config.js` — brand tokeny (`brand`, `brand-contrast`, `accent`) mapované na CSS proměnné.
- `resources/css/storefront.css` — komponentní vrstva (`.btn`/`.btn-primary`/`.btn-outline`/`.card`/`.field-*`/`.badge`/`.prose-shop`).
- Přebarveny **všechny** storefront views: chrome + komponenty (`product-card`/`product-grid`/`breadcrumbs`/`sort-form`), home, detail produktu, kategorie, hledání, chyby, košík, pokladna (doprava/údaje), děkovná, účet zákazníka, auth (login/register/reset), statická stránka.

### Admin
- `app/Http/Controllers/Tenant/AppearanceController.php` + `app/Http/Requests/Tenant/UpdateAppearanceRequest.php` + `routes/tenant.php` — obrazovka „Vzhled" (`/admin/nastaveni/vzhled`): barvy (hex validace `/D`), upload loga (`image`, max 512 kB) a favicon (`mimes:png,ico`, max 128 kB), mazání. Izolace přes `context->current()` (žádné id z requestu), write-freeze pro suspended.
- `resources/js/Pages/Tenant/Appearance.vue` — color pickery + hex, živé varování kontrastu (stejný vzorec jako PHP `Contrast`), náhled + upload + mazací dialog, „Zobrazit e-shop". Nav odkaz „Vzhled" v `AdminLayout.vue`.

## Plnění spec
- Designový systém (čistý styl) — **hotovo**.
- Přebarvení všech storefront stránek — **hotovo**.
- Branding nájemce (logo/favicon/barvy → CSS proměnné) — **hotovo**.
- Admin „Vzhled" + kontrola kontrastu — **hotovo**.

## Testy
1140 testů celkem (3752 assertions), zeleně. Nové sady: `TenantThemeModelTest`, `ContrastTest`, `ThemeResolverTest`, `StorefrontThemeIsolationTest`, `LayoutThemeRenderTest`, `ComponentThemeTest`, `AppearanceControllerTest`. Storefront-rendering checklist pokrytý automaticky (žádný framework JS na storefrontu; cena/název v surovém HTML; nákupní tok bez JS drží — existující checkout/customers testy). Bezpečnost: CSS-injection guard (sanitizeHex `/D`), tenant izolace barev, upload safety, write-freeze, SVG favicon odmítnut.

## Odchylky a vědomé kompromisy
1. **Barvy jako CSS proměnné v inline `<style>`**, ne per-tenant CSS build — sdílená cache, cache-safe. Lze měnit jen barvy, ne strukturu/spacing per tenant (záměr MVP).
2. **`tenant_theme` samostatná tabulka** (ne sloupce na `tenants`, ne `SettingsService`) — prezentace je jádrová vrstva, ne modulový setting.
3. **CSS-injection obrana na resolveru** (`sanitizeHex`), ne jen na admin vstupu — defense-in-depth; ať do `<style>` nikdy nevstoupí ne-hex, bez ohledu na zdroj (DB/admin).
4. **Logo na veřejném disku** (`tenant_public`) — je viditelné na storefrontu, není tajné.
5. **Favicon jen `png,ico`** (ne SVG) — SVG servírované jako `image/svg+xml` je stored-XSS vektor (stejná politika jako raster-only product images).

## Tech dluh / follow-up
- **`Modules/Pages/Resources/views/show.blade.php` je standalone** mimo `storefront::layouts.shop` (pre-existing) — statické stránky (VOP/GDPR/kontakt) nemají chrome/branding. Wiring vyžaduje `PagesController` `$seo`/nav. Řešit v pre-launch/legal vlně.
- **Platform-host 404** (tenant=null) zůstává holé HTML — bez tenanta není shop CSS bundle.
- **Aktivní kategorie v nav** bez `aria-current` markeru — vyžaduje předat current-category do sdíleného composeru (coupling); odloženo.
- **Bloková homepage / page builder** = vlna 2.3. Další šablony (marketingová, technická) = `docs/future/2026-07-25-dalsi-storefront-sablony.md`.

## Před spuštěním (souvisí s brandingem)
- Nájemce nastaví logo/favicon/barvy v `/admin/nastaveni/vzhled`.
- Bez nastavení běží neutrální default (tmavě modrošedá primární).
