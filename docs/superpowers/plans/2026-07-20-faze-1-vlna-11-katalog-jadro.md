# Fáze 1 / vlna 1.1 — Jádro katalogu (`categories` + `products`) — implementační plán

> **STAV: dokončeno 2026-07-20**, verze 0.8.0, větev `feat/catalog-core`.
> Bloky A–J hotové, 443 testů zelených. Skutečný stav a odchylky:
> [`docs/as-is/2026-07-20-katalog-jadro.md`](../../as-is/2026-07-20-katalog-jadro.md).
>
> Odchylky proti tomuto plánu (detail v as-is): převody DPH sedí na `TaxRate`,
> ne na `Money` (krok B4); Inertia stránky modulů leží v core stromu;
> soft-deleted produkt si nechává soubory obrázků; sanitizace HTML je vlastní,
> bez nové závislosti.

> **Pro agenta:** superpowers:executing-plans / subagent-driven-development. Kroky `- [x]` po dokončení.

**Cíl:** Nájemce se přihlásí do vlastního adminu, založí strom kategorií a produkty s cenami, DPH, skladem, obrázky a SEO poli. Ostatní moduly (košík, objednávky) čtou katalog výhradně přes kontrakt `ProductCatalog`, nikdy z tabulek.

**Architektura:** Dva moduly nad existujícím systémem modulů — `categories` (base, bez závislostí) a `products` (base, `requires: categories`). Admin = Inertia/Vue SPA pod `/admin/m/{modul}` (oddíl C pravidla `storefront-rendering.md`, vše `noindex`). Storefront rendering **není součástí této vlny** — přijde ve vlně 1.2, katalog zatím žádné veřejné routy nepublikuje.

Do jádra patří tři věci, které modul nesmí vlastnit: číselník sazeb DPH (spec §6.2 „číselník spravuje jádro"), tabulka `redirects` (spec §15.3, sdílí ji kategorie i produkty i budoucí moduly) a autorizační vrstva tenant adminu.

**Tech stack:** Laravel 13, PHP 8.3, Inertia 3 + Vue 3.5, Tailwind 3.4, PHPUnit 12, MySQL 8 + Redis.

**Spec:** §6.2, §6.3, §16.1, §16.2, §15.3 · Navazuje na [systém modulů](../../as-is/2026-07-19-system-modulu.md), [kernel služby](../../as-is/2026-07-19-kernel-sluzby.md), [FileStorage](../../as-is/2026-07-19-filestorage.md).

**Role/viditelnost:** Admin katalogu výhradně `TENANT_ADMIN` (`TenantRole::Owner`; `Staff` je fáze 2, ale oprávnění se kontrolují už teď). Nákupní cena jen s právem `products.costs`. `SUPERADMIN` se dostane dovnitř jen impersonací. Storefront (veřejné čtení) v této vlně nevzniká.

---

## Rozhodnutí přijatá před plánem

- **Strom kategorií vlastní implementací** (`parent_id` + `position` + `path`), ne `staudenmeir/laravel-adjacency-list`. Max hloubka 4 podle §16.2 dělá rekurzi levnou a balíčkové CTE dotazy by obcházely `TenantScope`.
- **Bez variant, bez CSV importu, bez image cuts jobů, bez hromadných operací** — vlna 1.2 a dál.
- Produkt má **jednu cenu s DPH jako primární vstup**, bez DPH se dopočítává (§16.1).

---

## Bezpečnostní jádro (čte se první)

1. **Admin routy modulů dnes nemají `auth`.** `ModuleRouteRegistrar::mountAdmin()` (`app/Core/Modules/ModuleRouteRegistrar.php:56`) montuje pod middleware `['web', 'module:{key}']`. Kdokoli anonymní může volat admin controller libovolného modulu. Platí to i pro už nasazený `Modules/Pages`. Blok A to opravuje jako první věc — bez toho je celá vlna děravá.
2. **Oprávnění z `module.json` nikdo nevynucuje.** Manifest deklaruje `permissions`, ale žádná Gate je nečte. Blok A zavádí `TenantPermissions` + Gate; `products.costs` je první právo, které reálně něco skryje.
3. **Slug je unikátní per tenant, ne globálně** — unikátní index musí být složený `(tenant_id, slug)`, jinak první tenant se slugem `iphone-15` zablokuje všechny ostatní. Stejná past u SKU.
4. **Cenu počítá jen server.** Dopočet ceny bez DPH ve Vue je pohodlí pro editaci v adminu; závazná hodnota vzniká v `Money` na serveru a klientský vstup se zahazuje.
5. **Sklad se nikdy nemění `update()`em načteného modelu.** Kontrakt `decrementStock` musí být atomický (`UPDATE ... SET stock_qty = stock_qty - ? WHERE id = ? AND stock_qty >= ?`), jinak AK §6.2 „žádný oversell při souběhu" neplatí. Implementuje se už teď, i když volající (košík) přijde později.
6. **Upload obrázků jen přes `FileStorage`.** Whitelist MIME jpg/png/webp, limit 8 MB/soubor, jméno souboru se generuje (nikdy nepoužít `getClientOriginalName()`), `PathGuard` na cestu. Odečet z limitu `storage_mb` už `StorageLimitCounter` umí.
7. **Popis produktu je WYSIWYG = XSS vektor.** HTML se sanitizuje **při zápisu** whitelistem tagů, ne až při renderu. Bez sanitizace se v Blade použije `{!! !!}` a tenant si do vlastního e-shopu vloží skript.
8. **Smazání produktu = soft delete** (§16.1) — objednávky drží snapshot, ale FK musí zůstat validní.
9. **Smazání kategorie s produkty** vyžaduje dialog „přesunout produkty do…" (CLAUDE.md: každá mazací akce má potvrzení).
10. **Cyklus v `parent_id` musí být nemožný** — validace kontroluje, že nový rodič není potomkem sebe sama.
11. **Limit `products`** se kontroluje `LimitsService::check('products')` **před** zápisem, ne po něm.

---

## Kroky

### A. Autorizace tenant adminu (jádro, blokuje vše ostatní)

- [x] A1. Test `ModuleAdminRouteTest`: anonymní GET na `/admin/m/pages` vrátí redirect na login (dnes vrací 200 — červený test dokazuje díru); přihlášený uživatel jiného tenanta dostane 403/404; owner tenanta projde.
- [x] A2. Rozšířit `mountAdmin()` o `['web', 'auth', 'tenant.member', 'module:'.$key]`. Nový middleware `EnsureTenantMember` — uživatel musí patřit k aktuálnímu tenantovi (`TenantContext`).
- [x] A3. `app/Core/Auth/TenantPermissions.php` — čte `permissions` z manifestů aktivních modulů, mapuje na roli (`Owner` = vše, `Staff` = fáze 2, zatím prázdná množina). Registrace Gate v `AuthServiceProvider`.
- [x] A4. Test na `products.costs`: owner ho má, staff ne.
- [x] A5. Zeleně. Commit `fix: require authentication on module admin routes`.

### B. Číselník sazeb DPH (jádro)

- [x] B1. Test `TaxRateTest`: sazby jsou globální (bez `tenant_id`), obsahují 21/12/0 %, `TaxRates::default()` vrací základní sazbu; výpočet ceny bez DPH z ceny s DPH je haléřově správný přes `Money`.
- [x] B2. Migrace `tax_rates` (`code`, `name`, `rate_permille` jako integer — ne float), seeder s CZ sazbami.
- [x] B3. `app/Core/Tax/TaxRates.php` — `all()`, `find()`, `default()`, cache. `TaxRate` model bez `BelongsToTenant`.
- [x] B4. `Money::netFromGross(TaxRate)` / `grossFromNet()` — zaokrouhlení na haléře, dokumentovaná strategie.
- [x] B5. Zeleně. Commit `feat: add core VAT rate registry`.

### C. Tabulka `redirects` (jádro)

- [x] C1. Test `RedirectsTest`: zápis 301 při změně slugu, žádné duplicity, cyklus A→B→A se zkrátí (nový záznam přepíše starý cíl), smazání entity nechá redirect žít.
- [x] C2. Migrace `redirects` (`tenant_id`, `from_path`, `to_path`, `status`, unikát `(tenant_id, from_path)`).
- [x] C3. `app/Core/Routing/RedirectRegistry.php` — `record(from, to)`, `resolve(path)`. Middleware, který nezmapovanou cestu zkusí přesměrovat, přijde s vlnou storefrontu; teď stačí zápis a čtení.
- [x] C4. Zeleně. Commit `feat: add tenant redirect registry`.

### D. Admin shell nájemce

- [x] D1. `resources/js/Layouts/AdminLayout.vue` — hlavička, boční navigace z `NavigationBuilder` (moduly aktivní pro tenanta), jméno uživatele, odhlášení, lišta impersonace, `noindex`, slot flash zpráv.
- [x] D2. Sdílená data v `HandleInertiaRequests`: aktuální tenant, navigace, oprávnění uživatele.
- [x] D3. Znovupoužít komponenty z `Platform/` — přesunout `DataTable`, `Pagination`, `ConfirmDialog`, `FilterBar` do `resources/js/Components/Ui/`, `Platform/*` na ně napojit (žádná duplicitní sada).
- [x] D4. Přepsat `Modules/Pages` admin na nový layout — důkaz, že shell není šitý na katalog.
- [x] D5. A11y kontrola agentem `a11y-checker`.
- [x] D6. Commit `feat: add tenant admin shell`.

### E. Modul `categories` — datový model a služba

- [x] E1. Testy `CategoryTreeTest`: vložení kořene a potomka, `path` se přepočítá; přesun podstromu přepočítá `path` všem potomkům; cyklus je odmítnut; hloubka > 4 je odmítnuta; kategorie tenanta A není vidět z tenanta B.
- [x] E2. `Modules/Categories/module.json` (base, `requires: {}`, práva `categories.view`, `categories.edit`, nav položka).
- [x] E3. Migrace `categories` (`tenant_id`, `parent_id`, `name`, `slug`, `path`, `depth`, `position`, `description`, `image_path`, `is_visible`, `seo_title`, `seo_description`, `seo_image_path`, unikát `(tenant_id, slug)`).
- [x] E4. Model `Category` + `CategoryTree` služba (`move`, `reorder`, `breadcrumbs`, `descendants`). Změna slugu zapíše 301 přes `RedirectRegistry`.
- [x] E5. Zeleně. Commit `feat: add category tree model`.

### F. Modul `categories` — admin UI

- [x] F1. Feature testy: index vrátí strom; store/update/destroy vyžadují právo; smazání kategorie s produkty bez cíle přesunu selže validací.
- [x] F2. `CategoryAdminController` + Form Requesty, routy v `routes/admin.php`.
- [x] F3. Vue: strom s přesunem (klávesnicí ovladatelný — drag&drop nesmí být jediná cesta, WCAG 2.2 AA), karta kategorie, potvrzovací dialog mazání s výběrem cílové kategorie.
- [x] F4. A11y kontrola. Commit `feat: add category admin screens`.

### G. Modul `products` — datový model a kontrakt

- [x] G1. Testy `ProductTest`: slug se generuje z názvu, kolize dostane suffix `-2`; cena bez DPH sedí na haléř; `decrementStock` je atomický (souběžný test dvou dekrementů nepřetáhne sklad pod nulu); soft delete nechá produkt v DB; izolace tenantů.
- [x] G2. `Modules/Products/module.json` (base, `requires: {categories: "^1.0"}`, práva `products.view`, `products.edit`, `products.costs`).
- [x] G3. Migrace: `manufacturers`, `products` (dle §6.2 + `deleted_at`, `stock_alert_qty`), `product_images`, `product_category` (M:N s příznakem `is_primary`).
- [x] G4. Modely + `ProductCatalog` kontrakt v `app/Core/Contracts/` (jádro vlastní rozhraní, modul implementaci) — `findBySlug`, `search`, `decrementStock`, `price`. Řetěz `PriceModifier` zatím prázdný, ale bod rozšíření existuje.
- [x] G5. `ProductsLimitCounter implements LimitCounter` → registrace limitu `products`.
- [x] G6. Sanitizace HTML popisu (whitelist tagů) při zápisu.
- [x] G7. Zeleně. Commit `feat: add product catalog model and contract`.

### H. Modul `products` — obrázky

- [x] H1. Testy: upload uloží soubor přes `FileStorage`, odmítne špatný MIME i soubor nad 8 MB, respektuje limit `storage_mb`, smazání produktu smaže soubory, obrázek tenanta A není dostupný z tenanta B.
- [x] H2. `ProductImageService` — upload, řazení, alt text, hlavní obrázek. Bez generování řezů (vlna 1.2), zatím se servíruje originál.
- [x] H3. Commit `feat: add product image uploads`.

### I. Modul `products` — admin UI

- [x] I1. Feature testy: seznam s filtry a stránkováním po 50; karta produktu uloží všechny záložky; nákupní cena není v odpovědi bez práva `products.costs`; překročený limit `products` vrátí čitelnou chybu.
- [x] I2. `ProductAdminController` + Form Requesty (validace dle §16.1: cena ≥ 0, slug `[a-z0-9-]{1,190}`, EAN 8/13 s checksum jako warning, hmotnost 0–200 000 g).
- [x] I3. Vue: seznam (fulltext, filtry stav/kategorie/výrobce/skladem, řazení) + karta se záložkami Základní / Ceny / Obrázky / Sklad / SEO.
- [x] I4. A11y kontrola. Commit `feat: add product admin screens`.

### J. Uzavření vlny

- [x] J1. Celá sada testů zeleně na MySQL 8 + Redis.
- [x] J2. `./vendor/bin/pint` na dotčené soubory, `npm run build`.
- [x] J3. As-is `docs/as-is/2026-07-20-katalog-jadro.md` + aktualizace `STATUS.md` (včetně poznámky, že díra v admin routách modulů se týkala i `Pages`).
- [x] J4. `VERSION` → 0.8.0, `CHANGELOG.md` (skill `versioning`).
- [x] J5. Merge do `main` po potvrzení uživatelem.

---

## Rizika

- **Blok D (shell) se může rozlézt.** Pokud se ukáže, že přesun komponent z `Platform/` do `Ui/` rozbíjí superadmin, udělat shell minimální a sjednocení komponent odložit — vlna nesmí uváznout na refaktoru cizí obrazovky.
- **Sanitizace HTML** může chtít balíček (`ezyang/htmlpurifier` nebo `mews/purifier`). Nová závislost = dotaz uživateli, ne tiché doinstalování.
- **Test atomického dekrementu** potřebuje souběh; na jednom PHP procesu se dá simulovat jen dvěma transakcemi. Pokud test nebude průkazný, doložit alespoň, že se generuje jediný `UPDATE` s podmínkou.
