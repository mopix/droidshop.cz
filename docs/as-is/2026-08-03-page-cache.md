# As-is: page cache storefrontu (§15.6) — vlna 3.0

Datum: 2026-08-05 · Verze: **0.35.0** (minor otevírá vlnu 3.0) · Větev: `feature/vlna-30-page-cache`

Spec: [`docs/superpowers/specs/2026-08-03-vlna-30-page-cache-design.md`](../superpowers/specs/2026-08-03-vlna-30-page-cache-design.md)

## Co vlna přinesla

Storefront do této vlny renderoval každý požadavek od nuly — žádná vrstva mezi návštěvníkem a databází neexistovala. Cíl TTFB < 200 ms ze specifikace §8 nechránil nic a nájemcův e-shop stál a padal s tím, kolik dotazů zvládne MySQL.

Vlna staví **whole-HTML page cache pro anonymní GET** storefrontu (§15.6): opt-in middleware na routě, klíč složený z generačního čítače nájemce a normalizovaného query stringu, invalidace přes observery modelů, CSRF token bezpečně vyměněný za značku. Zároveň pod sebe sjednocuje existující ad-hoc cache sitemap (2.9) a feedů (2.9) a nově cachuje i výsledek hledání v tabulce `redirects` pro 404 mimo routu.

Toto je **etapa 1 (aplikační vrstva)** z dvou plánovaných. Etapa 2 (statický soubor servírovaný web serverem) čeká na VPS.

## Mapa změn

46 souborů, +3355/−19 (`git diff --stat 1e0592d..HEAD`).

| Soubor | Změna |
|--------|-------|
| `app/Core/PageCache/Dimension.php` | enum `Catalog`/`Content`/`Theme`, mapuje na sloupec `page_gen_{value}` |
| `app/Core/PageCache/Generations.php` | čítače nad `tenants`: `stamp()` (čte), `bump()` (atomický `increment` + sync in-memory instance), `bumpAll()` |
| `app/Core/PageCache/PageCacheKey.php` | klíč `page:{tenant}:{host}:{gen-stamp}:{path}[:{qs-hash}]`; whitelist query parametrů; `foldSearchTerm()` jediná definice foldu `q` |
| `app/Core/PageCache/PageCacheObserver.php` | jeden observer, mapa model → dimenze |
| `app/Core/PageCache/PageCachePolicy.php` | `tenantFor()` (smí se číst/zapisovat vůbec), `mayStore()` (smí se uložit tahle konkrétní odpověď) |
| `app/Core/PageCache/DynamicTokens.php` | `mask()`/`unmask()` CSRF tokenu |
| `app/Http/Middleware/CacheStorefrontPage.php` | middleware `page-cache:{dimenze}`, alias v `bootstrap/app.php` |
| `config/pagecache.php` | `enabled`, `store`, `ttl.*`, `query_whitelist`, `search_term_max` |
| `database/migrations/2026_08_03_100000_add_page_generation_counters_to_tenants_table.php` | `tenants.page_gen_catalog/content/theme`, default 1 |
| `app/Core/Tenancy/TenantContext.php` | **mimo deklarovaný rozsah vlny — viz sekce níže** |
| `app/Core/Modules/ModuleRegistry.php` | `forgetTenant()` navíc bumpuje `Theme` |
| `app/Core/Routing/RedirectResponder.php` | cachuje výsledek hledání v `redirects` (klíč `redirect:{tenant}:{catalog-gen}:{path}`) |
| `app/Core/Settings/SettingsService.php` | `setMany()` bumpuje `Theme` |
| `app/Http/Controllers/Tenant/AppearanceController.php` + `resources/js/Pages/Tenant/Appearance.vue` | tlačítko „Vymazat cache e-shopu" (`bumpAll()`) |
| `Modules/Products/Services/EloquentProductCatalog.php` | `bumpIfSoldOut()`/`bumpIfRestocked()`/`deferBump()` — hranice skladem/vyprodáno, `DB::afterCommit` |
| `Modules/Categories/Services/CategoryTree.php` | `reorder()` bumpuje `Catalog` inline (bulk update, žádný Eloquent event) |
| `Modules/Storefront/Http/Controllers/SearchController.php`, `.../SitemapController.php` | fold termínu, guard délky, sitemap stamped `Catalog+Content` |
| `Modules/Storefront/Resources/views/layouts/shop.blade.php` | hlavičkové vyhledávací pole echoje **foldnutý** term, ne raw `request()->query('q')` |
| `Modules/Feeds/Support/FeedCache.php`, `.../FeedAdminController.php` | klíč feedu nese `Catalog` stamp; admin uložení zapomíná jen svůj přesný klíč, ne celý `Catalog` |
| `routes/web.php`, `Modules/{Storefront,Products,Categories,Pages}/routes/storefront.php` | middleware `page-cache:{dimenze}` na routách |
| `tests/Feature/PageCache/*`, `tests/Unit/PageCache/DynamicTokensTest.php` | 12 nových testovacích souborů |

## Plnění spec §15.6 po bodech

- **Whole-HTML cache pro anonymní GET.** Middleware `page-cache:{dimenze}` opt-in per routa (homepage `catalog,content,theme`; kategorie/produkt `catalog,theme`; statické stránky `content,theme`; hledání jede přes `SearchController` s vlastním guardem). Žádná routa cache nedědí automaticky.
- **Klíč per nájemce, per host, per cesta, per generace.** `page:{tenant_id}:{host}:{gen-stamp}:{path}[:{qs-hash}]`. Klíč nese jen dimenze, na kterých stránka závisí — přebarvení neshodí katalog. Host je v klíči zvlášť vedle `tenant_id`, protože mezi ověřením vlastní domény a jejím povýšením na primární servírují storefront **oba** hosty (subdoména i custom doména) bez vzájemného redirectu a cachované HTML nese absolutní URL odvozené od hostu (canonical, `og:url`, `action` formuláře přidání do košíku) — bez hostu v klíči by jeden z nich zdědil cizí canonical a odeslání košíku by skončilo na hostu bez odpovídající session.
- **Query string whitelist** (`razeni`, `skladem`, `page`, `q`) — vše ostatní se zahodí, neplatné hodnoty padnou na stejný klíč jako výchozí. `?utm_source=fb` netříští cache.
- **CSRF bez úniku mezi návštěvníky.** Token se na MISS nahradí značkou `<!--PAGECACHE_CSRF-->` a na HIT vrátí čerstvým tokenem toho, kdo žádá.
- **Přihlášený zákazník cache neuvidí ani nezapíše.** `PageCachePolicy::hasVisitorState()` — guard `customer`, guard `web` (personál/impersonace), flash/validační chyby v session.
- **Sdílená bezpečnostní pravidla.** `Set-Cookie` se nikdy neukládá ani nepřehrává; jen `Content-Type` jde do cache; ukládají se jen 200/404/410.
- **Invalidace generačními čítači** ve sloupcích `tenants` (`page_gen_catalog/content/theme`), bump = atomický `UPDATE ... + 1` přes observery modelů + tři explicitní bumpy tam, kde zápis obchází Eloquent (odpis skladu, reorder kategorií, admin flush).
- **Sjednocení sitemap + feedů** pod stejný mechanismus — dřív hodinová `Cache::remember` bez vazby na obsah, teď invalidované okamžitě přes `Catalog`/`Content` stamp v klíči.
- **Escape hatch** „Vymazat cache e-shopu" na `/admin/nastaveni/vzhled` (`bumpAll()`), gatovaný `tenant.member` jako zbytek obrazovky vzhledu — žádné nové právo (packeta.manage precedent z 2.5).
- **Globální nouzová brzda** `config('pagecache.enabled')` — vypnutá cache vrací chování před vlnou beze změny kódu.

## Mimo dosah, viditelně nesplněné

- **Etapa 2 — statický soubor servírovaný web serverem.** Nejde ověřit bez VPS; design počítá s kopií na disk a mazáním adresáře nájemce při invalidaci.
- **Etapa 3 — datová a fragmentová cache** (menu, patička, číselník sazeb) — nezávislý mechanismus, jiná invalidace, nepomůže tam, kde page cache nemůže (košík, pokladna, přihlášený zákazník).

## Zásah mimo deklarovaný rozsah — `TenantContext.php`

Task 5 (commit `120ef38`) mění `app/Core/Tenancy/TenantContext.php`, soubor mimo rozsah vlny (jádro tenancy, ne page cache). **Rozhodnutí vlastníka: zůstává ve větvi**, a proto je tu vypsán zvlášť, aby se nedostal do `main` potichu.

**Co dělá:** `spatie/laravel-multitenancy`'s `Tenant::makeCurrent()` má krátký obvod (`isCurrent()`, klíčovaný jen na primárním klíči) — pokud je už svázaný tenant se stejným id, `makeCurrent()` nic dalšího neudělá. `SetTenantContext` volá `makeCurrent()` jednou za request s čerstvě načteným modelem, takže na workeru, který přežije víc než jeden request (test suite, který sdílí proces napříč sekvenčními `$this->get()` voláními už dnes; Octane, pokud se někdy nasadí), druhý request pro stejného nájemce potichu dál servíroval atributy z PRVNÍHO requestu z kontejneru.

**Proč to bylo nutné:** objeveno přes `page_gen_*` čítače — bump aktualizoval řádek v DB správně, čerstvě načtený model nesl novou hodnotu, ale `TenantContext::current()` ji nikdy neviděl kvůli tomuto krátkému obvodu. Cache invalidace vypadala rozbitá, ačkoli mechanismus byl v pořádku — problém byl o vrstvu níž, v tenancy jádru.

**Proč ne jednodušší oprava:** spustit celý `makeCurrent()` switch-task pipeline pokaždé není řešení — `PrefixCacheTask` zapomíná cache driver při každém přepnutí, což u `array` driveru zahodí jeho in-memory úložiště a udělá z každého requestu cache miss. Stejné id tenanta znamená, že switch tasky nemají co nového dělat, takže oprava jen nahradí svázanou instanci (stejně jako `BindAsCurrentTenant` dělá interně), bez opětovného spuštění tasků.

Ověřeno proti zdroji `spatie/laravel-multitenancy` 4.1.4, bez rizika v produkci (žádné Octane zatím) — verifikováno review, sign-off vlastníka zaznamenán v ledgeru (`Task 5: HUMAN RULING`).

## Testy

Instrukce k Task 14 zakazují pouštět celou sadu (koliduje na sdílené testovací DB, přesahuje timeout) — page-cache soubory se pustily jednotlivě v popředí a sečetly:

| Soubor | Testů | Assertions |
|---|---|---|
| `PageCacheMiddlewareTest.php` | 8 | 19 |
| `PageCacheInvalidationTest.php` | 13 | 29 |
| `PageCacheKeyTest.php` | 24 | 35 |
| `PageCachePolicyTest.php` | 12 | 16 |
| `StockBoundaryTest.php` | 10 | 20 |
| `SettingsInvalidationTest.php` | 2 | 4 |
| `SearchCacheTest.php` | 6 | 24 |
| `XmlOutputInvalidationTest.php` | 4 | 18 |
| `RedirectLookupCacheTest.php` | 4 | 20 |
| `FlushCacheTest.php` | 3 | 9 |
| `PageCacheAcceptanceTest.php` | 3 | 21 |
| `DynamicTokensTest.php` (Unit) | 7 | 9 |
| **Součet** | **96** | **224** |

Všech 96 zeleně. `GenerationsTest.php` (Task 1) v branchi existuje, ale nebyl v seznamu souborů k Task 14 a do součtu se nepočítá.

Celá sada se v této dokumentaci neuvádí — poslední ověřený úplný běh je z uzavření vlny 2.12 (1755 testů, 6206 assertions, `docs/as-is/2026-07-31-doprava-na-dokladu.md`); page cache k tomu přidává 96 nových testů a upravuje `VariantStorefrontTest`/`StorefrontSearchTest` beze změny jejich počtu.

## Odchylky od specifikace

1. **Generační čítače leží ve sloupcích `tenants`, ne v cache store**, jak spec §15.6 nejdřív navrhovala. Čítač uložený v cache může být vystěhován; vrátí se na 1 a stránka uložená pod starou generací 1 obživne — cache by servírovala obsah, který nájemce dávno změnil. `DomainTenantFinder` beztak načítá řádek nájemce každým requestem, takže tři celočíselné sloupce nestojí extra dotaz a bump je atomický `UPDATE`.
2. **Invalidaci spouští observery modelů, ne instrumentované writery.** `ProductWriter` a `VariantWriter` mají dohromady přes patnáct zapisujících metod a přibývají; instrumentovat každou znamená, že šestnáctá se zapomene. Observer navíc chytí i cesty, které teprve vzniknou (CSV import). Výjimky (odpis skladu, reorder kategorií) obcházejí query builder a bumpují samy, protože Eloquent event nikdy nevznikne.
3. **404 na neexistující cestě se necachuje jako stránka middlewarem routy — cachuje se výsledek vyhledání v `redirects`.** Když nesedne žádná routa, middleware ji nikdy neuvidí (routing vyhodí výjimku dřív, než doběhne k middlewaru na routě). `RedirectResponder` proto sám cachuje `Cache::remember('redirect:{tenant}:{catalog-gen}:{path}', ...)`, invalidované dimenzí `Catalog` (redirect vzniká přejmenováním slugu). Sdílí store i TTL s `pagecache.ttl.not_found`, aby globální bump store nezanechal osamocenou cache mimo dosah.
4. **`mayStore()` odmítá jen na `Cache-Control: no-store`, nikdy na `private`.** Symfony/Laravel razítkuje `no-cache, private` na každou odpověď, která jede na session cookie, jako framework default — kdyby middleware kontroloval `private` (jak psal původní plán), nikdy by se neuložilo nic a celá vlna by byla mrtvá hned při prvním requestu (review finding Task 3, Critical). Kontrolu na `private` nahrazuje `PageCachePolicy::tenantFor()`, který request se session state odmítne dřív, než se k `mayStore()` vůbec dostane — cache tedy sedí správně, jen jinou cestou, než plán čekal.
5. **`PageCache` fasáda ze specu nevznikla.** Middleware sahá na `Cache::store(config('pagecache.store'))` přímo — jediný volající, takže fasáda navíc by nic nechránila.

## Technický dluh

1. **Etapa 2 (statický soubor) a její otevřená otázka CSRF** zůstávají na nasazení. Staticky servírovaná stránka nemůže dostat čerstvý CSRF token, protože PHP neběží — buď statická vrstva ponese jen stránky bez formulářů a detail produktu zůstane na PHP vrstvě, nebo se vložení do košíku ochrání jinak (SameSite + kontrola Origin). Rozhodne se, až bude stát server.
2. **Etapa 3 (datová a fragmentová cache)** — menu kategorií, patička, číselník sazeb — čeká jako samostatný mechanismus.
3. **`ShippingMethod` není observovaný**, takže editace způsobu dopravy nikdy neinvaliduje blok `DELIVERY` ve feedu, na žádné dimenzi. Odhaleno review Tasku 10, ponecháno jako follow-up.
4. **`VariantWriter::generate()` bumpuje jednou za vygenerovanou kombinaci** — matice 3×4 je 12 bumpů v jednom requestu. Nic uvnitř requestu bumpy nededupuje. Není to chyba korektnosti, jen zbytečné bumpy nad rámec toho, co by pro hit-rate stačilo.
5. **Vymazání cache je nárazová zátěž.** `bumpAll()` na `/admin/nastaveni/vzhled` je okamžitě viditelné a nedestruktivní, ale na rušném e-shopu způsobí, že další request každého souběžného návštěvníka mine cache najednou — dávka DB dotazů místo jednoho.
6. **`DynamicTokens` není idempotentní proti literální předexistující značce v obsahu.** Je to bezpečné jen díky tomu, že `HtmlSanitizer` při zápisu odstraňuje všechny komentářové uzly a Blade escapuje `<` v prostých polích — kdyby se objevilo raw-output volání nebo sanitizér, který komentáře propustí, invariant by tiše přestal platit. Tahle vazba není zdokumentovaná uvnitř třídy samotné (jen v tomto as-is a v ledgeru).
7. **Cross-modulová vazba mezi `PageCacheKey::foldSearchTerm()` a `Modules\Products\Support\SearchText::normalise()` je vynucená jen komentářem a testy**, ne typovým systémem — `app/Core` nesmí importovat `Modules\Products`. Fold nesmí nikdy foldovat agresivněji, než hledání samo folduje (jinak dva termíny, které hledání rozlišuje, spadnou na stejný cache klíč a jeden návštěvník uvidí výsledky druhého); méně agresivní fold je jen plýtvání, ne chyba.

## Ponaučení o implementačním plánu

Několik code snippetů v implementačním plánu nepřežilo kontakt s codebase — `Product::factory()` neexistuje, cesta routy košíku v jednom snippetu byla špatně a assert na naformátovanou cenu nemohl nikdy sednout, protože české formátování měny používá U+00A0 jako oddělovač tisíců, ne mezeru. Plán navíc jednou navrhl řešení 404 přes `Request::path()`, které by podle plánu mělo rozbít redirecty s koncovým lomítkem — po ověření to byla falešná obava (`Request::path()` dělá `trim($this->getPathInfo(), '/')`, ořízne obě strany), implementer i review to nezávisle potvrdili. Nic z toho nemění výsledný kód — TDD a review to chytily dřív, než to dosáhlo `main` — ale je to důvod, proč plán píše, kdo umí spustit testy proti reálnému kódu, ne kdo si kód umí jen představit.

## Pre-deploy checklist

- [ ] Etapa 2 (statický soubor + web server) — rozhodnout CSRF otázku, než se implementuje.
- [ ] Etapa 3 (datová a fragmentová cache) — menu, patička, číselník sazeb.
- [ ] `PAGE_CACHE_STORE` v produkčním `.env`, pokud se nemá použít výchozí cache store (žádné tagy se nepoužívají, takže funguje file/database/Redis).
- [ ] Ověřit `ShippingMethod` observer, než se na něj někdo spolehne (dluh 3 výše).
- [ ] Sledovat hit-rate po nasazení — dluh 4 a 5 výše (nadbytečné bumpy variant, nárazová zátěž po flush) jsou optimalizace naslepo bez provozních čísel.
