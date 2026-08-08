# As-is: nastavení obchodu pro nájemce (vlna 3.6)

Datum: 2026-08-08 · Verze: **0.41.0** · Větev: `feature/vlna-36-nastaveni-obchodu`

Zadání: [spec](../superpowers/specs/2026-08-08-vlna-36-nastaveni-obchodu-design.md) · [plán](../superpowers/plans/2026-08-08-vlna-36-nastaveni-obchodu.md)

## Co bylo špatně

Superadmin viděl o e-shopu desítky údajů. Nájemce si pod NASTAVENÍ mohl změnit doménu a barvy — nic víc. Nešlo nastavit slogan, časové pásmo, kontakt na sebe, ani co o e-shopu uvidí Google. Fakturační profil existoval, ale vedl k němu jen banner, který nájemce jednou odklikl a už ho nenašel. A rozpracovaný katalog byl od první minuty veřejný a indexovatelný.

## Kde to leží

| Vrstva | Soubory |
|---|---|
| Úložiště | `database/migrations/2026_08_08_100000_create_shop_settings_table.php`, `app/Models/ShopSettings.php` |
| Služba | `app/Core/Shop/ShopSettingsService.php` |
| Obrazovky | `app/Http/Controllers/Tenant/ShopSettingsController.php`, `app/Http/Requests/Tenant/Update{Shop,Contacts,Seo,Display}Request.php`, `resources/js/Pages/Tenant/Settings/{Shop,Contacts,Seo,Display}.vue` |
| Sdílené UI | `resources/js/Components/Ui/{TextField,CheckboxField}.vue` |
| Zámek | `app/Http/Middleware/EnsureShopUnlocked.php`, `app/Http/Controllers/ShopLockController.php`, `resources/views/shop-lock.blade.php`, `database/migrations/2026_08_08_100100_add_storefront_locked_to_tenants_table.php` |
| Storefront | `Modules/Storefront/Providers/ModuleProvider.php`, `Modules/Storefront/Support/NavCategories.php`, `Modules/Storefront/Http/Controllers/{HomeController,RobotsController,SearchController}.php`, `layouts/shop.blade.php`, `components/seo-meta.blade.php` |

## Rozhodnutí, která stojí za připomenutí

**Vlastní tabulka.** `tenants` je platformní záznam o zákazníkovi, `tenant_theme` branding čtený na každém requestu, `settings` klíčuje **podle modulu** — a nic z toho nesedí na nastavení e-shopu jako celku. Muselo by se vymyslet, který modul „vlastní" časové pásmo.

**Čtení nikdy nevrací null.** Nájemce bez řádku dostane neuloženou instanci s výchozími hodnotami. Storefront musí renderovat dřív, než kdokoli poprvé uloží.

**Zápis zvedá všechny tři generace page cache.** Tupější než observer, který mapuje model na jednu dimenzi — ale slogan je téma, patička obsah a skrývání kategorií katalog, a dělit zápis podle pole znamená, že příští přidané pole dostane špatnou dimenzi. Nastavení se mění párkrát za život e-shopu.

**`noindex` jde do meta tagu i do `robots.txt`.** Jedno bez druhého je poloviční zákaz: crawler, který stránku nestáhne, meta tag nikdy nepřečte, a crawler, který ignoruje `robots.txt`, tag přečte. Přepínač e-shopu se s přepínačem stránky **slučuje**, nikdy nepřepisuje — košík zůstane `noindex`, ať e-shop říká cokoli.

**Skrývání prázdných kategorií se ptá na celou větev.** Běžný katalog má všechno v listech a kořeny prázdné záměrně; počítat jen vlastní produkty kořene by smazalo celé horní menu a nájemce by si myslel, že mu přepínač smazal kategorie. E-shop bez jediného publikovaného produktu si menu nechá celé — schovat všechno vypadá jako rozbitý e-shop, ne prázdný. Mřížka kategorií na homepage se **nedotýká**: to je obsah, který si nájemce vybral v page builderu, ne navigace.

**Zámek stojí na vlajce v `tenants`, ne v `shop_settings`.** Vlajku čte `EnsureShopUnlocked` i `PageCachePolicy` na **každém** requestu, včetně těch, které by teplá page cache odbavila bez jediného dotazu. Čtení z `shop_settings` stálo dva dotazy navíc na každý cache hit a shodilo rozpočet dotazů, který si vlna 3.0 nastavila právě proto. Stejný argument, který tam dal generační čítače: `DomainTenantFinder` ten řádek stejně načítá. `ShopSettingsService` je jediný zapisovatel a drží obojí v souladu.

**Odemčení drží session, ne vlastní cookie.** Session cookie framework už podepisuje a šifruje; vlastní cookie by potřebovala vlastní HMAC, jinak si návštěvník napíše `unlocked=1` sám. Klíčováno tenantem — e-shopy na subdoménách platformy sdílejí doménu cookie.

**Zámek nesmí spolknout webhook.** Zamčený e-shop, který přestane přijímat „objednávka je zaplacená", ztratí platbu tiše a nájemce se to dozví od zákazníka. Mimo zámek jsou i administrace, soubory, `robots.txt` a přihlášený personál.

## Odchylky od specifikace

| Odchylka | Proč |
|---|---|
| Sitemap a feedy **jsou** pod zámkem, spec je nechávala venku | Zdůvodnění ve specu platilo jen pro webhooky. Veřejná sitemap zamčeného e-shopu vydává seznam URL katalogu, který zámek zbytek času tají |
| Odemčení v session, spec psala cookie | Session už je podepsaná a šifrovaná; ruční cookie by potřebovala vlastní HMAC |
| Vlajka zámku duplikovaná na `tenants` | Rozpočet dotazů page cache z vlny 3.0 (viz výše) |
| Přihlášený personál vidí zamčený e-shop bez hesla | Přes administraci vidí všechno stejně; druhé heslo na náhled toho, co právě zamkli, nikomu nepomůže |

## Testy

| Soubor | Co hlídá |
|---|---|
| `tests/Feature/Shop/ShopSettingsServiceTest.php` | výchozí hodnoty bez řádku, jeden řádek na nájemce, izolace, bump cache, hash hesla |
| `tests/Feature/Shop/ShopSettingsScreenTest.php` | obě obrazovky, neznámé časové pásmo, `javascript:` odkaz, cizí uživatel |
| `tests/Feature/Shop/ShopSettingsStorefrontTest.php` | slogan v hlavičce, kontakty v patičce, prázdný box se nerenderuje |
| `tests/Feature/Shop/ShopSeoTest.php` | title/description, degradace na název, `noindex` na obou místech, SVG odmítnuto |
| `tests/Feature/Shop/ShopDisplayTest.php` | prázdné kategorie vč. větve, text hledání, bump cache |
| `tests/Feature/Shop/ShopLockTest.php` | 16 testů: cache před zámkem, webhook, rate limit, zrcadlení vlajky, izolace mezi e-shopy |
| `e2e/tests/shop-settings.spec.ts` | čtyři obrazovky + axe, pět položek v menu, slogan až na storefront |
| `e2e/tests/shop-lock.spec.ts` | prohlédnout e-shop, zamknout, dvakrát načíst, odemknout |

Celá sada: 2003 PHPUnit testů, 39 Playwright.

**Dvě falešně procházející místa, která si zaslouží zmínku.** Test bumpu cache prošel i s vymazaným `bumpAll()`, protože čítače pocházejí z výchozích hodnot sloupců a v paměti byly `null` — `assertGreaterThan(null, 1)` projde vždy. A test „stránka v cache před zámkem" by prošel i na e-shopu, jehož stránky se nikdy necachovaly; proto teď nejdřív tvrdí, že se opravdu něco uložilo.

## Technický dluh

1. **Formát data a času se zatím nikde nepoužívá.** Uloží se, ale administrace i doklady dál tisknou pevný český formát. Napojení je samostatná práce napříč šablonami.
2. **Časové pásmo taky ne.** `AK 10` (datum objednávky v administraci) je splněné jen v tom smyslu, že hodnota existuje; přepnutí `date_default_timezone` per request nebylo v rozsahu.
3. **`ShopSettings.locked` a `tenants.storefront_locked` jsou dvě místa.** Drží je v souladu jediný zapisovatel a test; přímý zápis do modelu by je rozešel.
4. **Zámek nemá vlastní obrazovku.** Sedí na konci Zobrazení, což je pro bezpečnostní prvek nenápadné místo.

## Pre-deploy

- [ ] `php artisan migrate` (dvě nové migrace)
- [ ] `npm run build`
- [ ] Projít čtyři obrazovky na produkci a uložit — hlavně že se slogan a kontakty objeví na storefrontu
