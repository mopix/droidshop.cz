# Vlna 3.0 — Page cache storefrontu (§15.6) — design

Datum: 2026-08-03 · Fáze 2 · Navazuje na: storefront (Blade SSR, vlna 2.2/2.3), katalog (`ProductWriter`/`CategoryWriter`), feedy (vlna 2.9), nastavení modulů (vlna 2.10)

**Status:** approved

## Cíl

Storefront dnes renderuje každý požadavek od nuly. Žádná vrstva mezi návštěvníkem a databází neexistuje — cíl TTFB < 200 ms ze specifikace §8 nechrání nic a nájemcův e-shop stojí a padá s tím, kolik dotazů zvládne MySQL.

Šablony jsou na cache připravené od vlny 1.3: mini-košík je ostrůvek, v cachovatelném HTML není osobní obsah, košík a pokladna posílají `Cache-Control: private, no-store`. Chybí sám mechanismus.

Vlna staví **page cache celého HTML pro anonymní GET** podle §15.6, invalidovanou generačními čítači, a sjednocuje pod ni existující ad-hoc cache sitemap a feedů.

## Mimo rozsah

- **Statický soubor servírovaný web serverem** — etapa 2, odložená na nasazení (viz Etapy). Design s ní počítá, ale bez VPS ji nejde ověřit.
- **Datová a fragmentová cache** (menu, patička, číselník sazeb) — etapa 3. §15.6 je zmiňuje, ale jsou to nezávislé mechanismy s jinou invalidací.
- **CDN a edge cache** — vyžaduje rozhodnutí o poskytovateli a doméně, tedy nasazení.
- **Warmup cache** (předgenerování po invalidaci) — bez provozních čísel je to optimalizace naslepo.

## Role a viditelnost

| Kdo | Co smí |
|-----|--------|
| `TENANT_ADMIN` (owner) | vidí tlačítko „Vymazat cache e-shopu", spustí ho |
| `TENANT_STAFF` | totéž — tlačítko sedí na jádrové obrazovce `/admin/nastaveni/vzhled` pod `tenant.member`, stejně jako zbytek nastavení vzhledu. Vlastní právo nevzniká: právo, které nic navíc nehlídá, je klamná autorizační plocha (týž závěr jako u `packeta.manage` ve vlně 2.5) |
| `CUSTOMER` | nic — cache je neviditelná; přihlášený zákazník ji obchází |
| `SUPERADMIN` | nic navíc v UI. Globální vypnutí je konfigurační přepínač `config('pagecache.enabled')`, ne obrazovka |

## Rozhodnutí z brainstormingu (závazná)

1. **Cache je jádrová infrastruktura (`app/Core/PageCache/`), ne modul.** Slouží všem storefront modulům (`storefront`, `products`, `categories`, `pages`, `feeds`); modul, který jde vypnout, by ji držet nemohl.
2. **Aplikační vrstva první, statický soubor až na VPS.** Middleware za `StartSession` je testovatelný lokálně a zbaví request DB dotazů i renderu. Bootování Laravelu (~30–60 ms) zůstává — to sundá až etapa 2.
3. **Invalidace generačními čítači, ne tagy.** Spec §15.6 psala tagy, ale ty umí jen Redis a projekt je dosud nikde nepoužívá (`Cache::remember` + ruční `forget`: sitemap, feedy, `SettingsService`, `ModuleRegistry`, `DomainTenantFinder`, `TaxRates`). Tag by z Redisu udělal tvrdou závislost, jejíž absence by se projevila **tiše** — cache by přestala invalidovat, ne spadnout. Generační čítač funguje na file, database i Redis driveru, nevyžaduje enumeraci klíčů a nemá závod.
9. **Čítače leží ve sloupcích `tenants`, ne v cache** (korekce při plánování). Čítač uložený v cache může být vystěhován; vrátí se na 1 a stránky uložené pod starou generací 1 tím **obživnou** — cache by servírovala obsah, který nájemce dávno změnil. `DomainTenantFinder` beztak načítá řádek nájemce každým requestem (`Tenant::find`), takže tři celočíselné sloupce nestojí ani jeden dotaz navíc a inkrement je atomický `UPDATE`.
10. **Invalidaci spouští observery modelů, ne volání ve writerech** (korekce při plánování). `ProductWriter` a `VariantWriter` mají dohromady přes patnáct zapisujících metod a přibývají; instrumentovat každou znamená, že šestnáctá se zapomene. Observer na `Product`, `ProductVariant`, `Category`, `HomepageBlock`, `Page` a `TenantTheme` chytí i cesty, které teprve vzniknou — včetně CSV importu. Výjimka je odpis skladu: `EloquentProductCatalog::decrementStock` píše přes query builder, takže žádnou událost Eloquentu nevyvolá a hranici skladem/vyprodáno musí zvednout sám.
4. **Zapíná se opt-in na routě**, ne globálně. Nová routa se necachuje, dokud to někdo vědomě nenapíše; opačné pořadí by při příštím modulu tiše zacachovalo něco osobního.
5. **Přihlášený zákazník cache obchází.** Hlavička renderuje „Můj účet" vs „Přihlásit se" podle `$signedInCustomer` (`shop.blade.php:65`) — per-návštěvníkový obsah. Alternativy (zástupný znak i pro účet, ostrůvek) byly zamítnuty: první přenáší povinnost pamatovat na značku na každý budoucí osobní prvek v šabloně, druhý by bez JS odkaz na přihlášení úplně skryl. Přihlášených zákazníků je menšina; drtivá většina prokliků z Heureky a Googlu je anonymní.
6. **CSRF token se v cache nahrazuje značkou a při odeslání se vrací čerstvý.** Detail produktu má `@csrf` ve formuláři do košíku (`show.blade.php:126`); token je per-session. Bez záměny by cache servírovala token návštěvníka A návštěvníkovi B a vložení do košíku by skončilo 419.
7. **Query string se normalizuje whitelistem.** `ProductQuery::fromInput` už neznámé parametry zahazuje, takže klíč je smí zahodit také. `?utm_source=fb` tak trefí stejný záznam místo aby tříštil cache, a nikdo cache nezaplaví vymyšlenými parametry.
8. **Odpis skladu zvedá čítač jen na hranici skladem/vyprodáno.** Naivní bump při každé objednávce by na rušném e-shopu cache nikdy netrefila. Detail produktu zobrazuje dostupnost, ne počet kusů — 5 → 4 se nezobrazuje, 1 → 0 ano.

## Architektura

`app/Core/PageCache/`:

| Třída | Odpovědnost |
|---|---|
| `PageCachePolicy` | Rozhodne, zda request a odpověď smí do cache |
| `PageCacheKey` | Složí klíč `page:{tenant}:{gen}:{path}:{qsHash}` |
| `Generations` | Čítače per nájemce a dimenze nad sloupci `tenants`; `stamp()`, `bump()`, `bumpAll()` |
| `PageCacheObserver` | Jeden observer registrovaný na katalogové, obsahové a vzhledové modely; mapuje model na dimenzi |
| `DynamicTokens` | Záměna CSRF tokenu za značku a zpět |
| `CacheStorefrontPage` (middleware) | Čte na vstupu, zapisuje na výstupu; alias `page-cache`. Sahá na `Cache::store()` přímo — vlastní fasáda by měla jediného volajícího |

Middleware **musí běžet za `StartSession`** — jinak neexistuje token, kterým se značka nahrazuje, ani session, podle které se rozhoduje o obcházení.

`config/pagecache.php`: zapnutí, cache store, TTL per skupina.

## Generační čítače

Tři dimenze per nájemce:

| Dimenze | Co ji zvedá | Které stránky ji čtou |
|---|---|---|
| `catalog` | `ProductWriter`, `VariantWriter`, `CategoryWriter`, start/konec akční kampaně, CSV import, překročení hranice skladem/vyprodáno | homepage, kategorie, produkt, hledání, sitemap, feedy |
| `content` | editor blokové homepage, modul `pages` | homepage, statické stránky |
| `theme` | `tenant_theme`, aktivace/deaktivace modulu, `SettingsService::setMany` | všechny (mění layout) |

Klíč nese **jen dimenze, na kterých stránka závisí** — detail produktu `catalog.theme`, statická stránka `content.theme`. Změna barvy tak neshodí katalog a naopak.

Bump = `Cache::increment`. Staré záznamy osiří a vyprší TTL; nic se nemaže, žádná enumerace, žádný závod.

## TTL

| Skupina | TTL | Poznámka |
|---|---|---|
| homepage, kategorie, produkt, statické stránky | 10 min | spec §15.6 |
| 404 / 410 | 60 min | jen 404 vzniklé **uvnitř** routy (neznámý slug produktu nebo kategorie) |
| hledání | 5 min | **jen dotazy s aspoň jedním výsledkem**; termín trimnutý a omezený délkou — `?q=` má neomezenou kardinalitu |
| sitemap, feedy | 60 min | beze změny, nově navíc invalidované čítačem `catalog` |

Poslední řádek uzavírá dnešní chování, kdy feed drží starou cenu až hodinu po přecenění.

**404 na neexistující cestě middleware nikdy neuvidí** (korekce při plánování). Když nesedne žádná routa, vyhodí to routing ještě před middlewarem routy a odpověď skládá handler výjimky v `bootstrap/app.php:134` (`RedirectResponder`, který navíc hledá v tabulce `redirects`). Právě těmito URL buší skenery. Middleware na routách je nepokryje, takže se místo toho cachuje **výsledek hledání v `redirects`** per nájemce a cesta — jedna hodnota, invalidovaná dimenzí `catalog`, protože redirect vzniká přejmenováním slugu.

## Bezpečnost sdílené cache

Chyba v této sekci znamená únik mezi zákazníky — stejná třída jako únik mezi nájemci (§12.1).

**CSRF token.** Na MISS se HTML vyrenderuje normálně a **až před uložením** se v něm hodnota tokenu nahradí značkou (`str_replace(csrf_token(), '@@PAGECACHE_CSRF@@', $html)`). Na HIT se značka nahradí tokenem toho requestu, který stránku žádá. Šablony se nemění — hledá se konkrétní hodnota, ne direktiva `@csrf`, takže to platí i pro formuláře přidané později.

**Hlavičky.** Do cache jde jen `Content-Type`. `Set-Cookie` se nikdy neukládá ani neservíruje z cache — session cookie připojí Laravel sám, protože middleware běží za `StartSession`.

**Podmínky obcházení.** Request se nečte ani nezapisuje, pokud platí kterákoli:

- metoda není GET ani HEAD
- existuje session zákazníka (`auth('customer')->check()`)
- v session je flash zpráva nebo validační chyba
- odpověď není 200, 404 ani 410
- odpověď nese `Cache-Control: private` nebo `no-store` (košík, pokladna, děkovná stránka, `/api/kosik/souhrn`, výdejní místa)
- probíhá impersonace nebo je přihlášený uživatel adminu
- nájemce je suspendovaný nebo smazaný

**Izolace nájemců.** Klíč začíná `tenant_id` z `TenantContext`, ne hostem. Custom doména i subdoména téhož nájemce sdílí jeden záznam — správně, protože `RedirectToCanonicalHost` stejně 301 přesměruje na kanonickou.

## Etapy

**Etapa 1 — aplikační vrstva (tato vlna).** Vše výše, plus:

- tlačítko **„Vymazat cache e-shopu"** na `/admin/nastaveni/vzhled` (`bumpAll()`) — únikový východ, když někdo uvidí zastaralý obsah a nechce čekat na TTL
- `config('pagecache.enabled')` jako globální nouzová brzda; vypnutá cache vrací chování před vlnou

**Etapa 2 — statický soubor (až s VPS).** `PageCache` zapíše kopii i na disk, web server ji servíruje před PHP. TTFB ~5 ms, náraz kampaně přežije. Bump čítače tam nestačí (web server o čítači neví) — invalidace = smazání adresáře nájemce.

**Otevřená otázka etapy 2:** staticky servírovaná stránka nemůže dostat čerstvý CSRF token, protože PHP neběží. Buď statická vrstva ponese jen stránky bez formulářů (kategorie, homepage, statické) a detail produktu zůstane na PHP vrstvě, nebo bude vložení do košíku chráněné jinak než tokenem (SameSite + kontrola Origin). Rozhodne se, až bude stát server.

**Etapa 3 — datová a fragmentová cache.** Menu kategorií, patička, číselník sazeb. Sníží zátěž i tam, kde page cache nepomůže — pokladna, košík, přihlášený zákazník.

## Testy

- hit/miss; druhý request nesmí sáhnout na DB (počítadlo přes `DB::listen`)
- **izolace nájemců** — A nedostane stránku B (povinné podle CI pravidla v CLAUDE.md)
- **CSRF** — dvě session, dva různé tokeny, obě vložení do košíku projdou
- každá podmínka obcházení zvlášť (POST, přihlášený zákazník, flash, 500, `no-store`, suspendovaný nájemce, impersonace)
- bump každé dimenze zneplatní správné stránky a **nezneplatní** ostatní
- odpis skladu 5 → 4 cache nechá, 1 → 0 ji zvedne
- normalizace query: `?utm_source=fb` trefí stejný záznam, `?razeni=cena` jiný
- hledání bez výsledků se neuloží
- `Set-Cookie` se z cache nikdy nevrátí

## Akceptační kritéria

1. Anonymní opakovaný GET homepage, kategorie a detailu produktu se obslouží bez jediného DB dotazu do katalogu.
2. Vložení do košíku funguje z cachované stránky pro libovolného návštěvníka, bez JS.
3. Přihlášený zákazník vidí v hlavičce „Můj účet" vždy, i po tom, co tutéž URL navštívil anonym.
4. Změna ceny produktu se projeví na storefrontu i ve feedu okamžitě, ne až po TTL.
5. Změna barvy v „Vzhledu" neshodí cache katalogu.
6. Nájemce A nikdy nedostane stránku nájemce B — ani při shodné cestě.
7. Vypnutí cache v konfiguraci vrátí chování před vlnou, bez zásahu do kódu.
