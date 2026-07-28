# As-is: Slevový engine (vlna 2.6)

Datum: 2026-07-28 · Verze: **0.24.x** (0.24.0 = uzavření vlny; patch bumpy nad ním jsou opravy z final review, VERSION zvedá pre-commit hook) · Větev: `feature/vlna-26-slevovy-engine` · 1461 testů (5110 assertions) + 6 z final review

Spec: [`docs/superpowers/specs/2026-07-28-vlna-26-slevovy-engine-design.md`](../superpowers/specs/2026-07-28-vlna-26-slevovy-engine-design.md)
Plán: [`docs/superpowers/plans/2026-07-28-vlna-26-slevovy-engine.md`](../superpowers/plans/2026-07-28-vlna-26-slevovy-engine.md)

## Co vlna přinesla

Nájemce dává slevy: kódový kupón, který zákazník zadá v košíku nebo v pokladně, a automatické pravidlo, které platí bez kódu (typicky „doprava zdarma nad 2000 Kč" nebo „−10 % na kategorii"). Sleva se rozpustí do řádků košíku i objednávky s haléřovou přesností, takže DPH rekapitulace vždy sedí na skutečně zaplacenou částku. Cenová autorita zůstává na serveru — klient posílá jen kód, nikdy částku ani id slevy.

## Mapa změn

### Jádro — `app/Core/Discounts/`

| Soubor | Role |
|--------|------|
| `Contracts/DiscountEngine.php` | `apply(DiscountContext): AppliedDiscount` — vrstva nad `ProductCatalog::price()` |
| `Contracts/DiscountBook.php` | read pro admin (`all()`, `findByCode()`), stejný split jako `OrderBook`/`OrderPlacement` |
| `Contracts/DiscountRedemption.php` | `redeem(...)` / `release(...)` — čerpání a vracení limitu |
| `DiscountContext.php`, `DiscountLine.php` | vstupní hodnotové objekty (řádky, `itemsTotal`, `couponCode`, `customerId`, `email`, `shippingCost`) |
| `AppliedDiscount.php`, `AppliedDiscountSource.php` | výstup: `perLine` (mapa `itemId => Money`), `freeShipping`, `total`, `sources`, `rejection` |
| `DiscountRejection.php` | proč kód neprošel (`NOT_FOUND`, `EXPIRED`, `MIN_CART`, `REQUIRES_LOGIN`, `USAGE_LIMIT`, `EMAIL_LIMIT`, …) |
| `Exceptions/DiscountNoLongerValid.php` | kód/pravidlo zneplatní mezi zobrazením pokladny a odesláním |
| `Null*` implementace | guest-safe chování bez nasazeného modulu (vzor `NullShippingOptions`, `PaymentGatewayRegistry`) |

### Nový modul `Modules/Discounts/`

`level: premium`, `requires: {}` (runtime gate přes `ShopModules`, precedent `checkout`), permission `discounts.manage`, nav „Slevy".

- `Services/DiscountEvaluator implements DiscountEngine` — načte aktivní kupón/pravidla, vyhodnotí podmínky, poskládá výsledek
- `Services/DiscountEligibility` — jednotlivé gaty (aktivita, platnost, min. košík, cíl, login, první nákup, limity)
- `Services/DiscountAllocator` — rozpustí částku slevy do řádků přes `Money::allocateByRatios()`; capacity-aware (víc slev na tentýž řádek nikdy nepřekročí jeho vlastní součet)
- `Services/EloquentDiscountBook`, `Services/EloquentDiscountRedemption`
- Modely `Discount`, `DiscountTarget`, `DiscountRedemption`
- `Http/Controllers/DiscountAdminController.php` + `Http/Requests/{Store,Update}DiscountRequest.php`
- `routes/admin.php` — `admin.discounts.{index,create,store,edit,update,destroy}` + `admin.discounts.products.search` (target picker)

### Schéma

| Tabulka | Poznámka |
|---------|----------|
| `discounts` | `code` nullable (NULL = automatické pravidlo), `type` (`percent`/`fixed`/`free_shipping`), `value` (permille/haléře/0), `scope` (`cart`/`categories`/`products`), `starts_at`/`ends_at`, `min_cart_total`, `usage_limit`, `usage_limit_per_email`, `used_count`, `requires_login`, `first_order_only`, `combinable`, `priority`. Unique `(tenant_id, code)` |
| `discount_targets` | `discount_id`, `target_type` (`category`/`product`), `target_id` — bez FK přes hranici modulu (vzor `carts.shipping_method_id`) |
| `discount_redemptions` | `discount_id`, `order_id`, `email` (lowercase), `customer_id` nullable, `amount`, `released_at` nullable. Unique `(tenant_id, discount_id, order_id)` — idempotence retry odeslání |
| `orders.discount_total`, `order_items.discount_total` | `unsignedInteger`; `order_items.line_total` je už po slevě |
| `order_discounts` (modul `orders`) | immutable snímek: `discount_id` nullable (bez FK), `code`, `name`, `type`, `amount`, `free_shipping` — **bez sloupce `value`** (viz Odchylky) |
| `carts.coupon_code` | jediné, co si klient nese |
| `documents.discount_total` (`unsignedBigInteger`), `documents.discount_note` (nullable string) | informační poznámka pod tabulkou řádků faktury |
| `plan_modules` | backfill migrace + `PlanSeeder` — modul jde jen s tarifem premium |

### Zásahy do existujících modulů

`checkout` — `CartPricer::price()` volá engine po přepočtu řádků; `PricedCart`/`PricedCartLine` nesou `discountTotal`, `discountSources`, `freeShipping`, `discountRejection`, `discountAmount`, `discountedLineTotal`; `CartPricer::shippingCost()` bere příznak `freeShipping` a vrátí nulu bez ohledu na `freeFrom()`; `CartPricer::vatBreakdown()` čte už zlevněné řádkové součty beze změny algoritmu. Nové endpointy `POST /kosik/sleva` (`CartDiscountController::apply`) a `POST /kosik/sleva/zrusit` (`::remove`) — CSRF, PRG, gated na modul `discounts`. Pole „Slevový kód" na `/kosik` i `/pokladna/udaje` (čistý `<form method="post">`), chyba vázaná `aria-describedby` + `role="alert"`.

`orders` — `OrderPlacer` po `recomputeLines()` volá engine **znovu** (poslední vyhodnocení je závazné, stejná politika jako `PriceChanged`), zapíše alokaci na `order_items.discount_total`/`orders.discount_total`, snímek do `order_discounts` a `redeem()` **uvnitř téže transakce** jako odpis skladu. Storno a expirační job volají `release()` tam, kde dnes vracejí sklad — **gated na `returnStock`**, takže podezřelý storno (který sklad záměrně nevrací) nevrací ani kupón. `OrderEditor` při editaci zachová podíl slevy na každém přeživším řádku a znovu odvodí `orders.discount_total`, aniž by engine spouštěl podruhé.

`payments`/`checkout` — objednávka za 0 Kč po slevě se settluje přímo přes `OrderSettlement`, brána se nevolá; QR i platební instrukce se potlačí na děkovné stránce i v potvrzovacím e-mailu.

`docs` — `InvoiceIssuer` píše poznámku o uplatněné slevě pod tabulku řádků; při neshodě snímku se živým součtem poznámka degraduje na obecný text bez jmen slev. Dobropis je beze změny prostá negace (zlevněné řádky se negují stejně jako nezlevněné).

`admin` (Inertia, `resources/js/Pages/Modules/Discounts/`) — výpis, formulář (generátor kódu, ohraničený hledáček produktů, výběr kategorií), potvrzovací dialog u mazání, chyby vázané na pole (WCAG).

## Plnění spec

| Akceptační kritérium | Stav |
|---|---|
| Zákazník bez JS zadá platný kód, dokončí objednávku, sleva sedí na `orders.total` | **splněno** |
| Neplatný/expirovaný/vyčerpaný kód se neuloží, vrátí konkrétní důvod | **splněno** |
| Kód zneplatnělý mezi pokladnou a odesláním objednávku nevytvoří | **splněno** — a to pro **libovolný** důvod zneplatnění, ne jen pro dva e-mailem gatované. Mechanismus je dvoudílný: (1) každý render košíku i rekapitulace pokladny odmítnutý kód **smaže z košíku** a zároveň na téže stránce vypíše důvod (`Modules\Checkout\Support\StaleCoupon`), takže „kód je na košíku" znamená „při posledním renderu platil"; (2) `OrderPlacer::refuseIfTheCouponStoppedApplying()` na jakémkoli odmítnutí zadaného kódu objednávku odmítne s českým vysvětlením a nic nezapíše. Zpřísněno final review (dřív se tiše zapsala plná cena) |
| Alokace do řádků se sečte přesně; DPH rekapitulace sedí i u dvou sazeb | **splněno částečně** — alokace do řádků a rekapitulace přes víc sazeb sedí, ale poplatek za dopravu nebo platbu, jehož metoda **nemá `tax_rate_id`** (sloupec je nullable ve schématu i v `StoreShippingMethodRequest`/`StorePaymentMethodRequest`), z rekapitulace vypadne, přestože do `orders.total` dál počítá. Plátce DPH tak umí vystavit fakturu, jejíž rekapitulace nesedí s vlastním součtem. Pre-existující cesta (`CartPricer::vatBreakdown()`, `OrderPlacer::vatSummary()`, `OrderEditor::vatSummary()`), vlnou nezhoršená; oprava potřebuje rozhodnutí na úrovni tenant configu — viz technický dluh |
| Automatické „doprava zdarma nad X" přebije `freeFrom()` | **splněno** |
| `combinable = false` se s kupónem nesčítá | **splněno** |
| `usage_limit` se při souběhu nepřečerpá | **splněno** — `lockForUpdate` uvnitř objednávkové transakce |
| Limit na e-mail odmítne druhou objednávku téhož e-mailu (i host) | **splněno** |
| Storno vrací čerpání | **splněno**, gated na `returnStock` |
| Objednávka za 0 Kč nevolá bránu | **splněno** |
| Faktura nese zlevněné částky + poznámku; dobropis je negace | **splněno** |
| Vypnutý modul: pole zmizí, checkout beze změny | **splněno** |
| Tenant izolace kupónu | **splněno** |
| Celá stávající sada zůstává zelená | **splněno** — 1461 testů, 5110 assertions |

## Testy

1461 testů zelených (5110 assertions; vlna začala na 1359), plná sada spuštěna ve foregroundu na commitu `e9b2142`. Každý ze 12 implementačních tasků prošel task review, několik po fix roundu.

Final review celé větve přidal dalších **6 testů** (CAS ve `release()`, dvojí storno, throttle kupónového endpointu, mazání zastaralého kódu na renderu košíku i rekapitulace, chybová zpráva u automatického pravidla bez kódu) a **2 existující testy přepsal**: `test_an_exhausted_coupon_stops_applying_and_leaves_no_row` → `test_an_exhausted_coupon_refuses_the_order_rather_than_charging_full_price` a `test_a_coupon_rejected_for_a_non_email_reason_still_places_at_full_price` → `..._also_refuses_the_order`. Oba pinovaly staré tiché chování za plnou cenu, takže musely změnit tvrzení, ne zmizet. Cílený běh `--filter="Discount|Order|Checkout|Payment|Docs"` po opravách: 440 testů, 2009 assertions, zeleně.

Nové testovací třídy: `DiscountNullBindingTest`, `DiscountModuleTest`, `DiscountAllocatorTest`, `DiscountEvaluatorTest`, `DiscountRedemptionTest`, `DiscountAdminTest`, `CartDiscountTest`, `CartDiscountFormTest`, `CheckoutDiscountRecapTest`, `OrderDiscountTest`, `OrderDiscountReleaseTest`, `InvoiceDiscountTest`.

## Opravy z final review celé větve

Čtyři must-fix nálezy, každý ve vlastním commitu:

1. **Dvojí vrácení čerpání (souběh).** `EloquentDiscountRedemption::release()` stampovalo `released_at` bezpodmínečným UPDATE po SELECTu, takže dva souběžné releasy téže objednávky obě dekrementovaly `used_count` — kupón s `usage_limit = 100` šel použít 101×. Nově compare-and-swap (`whereKey(...)->whereNull('released_at')->update(...)`) a dekrement jen když UPDATE opravdu zabral. `OrderEditor::cancel()` navíc bere `lockForUpdate()` na řádku objednávky (vzor `EloquentOrderSettlement::settleFailed()`) a pracuje se zamčenou instancí — dvojklik na „Stornovat" tak druhým průchodem narazí na `IllegalTransition` a nevrátí sklad ani kupón podruhé. Pořadí zámků beze změny: produkty nejdřív, řádek slevy poslední.
2. **Neomezený kupónový endpoint jako oracle.** `POST /kosik/sleva` neměl žádný rate limit (skupina `web` ho nenese) a odpovědi rozlišují „takový kód neexistuje" od `min_cart`/`expired`/`usage_limit`/`requires_login` — slovníkový útok si tak umí vypsat existující kódy nájemce. `ApplyDiscountRequest` nově throttluje stejným vzorem jako `Modules\Customers\Http\Requests\LoginRequest`: 10 pokusů/min na tenant + cart token + IP, `ValidationException` s českou zprávou v `passedValidation()`, takže zahozený pokus se k vyhledání slevy vůbec nedostane.
3. **Tichá plná cena při zneplatnění kódu.** Viz AK 3 výše — refuse platí pro každý důvod, `StaleCoupon` maže odmítnutý kód na renderu (a důvod tam vypíše), `CartPricer` zůstává čistý read (mini-košík nesmí měnit obsah cizího košíku). Flash v `CheckoutController::place()` už netvrdí „Odebrali jsme ho z košíku", když žádný kód nebyl — `DiscountNoLongerValid::forCode(null)` je dosažitelné pro automatické pravidlo, které prohraje `redeem()` lock race.
4. **Dvě nepravdivá tvrzení v této as-is.** AK 3 se opravdu splnilo až fixem 3; AK 4 je nově vedené jako částečně splněné (viz technický dluh).

## Odchylky od specifikace

1. **Klíč modulu je `discounts`, ne `coupons`** (produktová spec §909). Modul obsluhuje i automatická pravidla bez kódu; `coupons` by obsah podhodnocoval a byl by matoucí, až přibudou akční ceny produktu (vlna 2.7).
2. **Pole pro kód je i v pokladně**, nejen v košíku (spec §797). Zákazník, který kód najde až u finální rekapitulace, nemusí couvat.
3. **Obecný `PriceModifier` řetěz ze spec §260 se nestaví.** Jeden kontrakt `DiscountEngine` s jedním volajícím na každé straně (košík, objednávka) pokrývá potřebu 2.6 i 2.7; abstraktní řetěz modifikátorů by byl postavený před druhým reálným použitím.
4. **`order_discounts` nenese sloupec `value`.** Návrh počítal se snímkem `value` vedle `amount`, ale `value` je vlastnost šablony slevy (permille/haléře), ne toho, co se skutečně odečetlo na této objednávce — nepoužitelný, nezapisovaný sloupec byl odstraněn už během implementace (task 8). Snímek nese `amount` (co bylo skutečně odečteno) a `type`/`code`/`name` pro popisek.
5. **Procenta se v adminu převádí na permille na klientovi, ne na serveru.** `DiscountFactory::percent()` i `StoreDiscountRequest` pracují v permille (stejná jednotka jako `value` ve schématu); formulář odesílá permille jako jednotku, kterou i validuje — konzistentní s tím, jak peníze v projektu odjakživa cestují už v haléřích. Round-trip je bezeztrátový; přesun výpočtu na server by byl duplicitní logika bez přidané hodnoty.

## Technický dluh

**Nejzávažnější nesené riziko**
- **DPH rekapitulace zahazuje poplatek bez sazby, ale účtuje ho.** Doprava nebo platba bez `tax_rate_id` (nullable ve schématu i ve FormRequestech) se do `vat_summary` nedostane, do `orders.total` ano. U plátce DPH to znamená daňový doklad, jehož rekapitulace nesedí s jeho vlastním součtem — účetní problém nájemce, ne kosmetika. Dotčená místa: `CartPricer::vatBreakdown()`, `OrderPlacer::vatSummary()`, `OrderEditor::vatSummary()`. Cesta je pre-existující (od vlny 1.3), touto vlnou jen zviditelněná, a **v této vlně se záměrně neopravovala**: správná oprava není tichý fallback na výchozí sazbu, ale rozhodnutí na úrovni tenant configu (buď je sazba u zpoplatněné metody povinná pro plátce DPH, nebo se poplatek musí umět zařadit do nulové sazby explicitně). Uzavírá AK 4 jako částečně splněné.

**Architektura**
- Dvojí výpočet DPH (`CartPricer` + `OrderPlacer` každý počítá vlastní rekapitulaci) je existující dluh z dřívějších vln; engine je volaný z obou míst, takže se dluh vlnou nezhoršuje, ale ani neřeší.
- **`DiscountBook` je kontrakt, který v produkci nikdo nevolá.** Admin (`DiscountAdminController`) se dotazuje modelu `Discount` přímo, takže inzerovaný read/write split (vzor `OrderBook`/`OrderPlacement`) reálně neexistuje — kontrakt i `EloquentDiscountBook`/`NullDiscountBook` jsou zatím jen deklarace. Buď admin převést na kontrakt, nebo kontrakt zahodit, až bude jasné, kdo je jeho skutečný cizí čtenář.
- **Engine běží na každém zobrazení storefrontové stránky.** Mini-košík (`CartSummaryController`, `GET /api/kosik/souhrn`) volá `CartPricer::price()`, takže se na každé stránce vyhodnotí celý slevový engine, včetně lazy-loadu kategorií per produkt (`EloquentProductCatalog::findById()` neeager-loaduje `categories`, viz poznámka z tasku 5). Na malém košíku neznatelné, ale je to nový fixní náklad na každý page view — profilovat, než přijde page cache.
- Editace objednávky v adminu slevu **nepřepočítává** — zachovává podíl slevy na přeživších řádcích (viz rozhodnutí 2026-07-28). Nescaluje dobře, když admin změní množství na řádku: sleva zůstává stejná v haléřích, jen ořezaná na nový součet řádku.
- `checkout/shipping.blade.php` u každé radio volby dopravy/platby dál tiskne nominální cenu i tehdy, když sleva na dopravu zdarma běží — dřívější kód, dosažitelný poprvé teprve touto vlnou (bez opravy: cena vedle radio buttonu může působit rozporuplně proti přeškrtnuté ceně v rekapitulaci).

**Testové mezery**
- Chybí objednávkový test, který naskládá `PERCENTAGE` slevu s další slevou a přímo asertuje `sum(order_discounts.amount) === orders.discount_total`; invariant drží strukturálně, pokrytí je mezera.
- Souběžné scénáře limitu (`usage_limit`) jsou ověřené jen sekvenčně přes `lockForUpdate`, jednovláknový PHPUnit skutečný race nevyvolá. Compare-and-swap ve `release()` je proto ověřený deterministicky — cizí writer orazítkuje řádek pod už proběhlým SELECTem (`OrderDiscountReleaseTest`).

**Menší nálezy z reviews (odloženo)**
- Nepoužitý sloupec `discounts.currency` (CZK-only MVP) — buď zahodit, nebo časem validovat proti měně košíku.
- Cizí dokumentační drobnosti v docblocích `DiscountLine`/`DiscountBook` (přebytečný `@param`/`@method`) — kosmetické, beze změny chování.

## Pre-deploy checklist

- [ ] `php artisan modules:sync` **před** `php artisan migrate` na produkci — backfill migrace `attach_discounts_to_premium_plan` čeká, až v `modules` existuje řádek `discounts`
- [ ] Ověřit, že existující premium tenanti dostali modul `discounts` v `tenant_modules` (backfill přes `plan_modules`, ne ruční seed)
- [ ] Ověřit, že tarif Start modul `discounts` nedostal (premium-only, spec §909)
- [ ] Projít `.claude/skills/accessibility/SKILL.md` na obrazovce košíku/pokladny se slevovým polem — `<label for>`, `aria-describedby` + `role="alert"` na chybě, kontrast 4.5:1, celý tok proklikatelný klávesnicí
