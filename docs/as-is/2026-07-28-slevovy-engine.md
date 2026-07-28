# As-is: Slevový engine (vlna 2.6)

Datum: 2026-07-28 · Verze: **0.24.0** · Větev: `feature/vlna-26-slevovy-engine` · 1461 testů (5110 assertions)

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
| Kód zneplatnělý mezi pokladnou a odesláním objednávku nevytvoří | **splněno** — refuse s českým vysvětlením, kód se zároveň smaže z košíku |
| Alokace do řádků se sečte přesně; DPH rekapitulace sedí i u dvou sazeb | **splněno** |
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

Nové testovací třídy: `DiscountNullBindingTest`, `DiscountModuleTest`, `DiscountAllocatorTest`, `DiscountEvaluatorTest`, `DiscountRedemptionTest`, `DiscountAdminTest`, `CartDiscountTest`, `CartDiscountFormTest`, `CheckoutDiscountRecapTest`, `OrderDiscountTest`, `OrderDiscountReleaseTest`, `InvoiceDiscountTest`.

## Odchylky od specifikace

1. **Klíč modulu je `discounts`, ne `coupons`** (produktová spec §909). Modul obsluhuje i automatická pravidla bez kódu; `coupons` by obsah podhodnocoval a byl by matoucí, až přibudou akční ceny produktu (vlna 2.7).
2. **Pole pro kód je i v pokladně**, nejen v košíku (spec §797). Zákazník, který kód najde až u finální rekapitulace, nemusí couvat.
3. **Obecný `PriceModifier` řetěz ze spec §260 se nestaví.** Jeden kontrakt `DiscountEngine` s jedním volajícím na každé straně (košík, objednávka) pokrývá potřebu 2.6 i 2.7; abstraktní řetěz modifikátorů by byl postavený před druhým reálným použitím.
4. **`order_discounts` nenese sloupec `value`.** Návrh počítal se snímkem `value` vedle `amount`, ale `value` je vlastnost šablony slevy (permille/haléře), ne toho, co se skutečně odečetlo na této objednávce — nepoužitelný, nezapisovaný sloupec byl odstraněn už během implementace (task 8). Snímek nese `amount` (co bylo skutečně odečteno) a `type`/`code`/`name` pro popisek.
5. **Procenta se v adminu převádí na permille na klientovi, ne na serveru.** `DiscountFactory::percent()` i `StoreDiscountRequest` pracují v permille (stejná jednotka jako `value` ve schématu); formulář odesílá permille jako jednotku, kterou i validuje — konzistentní s tím, jak peníze v projektu odjakživa cestují už v haléřích. Round-trip je bezeztrátový; přesun výpočtu na server by byl duplicitní logika bez přidané hodnoty.

## Technický dluh

**Architektura**
- Dvojí výpočet DPH (`CartPricer` + `OrderPlacer` každý počítá vlastní rekapitulaci) je existující dluh z dřívějších vln; engine je volaný z obou míst, takže se dluh vlnou nezhoršuje, ale ani neřeší.
- Editace objednávky v adminu slevu **nepřepočítává** — zachovává podíl slevy na přeživších řádcích (viz rozhodnutí 2026-07-28). Nescaluje dobře, když admin změní množství na řádku: sleva zůstává stejná v haléřích, jen ořezaná na nový součet řádku.
- `checkout/shipping.blade.php` u každé radio volby dopravy/platby dál tiskne nominální cenu i tehdy, když sleva na dopravu zdarma běží — dřívější kód, dosažitelný poprvé teprve touto vlnou (bez opravy: cena vedle radio buttonu může působit rozporuplně proti přeškrtnuté ceně v rekapitulaci).
- `CartPricer::vatBreakdown()` tiše zahazuje poplatek za dopravu/platbu bez `tax_rate_id`, ačkoli poplatek dál počítá do celkové částky — existující dluh, potřebuje validaci na úrovni tenant configu.

**Testové mezery**
- Chybí objednávkový test, který naskládá `PERCENTAGE` slevu s další slevou a přímo asertuje `sum(order_discounts.amount) === orders.discount_total`; invariant drží strukturálně, pokrytí je mezera.
- `EloquentDiscountRedemption::release()` zapisuje `released_at` bezpodmínečným UPDATE místo compare-and-swap a `OrderEditor::cancel()` nebere zámek na řádku objednávky — dva opravdu souběžné vstupní body by mohly dvakrát odečíst `used_count`. Existující vzor, ne nový v této vlně.
- Souběžné scénáře limitu (`usage_limit`) jsou ověřené jen sekvenčně přes `lockForUpdate`, jednovláknový PHPUnit skutečný race nevyvolá.

**Menší nálezy z reviews (odloženo)**
- Nepoužitý sloupec `discounts.currency` (CZK-only MVP) — buď zahodit, nebo časem validovat proti měně košíku.
- Cizí dokumentační drobnosti v docblocích `DiscountLine`/`DiscountBook` (přebytečný `@param`/`@method`) — kosmetické, beze změny chování.

## Pre-deploy checklist

- [ ] `php artisan modules:sync` **před** `php artisan migrate` na produkci — backfill migrace `attach_discounts_to_premium_plan` čeká, až v `modules` existuje řádek `discounts`
- [ ] Ověřit, že existující premium tenanti dostali modul `discounts` v `tenant_modules` (backfill přes `plan_modules`, ne ruční seed)
- [ ] Ověřit, že tarif Start modul `discounts` nedostal (premium-only, spec §909)
- [ ] Projít `.claude/skills/accessibility/SKILL.md` na obrazovce košíku/pokladny se slevovým polem — `<label for>`, `aria-describedby` + `role="alert"` na chybě, kontrast 4.5:1, celý tok proklikatelný klávesnicí
