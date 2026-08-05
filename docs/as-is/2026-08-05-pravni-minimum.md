# As-is: právní minimum platformy — vlna 3.2

Datum: 2026-08-05 · Verze: **0.37.0** (minor otevírá vlnu 3.2) · Větev: `feature/vlna-32-pravni-minimum`

Spec: [`docs/superpowers/specs/2026-08-05-vlna-32-pravni-minimum-design.md`](../superpowers/specs/2026-08-05-vlna-32-pravni-minimum-design.md)
Plán: [`docs/superpowers/plans/2026-08-05-vlna-32-pravni-minimum.md`](../superpowers/plans/2026-08-05-vlna-32-pravni-minimum.md)

## Co vlna přinesla

Platforma byla funkčně hotová a chybělo jí to, co je mimo kód. `docs/legal/` bylo prázdné, registrace nezaznamenávala žádný souhlas a nový e-shop dostal tři **prázdné nepublikované** stránky bez vodítka, co do nich napsat.

Vlna dodává:

1. **Čtyři právní dokumenty** platformy jako Markdown v `docs/legal/` a jako renderované stránky pod `/pravni/*`.
2. **Prokazatelný souhlas nájemce** při registraci — datum i verze znění.
3. **Vzory právních stránek nájemce** místo prázdných.
4. **Editor stránek**, bez kterého by byl bod 3 mrtvý (viz Rozšíření rozsahu).
5. **Odkazy** na publikované stránky z patičky a z pokladny.

## Mapa změn

42 souborů, +3114/−51 (`git diff --stat 76d7e1c..HEAD`).

| Soubor | Změna |
|--------|-------|
| `docs/legal/README.md` | rozcestník, vysvětlení dvojí GDPR role, verzování, stav revize |
| `docs/legal/vseobecne-obchodni-podminky.md` | VOP platformy vůči nájemci |
| `docs/legal/zasady-zpracovani-osobnich-udaju.md` | informační povinnost (my správce vůči nájemci) |
| `docs/legal/zpracovatelska-smlouva.md` | DPA podle čl. 28 GDPR (my zpracovatel vůči zákazníkům nájemce) |
| `docs/legal/zasady-cookies.md` | cookies platformy |
| `config/legal.php` | **nový** — `terms_version`, `effective_from` |
| `config/billing.php` | + `company.email` (kanál pro uplatnění práv na právních stránkách) |
| `database/migrations/…_add_terms_acceptance_to_users_table.php` | `users.terms_accepted_at`, `users.terms_version` |
| `app/Models/User.php` | cast + `$fillable` |
| `app/Http/Controllers/LegalController.php` | **nový** — mapa slug → view, server-authoritative |
| `resources/views/legal/layout.blade.php` + 4 šablony | **nové** — samostatný layout bez Vite |
| `routes/web.php` | `/pravni/{document}` za `platform.host` |
| `app/Http/Requests/Auth/RegisterRequest.php` | **nový** — validace registrace včetně `terms` |
| `app/Http/Controllers/Auth/RegisteredUserController.php` | zapisuje souhlas |
| `resources/js/Pages/Auth/Register.vue` | checkbox se souhlasem + **počeštění** (byl Breeze default) |
| `Modules/Pages/Support/PageTemplates.php` | **nový** — tři vzory s `[DOPLŇTE …]` |
| `Modules/Pages/Lifecycle.php` | seeduje vzory místo prázdna |
| `Modules/Pages/Services/PageWriter.php` | **nový** — sanitizace, unikátní slug, 301 |
| `Modules/Pages/Http/Requests/PageRequest.php` | **nový** |
| `Modules/Pages/Http/Controllers/PageAdminController.php` | z read-only na CRUD |
| `Modules/Pages/routes/admin.php` | create/store/edit/update/destroy |
| `Modules/Pages/Models/Page.php` | **odebráno** `getRouteKeyName()` |
| `Modules/Pages/Providers/ModuleProvider.php` | **nový** — sdílí publikované stránky dvěma views |
| `resources/js/Pages/Modules/Pages/{Index,Form}.vue` | seznam s akcemi + editor |
| `Modules/Storefront/Providers/ModuleProvider.php` | `footerPages` odsud odebráno (vlastní je modul pages) |
| `Modules/Storefront/Resources/views/layouts/shop.blade.php` | odkazy v patičce |
| `Modules/Checkout/Resources/views/checkout/details.blade.php` | odkazy v souhlasu |
| `tests/Feature/Legal/{LegalPagesTest,TermsAcceptanceTest}.php` | **nové** |
| `tests/Feature/Modules/Pages/{PageAdminTest,PageTemplatesTest}.php` | **nové** |
| `tests/Feature/Storefront/FooterLegalLinksTest.php` | **nové** |
| `tests/Feature/Auth/RegistrationTest.php` | + `terms` |

## Plnění akceptačních kritérií

| # | Kritérium | Stav |
|---|---|---|
| 1 | `/pravni/obchodni-podminky` odpovídá 200 na platform hostu bez JS, s údaji z configu | splněno |
| 2 | Tytéž cesty na hostu nájemce 404 a nezastiňují jeho stránku | splněno |
| 3 | Nájemce se slugem `cookies` / `obchodni-podminky` / `ochrana-osobnich-udaju` má stránku dál funkční | splněno, vlastní test |
| 4 | Registrace bez souhlasu = validační chyba; se souhlasem zapíše datum i verzi | splněno |
| 5 | Nový e-shop má tři nepublikované stránky s aspoň jedním `[DOPLŇTE …]` | splněno |
| 6 | Publikovaná stránka v patičce, nepublikovaná ne | splněno |
| 7 | Právní stránky nesou canonical a nejsou `noindex` | splněno |

## Rozšíření rozsahu oproti plánu

**Editor stránek.** Modul `pages` byl read-only — `PageAdminController` měl jen `index()` a komentář „editace přijde v pozdější vlně". Vzory z Tasku 5 by tedy zůstaly navždy nedoplněné a nepublikované: nájemce by měl tři neviditelné šablony a žádný způsob, jak z nich udělat své VOP. Celá vlna by byla inertní.

Vlastník rozhodl doplnit editor do této vlny. Dodáno: `PageWriter` (stejný tvar jako `ProductWriter` — sanitizace při zápisu, unikátní slug per e-shop, 301 při přejmenování), `PageRequest`, plné CRUD, seznam s akcemi a formulář. **Bez WYSIWYG** — obsah se píše jako HTML v `textarea`, povolené značky jsou vypsané pod polem a `HtmlSanitizer` zbytek odstraní při uložení. Rich-text editor by znamenal novou JS závislost, která se nemění bez souhlasu.

## Rozhodnutí a nálezy

### Prefix `/pravni/` je nutný, ne kosmetický

Od vlny 3.1 obsluhuje `/{slug}` na hostu nájemce `Route::fallback()`. Jednosegmentová platformní routa `/cookies` by se namatchla i tam a `RequirePlatformHost` odpoví 404 **až po** matchi — fallback by se neuplatnil a stránka nájemce by zmizela. Protože `Modules\Pages\Lifecycle` seeduje `ochrana-osobnich-udaju` každému e-shopu, šlo by o jistotu, ne o riziko. Dvousegmentová cesta kolizi vylučuje konstrukcí; test to hlídá přímo.

Alternativa `Route::domain(config('tenancy.platform_domain'))` neprošla: doména se do routy zapeče při bootu, kdežto testy nastavují `tenancy.platform_domain` až v `setUp()`. `DomainTenantFinder::isPlatformHost()` čte config za běhu, a proto middleware cesta funguje.

### `footerPages` sdílí modul `pages`, ne layout composer storefrontu

První verze plnila proměnnou v composeru na `storefront::layouts.shop`. Test na odkaz v pokladně **prošel falešně** — patička na téže stránce nese stejné URL, takže assert na `href` matchoval ji. Po zpřísnění na text odkazu (patička tiskne „Obchodní podmínky", souhlas skloňované „obchodními podmínkami") test spadl a ukázal skutečnost: **Blade child view se renderuje před svým layoutem**, takže composer na layoutu do `checkout/details` nikdy nedosáhne.

Řešení: composer v `Modules\Pages\Providers\ModuleProvider` na obě views. Data vlastní modul, který vlastní model — a ten taky rozhoduje, kde se jeho stránky nabízejí.

### `Page` už nepřepisuje `getRouteKeyName()`

Vracel `'slug'` kvůli storefront routě, která od vlny 3.1 model vůbec neváže (`PageController` čte cestu z requestu). Admin je jediný, kdo `Page` váže, a vazba podle slugu by znamenala, že se URL editace mění při přejmenování — otevřená záložka by po vlastním uložení 404ovala.

### Vzor se dá publikovat nedoplněný, jen s varováním

Tvrdá blokace publikace stránky s `[DOPLŇTE …]` byla zvážena a zamítnuta: nájemce může mít legitimní důvod publikovat rozpracovaný text a administrace, která odmítne uložit, působí jako chyba. Místo toho: stránky zůstávají po seedu nepublikované, seznam nese trvalé upozornění o odpovědnosti a formulář varuje **v okamžiku zaškrtnutí publikace**, pokud placeholdery zbyly.

### Šablony jsou testované proti sanitizeru

Značky mimo allowlist `HtmlSanitizer` by se odstranily při prvním uložení nájemcem a vzor by tiše ztratil strukturu. Test proto vzory sanitizerem prožene a ověří, že přežijí `h2`, `p` i `strong`.

### `users` potřeboval `$fillable`

Zápis souhlasu se nejdřív tiše ztrácel: `User::$fillable` je Breeze default (`name`, `email`, `password`), takže mass assignment nová pole zahodil bez chyby. Odhalil to test, ne review.

## Testy

**1954 testů, vše zelené** (bylo 1913). 41 nových.

| Sada | Testů |
|---|---|
| `tests/Unit` + `Auth`, `Billing`, `Core`, `Database`, `Domains`, `Legal` | 493 |
| `Onboarding`, `PageCache`, `Platform`, `Storefront`, `Tenancy`, `Tenant`, `Theme` + kořenové | 428 |
| `tests/Feature/Modules` | 1033 |

Plná sada se **nepouští jedním příkazem** — přeteče timeout a shodí sdílenou MySQL testovací databázi.

## Technický dluh

- **Drafty nemají právní revizi** (vědomé rozhodnutí vlastníka). Markery `> **K PRÁVNÍ REVIZI:**` označují limitaci náhrady škody, výpovědní dobu, SLA, lhůtu pro ohlášení porušení zabezpečení a rozsah auditního práva.
- **Text existuje dvakrát** — v `docs/legal/*.md` a v Blade šablonách. Markdown parser se nepřidával kvůli čtyřem dokumentům; `docs/legal/README.md` na povinnost měnit obojí upozorňuje a testy hlídají jen klíčové pasáže.
- **`terms_version` není tabulka verzí.** Historie znění je v gitu. Prokázat *znění* k datu by vyžadovalo samostatnou tabulku.
- **Editor je textarea s HTML.** Nájemce bez znalosti HTML si vystačí s dodaným vzorem, ale vlastní strukturovaný text píše ručně.
- **Cookie lišta a měřicí kódy chybí** — vlna 3.3. Zásady cookies to explicitně říkají oběma směry: platforma dnes měřicí kódy nemá a nájemce je nasadit nemůže.
- **`Welcome.vue` je pořád Laravel default**, včetně obrázku načítaného z `laravel.com`. Vlna se ho dotkla jen tím, že právní stránky mají vlastní layout.

## Pre-deploy checklist

- [ ] `php artisan migrate` (dva nové sloupce na `users`)
- [ ] `npm run build`
- [ ] **`.env` produkce:** `BILLING_COMPANY_ICO`, `BILLING_COMPANY_ADDRESS`, `BILLING_COMPANY_EMAIL` — bez nich se právní stránky vyrenderují s prázdnými identifikačními údaji, což je u VOP a zásad zpracování vada, ne kosmetika
- [ ] Projít drafty s právníkem, začít od markerů `K PRÁVNÍ REVIZI`
- [ ] Ověřit, že `/pravni/*` odpovídá na produkčním platform hostu a **404uje** na hostu nájemce
- [ ] Rozhodnout, zda existujícím nájemcům (registrovaným před touto vlnou, tedy bez `terms_accepted_at`) předložit souhlas dodatečně
