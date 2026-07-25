# Vlna 2.2 — Šablona storefrontu + branding — implementační plán

> **Pro agenta:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Kroky `- [ ]`. TDD kde dává smysl (model, contrast helper, admin controller, validace); u vizuálního přebarvení = aplikuj designový systém + testy na render/izolaci/a11y + neporušení existujících feature testů.

**Cíl:** Dát storefrontu jednu čistou prodejnou šablonu a umožnit nájemci přebrandovat (logo, favicon, primární + akcentní barva) přes admin obrazovku „Vzhled".

**Architektura:** Šablona zůstává Blade SSR v modulu `storefront` (žádný nový JS). Branding = CSS custom properties injektované do `<head>` z tabulky `tenant_theme` přes existující `View::composer`. Tailwind tokeny mapované na proměnné. Admin obrazovka = core tenant route (vzor `BillingProfile`).

**Tech stack:** Laravel 13, Blade, Tailwind v3 (config-based), Inertia/Vue (admin), `FileStorage` (public disk).

**Spec:** `docs/superpowers/specs/2026-07-25-vlna-22-storefront-theme-design.md`

## Global Constraints (verbatim ze specu + pravidel projektu)
- **Storefront = Blade SSR, žádné SPA, žádný nový povinný JS na storefrontu** (`.claude/rules/storefront-rendering.md`). Nákup musí projít bez JS.
- **Cache-safe:** branding je per-tenant, ne per-návštěvník; do cachovaného HTML smí. Žádný osobní obsah.
- **WCAG 2.2 AA:** kontrast textu ≥ 4.5:1; stav nikdy jen barvou; focus stavy viditelné; skip-link zachovat.
- **Tenant izolace:** barvy/logo nájemce A se nesmí zobrazit nájemci B (composer čte z tenant kontextu).
- **Barvy jako CSS proměnné v inline `<style>`**, ne per-tenant CSS build (sdílená cache).
- Kód anglicky, chat/UI česky. Pint na dirty PHP. Testy foreground.

---

## Předpoklady z exploru (load-bearing)
- Layout: `Modules/Storefront/Resources/views/layouts/shop.blade.php` (dnes hardcoded `slate-*`).
- Composer: `Modules/Storefront/Providers/ModuleProvider.php:56` — `View::composer('storefront::layouts.shop', …)` plní `shopName`/`navCategories`/`cartEnabled`/`customerAreaEnabled`/`signedInCustomer`. **Sem přidat `theme`.**
- Storefront views k přebarvení: layout, `home`, `search`, `shop-error`, `errors/404`, `components/{product-card,product-grid,breadcrumbs,sort-form}`; `Categories/…/storefront/show`; `Products/…/storefront/show`; `Checkout/{cart,checkout/shipping,checkout/details,thank-you}`; `Customers/…/storefront/{login,register,password-request,password-reset,account/*}`; `Pages/…/show`.
- CSS: `resources/css/storefront.css` (`@tailwind` direktivy), `tailwind.config.js` (extend). Vite input `resources/css/storefront.css`.
- `FileStorage`: `putPublic(path, contents): string`, `publicUrl(path): string`, `delete(path, private=true)`, `PUBLIC_DISK='tenant_public'`. Upload vzor: `Modules/Products/Http/Controllers/ProductImageAdminController.php`.
- Admin core route vzor: `BillingProfileController` + `routes/tenant.php` + `resources/js/Pages/Tenant/BillingProfile.vue` + nav v `resources/js/Layouts/AdminLayout.vue`.

---

## Task 1: Model `tenant_theme` + migrace + defaulty

**Files:**
- Create: `database/migrations/2026_07_25_000001_create_tenant_theme_table.php`
- Create: `app/Models/TenantTheme.php`
- Test: `tests/Feature/Theme/TenantThemeModelTest.php`

**Interfaces:**
- Produces: `TenantTheme` model s poli `tenant_id`, `logo_path`, `favicon_path`, `primary_color`, `accent_color`; konstanty `TenantTheme::DEFAULT_PRIMARY = '#0f172a'`, `TenantTheme::DEFAULT_ACCENT = '#2563eb'`.

- [ ] **Step 1: Test** — `tests/Feature/Theme/TenantThemeModelTest.php`: vytvoř tenant + `TenantTheme` s `primary_color='#0f766e'`, ověř persist + `belongsTo(tenant)`; ověř že chybějící barvy vrací defaulty přes accessor (`primaryColor()` vrátí `DEFAULT_PRIMARY` když null).
- [ ] **Step 2:** Spusť `php artisan test --filter TenantThemeModel` → FAIL.
- [ ] **Step 3: Migrace** — `php artisan make:migration create_tenant_theme_table --no-interaction`; `Schema::create('tenant_theme')`: `id`, `foreignId('tenant_id')->constrained()->cascadeOnDelete()->unique()`, `string('logo_path')->nullable()`, `string('favicon_path')->nullable()`, `string('primary_color', 9)->nullable()`, `string('accent_color', 9)->nullable()`, `timestamps()`. Model `TenantTheme` (`$guarded=[]`, konstanty defaultů, `tenant()` belongsTo, accessory `primaryColor()`/`accentColor()` s fallbackem na default). **`tenant_theme` je tenant-scoped přes `tenant_id` — přidej do `SchemaConventionTest` očekávání (má `tenant_id`, není v netenantovém allowlistu).**
- [ ] **Step 4:** `php artisan test --filter "TenantThemeModel|Schema"` → PASS.
- [ ] **Step 5: Commit** — `feat(theme): tenant_theme model + migration`

## Task 2: Contrast helper (luminance → text barva)

**Files:**
- Create: `app/Core/Theme/Contrast.php`
- Test: `tests/Unit/Theme/ContrastTest.php`

**Interfaces:**
- Produces: `App\Core\Theme\Contrast::textOn(string $hex): string` (vrátí `#ffffff` nebo `#0f172a` dle luminance pozadí — WCAG relative luminance); `Contrast::ratio(string $hexA, string $hexB): float`.

- [ ] **Step 1: Test** — `textOn('#0f172a')` (tmavá) → `#ffffff`; `textOn('#fde047')` (světle žlutá) → tmavá `#0f172a`; `ratio('#000000','#ffffff')` ≈ 21.0; `ratio('#777777','#ffffff')` < 4.5.
- [ ] **Step 2:** `php artisan test --filter Contrast` → FAIL.
- [ ] **Step 3: Implementace** — `Contrast`: parse hex → sRGB → linearize → relativní luminance (WCAG vzorec); `textOn` vrátí tmavou/světlou dle vyššího kontrastu; `ratio` = `(L1+0.05)/(L2+0.05)`.
- [ ] **Step 4:** `php artisan test --filter Contrast` → PASS.
- [ ] **Step 5: Commit** — `feat(theme): WCAG contrast helper`

## Task 3: Theme resolver + composer wiring (data do layoutu)

**Files:**
- Create: `app/Core/Theme/ThemeData.php` (readonly DTO)
- Create: `app/Core/Theme/ThemeResolver.php`
- Modify: `Modules/Storefront/Providers/ModuleProvider.php` (composer +`theme`)
- Test: `tests/Feature/Theme/ThemeResolverTest.php`, `tests/Feature/Theme/StorefrontThemeIsolationTest.php`

**Interfaces:**
- Consumes: `TenantTheme` (Task 1), `Contrast` (Task 2).
- Produces: `ThemeResolver::forCurrentTenant(): ThemeData`; `ThemeData` readonly s `primary`, `accent`, `primaryContrast`, `?logoUrl`, `?faviconUrl` (všechny stringy, barvy s defaulty).

- [ ] **Step 1: Test** — `ThemeResolverTest`: tenant bez `tenant_theme` → `ThemeData` s defaultními barvami, `logoUrl=null`, `primaryContrast` dopočítané z default primary. Tenant s theme + `logo_path` → `logoUrl` = `FileStorage::publicUrl(...)`, barvy z theme. `StorefrontThemeIsolationTest`: dva tenanti různé barvy → `forCurrentTenant` v `runAs(A)` vrátí barvy A, v `runAs(B)` barvy B.
- [ ] **Step 2:** `php artisan test --filter "ThemeResolver|StorefrontThemeIsolation"` → FAIL.
- [ ] **Step 3: Implementace** — `ThemeResolver::forCurrentTenant()`: načti `TenantTheme` pro `Tenant::current()` (nebo default), sestav `ThemeData` (barvy s fallbackem, `primaryContrast = Contrast::textOn(primary)`, logo/favicon URL přes `FileStorage::publicUrl` když path). Composer v `ModuleProvider` přidá `'theme' => app(ThemeResolver::class)->forCurrentTenant()`.
- [ ] **Step 4:** `php artisan test --filter "ThemeResolver|StorefrontThemeIsolation"` → PASS.
- [ ] **Step 5: Commit** — `feat(theme): ThemeResolver + composer wiring`

## Task 4: Designový systém — Tailwind tokeny + CSS proměnné + `<head>` injektáž

**Files:**
- Modify: `tailwind.config.js` (brand barvy → CSS vars)
- Modify: `resources/css/storefront.css` (komponentní vrstvy: `.btn`, `.btn-primary`, `.card`, `.field`, `.badge`, typografie)
- Modify: `Modules/Storefront/Resources/views/layouts/shop.blade.php` (`<head>`: inline `:root` proměnné z `$theme`, favicon, logo v headeru)
- Test: `tests/Feature/Theme/LayoutThemeRenderTest.php`

**Interfaces:**
- Consumes: `$theme` (ThemeData) z composeru.

- [ ] **Step 1: Test** — `LayoutThemeRenderTest`: GET homepage tenanta s `primary_color='#0f766e'` → HTML obsahuje `--brand-primary: #0f766e` a `--brand-primary-contrast:`; s logem → `<img` s logo URL + `alt` = název e-shopu; bez loga → textový název. Ověř, že žádný `<script src>` na framework (storefront rule) nepřibyl.
- [ ] **Step 2:** `php artisan test --filter LayoutThemeRender` → FAIL.
- [ ] **Step 3: Implementace:**
  - `tailwind.config.js` extend `colors`: `brand: { DEFAULT: 'var(--brand-primary)', contrast: 'var(--brand-primary-contrast)' }`, `accent: 'var(--brand-accent)'`. Nech `content` beze změny.
  - `resources/css/storefront.css` v `@layer components`: `.btn` (base padding/radius/focus-visible ring), `.btn-primary { background: var(--brand-primary); color: var(--brand-primary-contrast); }`, `.btn-outline`, `.card` (bílá, jemný border+stín, radius), `.field` (input/label styl přes @tailwindcss/forms), `.badge`, `.prose-shop` typografie. Čistý styl: štědré mezery, `rounded-lg`, jemné `shadow-sm`, neutrální šedá + brand akcenty.
  - `shop.blade.php` `<head>`: `<style>:root{ --brand-primary: {{ $theme->primary }}; --brand-primary-contrast: {{ $theme->primaryContrast }}; --brand-accent: {{ $theme->accent }}; }</style>`; `@if($theme->faviconUrl)<link rel="icon" href="{{ $theme->faviconUrl }}">@endif`. Header: `@if($theme->logoUrl)<img src alt="{{ $shopName }}">@else {{ $shopName }} @endif`. **Escapuj barvy** (jsou validované hex, ale i tak `{{ }}`).
- [ ] **Step 4:** `php artisan test --filter LayoutThemeRender` → PASS; `npm run build` OK.
- [ ] **Step 5: Commit** — `feat(theme): design tokens + brand CSS vars + head injection`

## Task 5: Přebarvení chrome + sdílených komponent

**Files:**
- Modify: `Modules/Storefront/Resources/views/layouts/shop.blade.php` (header/footer/nav → design systém)
- Modify: `Modules/Storefront/Resources/views/components/{product-card,product-grid,breadcrumbs,sort-form}.blade.php`
- Test: existující storefront feature testy (nesmí spadnout) + `tests/Feature/Theme/ComponentThemeTest.php`

- [ ] **Step 1: Test** — `ComponentThemeTest`: render product-card v gridu (přes homepage nebo kategorii) → obsahuje `.card` a `.btn` třídy (design systém aplikován), cena a název produktu v HTML (neztratit obsah, storefront rule). Breadcrumbs mají `aria-label`.
- [ ] **Step 2:** `php artisan test --filter ComponentTheme` → FAIL.
- [ ] **Step 3: Implementace** — přepiš hardcoded `slate-*`/`bg-slate-900` v layoutu (header/nav/footer, hledací pole, tlačítka) a v komponentách na designový systém (`.btn-primary`, `.card`, brand akcenty, konzistentní mezery). Zachovej sémantiku (WCAG skip-link, `role="search"`, `aria-label` nav), žádný nový JS. Logo/název už z Task 4.
- [ ] **Step 4:** `php artisan test --filter "ComponentTheme|Storefront|Products|Categories"` → PASS; `npm run build` OK.
- [ ] **Step 5: Commit** — `feat(theme): restyle layout chrome + shared components`

## Task 6: Přebarvení homepage, kategorie, hledání, detail produktu

**Files:**
- Modify: `Modules/Storefront/Resources/views/{home,search,shop-error,errors/404}.blade.php`
- Modify: `Modules/Categories/Resources/views/storefront/show.blade.php`
- Modify: `Modules/Products/Resources/views/storefront/show.blade.php`
- Test: existující feature testy těchto stránek + smoke render.

- [ ] **Step 1: Test** — rozšiř/přidej assertions: GET `/produkt/{slug}` a `/kategorie/{slug}` a `/` a `/hledani?q=` vrací 200, obsahuje název/cenu produktu v surovém HTML (storefront rule), používá `.btn`/`.card`. (Reuse existující testy, přidej třídní assertion kde chybí.)
- [ ] **Step 2:** Spusť dotčené filtry → FAIL na nových assertions.
- [ ] **Step 3: Implementace** — přebarvi tyto view na designový systém: homepage (jednoduchý úvod + grid produktů — POZOR: bloková homepage je 2.3, teď jen čistý default: název e-shopu, mřížka posledních/nabízených produktů, odkaz do katalogu), detail produktu (galerie placeholder + info + „do košíku" tlačítko `.btn-primary`, parametry, popis `.prose-shop`), výpis kategorie (grid + řazení), hledání, chybové stránky. Cenová logika a data beze změny, jen vzhled.
- [ ] **Step 4:** dotčené testy + `npm run build` → PASS.
- [ ] **Step 5: Commit** — `feat(theme): restyle home, category, search, product detail`

## Task 7: Přebarvení košík + pokladna + děkovná + účet + auth + statické

**Files:**
- Modify: `Modules/Checkout/Resources/views/{cart,checkout/shipping,checkout/details,thank-you}.blade.php`
- Modify: `Modules/Customers/Resources/views/storefront/{login,register,password-request,password-reset}.blade.php` + `account/*.blade.php`
- Modify: `Modules/Pages/Resources/views/show.blade.php`
- Test: existující checkout/customers feature testy (kritické — nákup bez JS musí projít) + smoke.

- [ ] **Step 1: Test** — reuse existující checkout feature testy (přidej assertion na `.btn`/`.card` kde smysluplné); klíčové: **nákupní tok bez JS stále projde** (existující testy to ověřují — nesmí spadnout). Auth formuláře mají labely (WCAG).
- [ ] **Step 2:** Spusť `--filter "Checkout|Customer|Cart|Order"` → zjisti baseline (měly by projít i teď; nové třídní assertions FAIL).
- [ ] **Step 3: Implementace** — přebarvi košík (tabulka položek `.card`, souhrn, tlačítka), pokladnu (formuláře `.field`, radio doprava/platba přehledně, souhrn), děkovnou stránku, účet zákazníka (přehled/objednávky/adresy/profil), auth stránky, statickou stránku (`.prose-shop`). **Zachovej:** všechny formuláře funkční bez JS, žádná cenová logika v JS, potvrzovací dialogy (mazání adresy zůstává GET stránka).
- [ ] **Step 4:** `--filter "Checkout|Customer|Cart|Order|Pages"` → PASS; `npm run build` OK.
- [ ] **Step 5: Commit** — `feat(theme): restyle cart, checkout, account, auth, static pages`

## Task 8: Admin — AppearanceController + routes + FormRequest + upload

**Files:**
- Create: `app/Http/Controllers/Tenant/AppearanceController.php`
- Create: `app/Http/Requests/Tenant/UpdateAppearanceRequest.php`
- Modify: `routes/tenant.php`
- Test: `tests/Feature/Tenant/AppearanceControllerTest.php`

**Interfaces:**
- Consumes: `TenantTheme` (Task 1), `FileStorage`, `Contrast` (Task 2).

- [ ] **Step 1: Test** — `AppearanceControllerTest`: `GET /admin/nastaveni/vzhled` (jako owner) → 200 Inertia `Tenant/Appearance` s aktuálními barvami/logem. `POST` s `primary_color='#0f766e'`, `accent_color='#2563eb'` → uloží do `tenant_theme` (updateOrCreate), redirect s flash. `POST` s upload loga (`UploadedFile::fake()->image('logo.png')`) → `logo_path` set, soubor na public disku. Validace: nevalidní hex (`'red'`) → 422. `DELETE .../logo` → smaže soubor + vynuluje `logo_path` (potvrzení řeší UI). Auth: nečlen → redirect/403. Suspended tenant `POST` → write-freeze 503. **Tenant izolace:** owner A nemůže měnit theme B (route bez id, bere z kontextu — ověř).
- [ ] **Step 2:** `php artisan test --filter AppearanceController` → FAIL.
- [ ] **Step 3: Implementace:**
  - `routes/tenant.php` (vzor `admin.billing.*`): `GET admin.appearance.edit`, `POST admin.appearance.update`, `DELETE admin/nastaveni/vzhled/logo → admin.appearance.logo.destroy`, `DELETE .../favicon → admin.appearance.favicon.destroy`.
  - `UpdateAppearanceRequest`: `primary_color`/`accent_color` `['required','regex:/^#[0-9a-fA-F]{6}$/']`; `logo`/`favicon` `['nullable','image','max:512']` (KB); `authorize()=true`.
  - `AppearanceController`: `edit()` → `Inertia::render('Tenant/Appearance', [theme dto + contrast ratio])`. `update()` → `updateOrCreate` theme pro `context->current()`, upload přes `FileStorage::putPublic("theme/logo.<ext>", contents)`, ulož path; `back()->with('success', …)`. `destroyLogo()`/`destroyFavicon()` → `FileStorage::delete(path, private:false)` + null path.
- [ ] **Step 4:** `php artisan test --filter AppearanceController` → PASS.
- [ ] **Step 5: Commit** — `feat(theme): admin appearance controller + routes + upload`

## Task 9: Admin UI — `Appearance.vue` + nav + a11y

**Files:**
- Create: `resources/js/Pages/Tenant/Appearance.vue`
- Modify: `resources/js/Layouts/AdminLayout.vue` (nav odkaz „Vzhled")
- Test: build + `AppearanceControllerTest` (Inertia assert) + a11y-checker

- [ ] **Step 1: Test** — rozšiř `AppearanceControllerTest` o `assertInertia` (komponenta `Tenant/Appearance`, prop barvy/logo/contrastRatio). (Vue rendering se netestuje unitově.)
- [ ] **Step 2:** `php artisan test --filter AppearanceController` → FAIL na Inertia assertion.
- [ ] **Step 3: Implementace** — `Appearance.vue` (vzor `BillingProfile.vue`, `AdminLayout`, `useForm`): color picker (`<input type="color">` + hex text) pro primární + akcentní; upload loga + favicon (náhled, mazání s `ConfirmDialog`); **živé varování kontrastu** (spočítej ratio v JS z primární barvy vůči bílé, když < 4.5 zobraz varování — nebo použij `contrastRatio` prop ze serveru a přepočítej při změně); odkaz „Zobrazit e-shop" (storefront URL); server chyby ve `form.errors`. Nav odkaz „Vzhled" do `AdminLayout.vue` (vedle „Doména"/statických odkazů). Po hotové stránce spusť **a11y-checker** na `Appearance.vue`, oprav Critical/Important.
- [ ] **Step 4:** `php artisan test --filter AppearanceController` → PASS; `npm run build` OK; a11y čistý.
- [ ] **Step 5: Commit** — `feat(theme): admin appearance screen + nav + a11y`

## Task 10: Uzavření

- [ ] **Step 1:** Plná sada: `php artisan test --compact` (foreground) → vše zelené.
- [ ] **Step 2:** Storefront-rendering checklist (curl): `curl -s <produkt> | grep` cena/název v surovém HTML; průchod nákupem s vypnutým JS; canonical/OG beze změny.
- [ ] **Step 3:** `docs/as-is/2026-07-25-storefront-theme.md` (mapa změn, plnění spec, odchylky, tech dluh) + řádek do `docs/as-is/STATUS.md`.
- [ ] **Step 4:** CLAUDE.md: rozhodnutí (CSS proměnné pro branding, `tenant_theme` core tabulka) + status řádek vlny 2.2. Aktualizuj `docs/DEMO-URLS.md` o `/admin/nastaveni/vzhled`.
- [ ] **Step 5:** `/finish-wave` (docs + minor bump + merge + push) — po schválení.

---

## Rizika a mitigace
- **Vizuální regrese / rozbití nákupu bez JS:** každý restyle task běží existující feature testy dané oblasti; nákupní tok je krytý checkout/order testy — nesmí spadnout. Curl + JS-off kontrola v Task 10.
- **Nízký kontrast brand barvy:** `--brand-primary-contrast` dopočítané (Task 2) drží text čitelný; admin varuje při zadání.
- **Cache stale po změně barvy:** branding je v HTML z composeru (ne separátní cache); page cache (zatím neexistuje) by musela klíčovat per-tenant — už dnes klíčuje per-tenant+path, takže OK. Poznámka do as-is.
- **Tenant izolace:** `ThemeResolver` čte `Tenant::current()`; test izolace v Task 3 + admin izolace v Task 8.

## Mimo rozsah (YAGNI)
Bloková homepage / page builder = vlna 2.3. Další šablony (marketingová, technická) = `docs/future/2026-07-25-dalsi-storefront-sablony.md`. Vlastní fonty, dark mode storefrontu, theme marketplace.
