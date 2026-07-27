# Vlna 2.6 — Slevový engine (kupóny + automatická pravidla) — design

Datum: 2026-07-28 · Fáze 2 · Navazuje na: `checkout` (`CartPricer`, `PricedCart`, `carts`), `orders` (`OrderPlacer`, `orders`, `order_items`, `OrderWorkflow`), `shipping` (`ShippingOption::freeFrom()`), `docs` (immutable snímek dokladu), `products` (`ProductCatalog` jako cenová autorita), precedent registry/null binding z vln 1.4 a 2.5.

**Status:** approved

## Cíl

Nájemce dává slevy: **kódový kupón**, který zákazník zadá v košíku, a **automatické pravidlo**, které platí bez kódu (typicky „doprava zdarma nad 2000 Kč" nebo „−10 % na kategorii"). Sleva se propíše do rekapitulace košíku, do pokladny, do objednávky a do daňového dokladu tak, aby DPH rekapitulace vždy seděla na skutečně zaplacenou částku.

Cenová autorita zůstává na serveru: `ProductCatalog::price()` počítá cenu produktu, slevový engine nad ní tvoří druhou vrstvu. Klient nikdy neposílá částku, jen kód kupónu.

Celý tok **musí fungovat s vypnutým JavaScriptem** (`.claude/rules/storefront-rendering.md`, spec §16.3).

## Mimo rozsah (→ `docs/future/`)

- **Akční ceny produktu** (přeškrtnutá cena, `sale_price` na produktu a variantě) včetně evidence nejnižší ceny za 30 dní podle novely — samostatná **vlna 2.7**, protože mění `ProductCatalog::price()`, tj. autoritu, přes kterou dnes jede košík, objednávka i doklady
- Dárkové poukazy s předplaceným zůstatkem (jiný účetní i daňový režim)
- Generování dávek unikátních jednorázových kódů (import/export CSV)
- Sleva na dopravu procentem (2.6 umí jen „doprava zdarma")
- Kombinace více kupónů v jednom košíku
- Slevy vázané na zákaznické skupiny / B2B cenové hladiny
- Přepočet slevy při editaci objednávky v adminu

## Role

| Role | Co smí |
|------|--------|
| `TENANT_ADMIN` s `discounts.manage` | zakládat, editovat, deaktivovat a mazat slevy; vidět čerpání |
| `TENANT_STAFF` | nic navíc (právo `discounts.manage` mu lze udělit, až role vznikne) |
| `SUPERADMIN` | **nic navíc** — slevy jsou obchodní rozhodnutí nájemce |
| `CUSTOMER` | zadat kód u vlastního košíku, vidět uplatněnou slevu na své objednávce a dokladu |
| anonym / storefront | zadat kód v košíku a pokladně (`noindex`) |

Modul je **premium** (spec §909), takže tarif Start slevy nedostane. Write-freeze na `suspended`/`past_due` platí přes `CheckTenantStatus` beze změny.

## Rozhodnutí z brainstormingu (závazná)

| Otázka | Rozhodnutí |
|--------|-----------|
| Rozsah vlny | **Jen engine nad košíkem**; akční ceny produktu = vlna 2.7 |
| Typy slevy | procento z košíku, pevná částka, doprava zdarma, sleva na kategorii/produkt |
| Kombinace | **Nejvýš jeden kód** + automatická pravidla; každé pravidlo má přepínač `combinable` |
| Limity | globální `usage_limit` + volitelný **limit na e-mail objednávky** (funguje i pro hosty) |
| Podmínky | platnost od–do, min. hodnota košíku, cíl kategorie/produkty, jen přihlášený, jen první nákup |
| Architektura | **jádrový kontrakt `DiscountEngine` + per-řádková alokace slevy** |
| Doklad | **snížené řádky + poznámka** o uplatněné slevě; žádný záporný řádek |
| Modul | klíč **`discounts`** (ne `coupons` ze specu), `level: premium` |
| Spotřeba limitu | **při odeslání objednávky**, storno/expirace **vrací** |
| UX | pole pro kód **v košíku i v rekapitulaci pokladny**, POST + redirect, bez JS |

### Odchylky od produktové specifikace

1. **Klíč modulu je `discounts`, ne `coupons`** (spec §909). Modul obsluhuje i automatická pravidla bez kódu; `coupons` by podhodnocoval obsah a byl by matoucí, až přibudou akční ceny (2.7).
2. **Pole pro kód je i v pokladně**, nejen v košíku (spec §797). Zákazník, který kód najde až u finální rekapitulace, nemusí couvat zpět.
3. **`PriceModifier` řetěz ze spec §260 se nestaví.** Jeden kontrakt `DiscountEngine` s jedním volajícím na každé straně (košík, objednávka) pokrývá potřebu 2.6 i 2.7; obecný řetěz modifikátorů by byl abstrakce postavená před druhým použitím.

## Architektura

### Jádro — `app/Core/Discounts/`

```
Contracts/DiscountEngine      apply(DiscountContext): AppliedDiscount
Contracts/DiscountBook        read pro admin a detail objednávky
Contracts/DiscountRedemption  redeem(...) / release(...)
NullDiscountEngine            žádná sleva (vypnutý modul)
NullDiscountBook              prázdno
NullDiscountRedemption        no-op
DiscountContext               vstupní hodnotový objekt
AppliedDiscount               výstupní hodnotový objekt
AppliedDiscountSource         { type, code, name, amount }
DiscountRejection             { code, reason } — proč kód neplatí
Exceptions/DiscountNoLongerValid
```

Read/write split je stejný jako `OrderBook`/`OrderPlacement` a `DocumentBook`/`DocumentIssuer`: cizí modul nikdy nesahá na Eloquent model modulu `discounts`.

`DiscountContext` nese: seznam řádků (`itemId`, `productId`, `variantId`, `categoryIds`, `lineTotal`, `taxRatePercent`), `itemsTotal`, `couponCode`, `customerId`, `email`, `shippingCost`.

`AppliedDiscount` nese: `perLine` (mapa `itemId => Money`), `freeShipping` (bool), `total` (Money), `sources` (list), `rejection` (nullable — kód, který neprošel, a proč).

Guest-safe null bindingy registruje jádro; modul je přebije při aktivaci (vzor `PaymentGatewayRegistry`, `CarrierRegistry`).

### Modul `Modules/Discounts/`

- `DiscountEvaluator implements DiscountEngine` — načte aktivní pravidla a případný kupón, vyhodnotí podmínky, poskládá výsledek
- `DiscountAllocator` — rozpustí částku do řádků; poslední řádek dostane zaokrouhlovací zbytek, takže `sum(perLine) === total` na haléř
- `EloquentDiscountBook`, `EloquentDiscountRedemption`
- Modely `Discount`, `DiscountTarget`, `DiscountRedemption`

Manifest: `core: false`, `level: premium`, `requires: {}` (runtime gate přes `ShopModules`, ne manifestová závislost — precedent `checkout`), `permissions: ["discounts.manage"]`, nav „Slevy".

### Integrace do checkoutu a objednávky

| Místo | Změna |
|-------|-------|
| `CartPricer::price()` | po přepočtu řádků zavolá engine; `PricedCart` dostane `discountTotal`, `discountSources`, `freeShipping`, `discountRejection`; `PricedCartLine` dostane `discountAmount` a `discountedLineTotal` |
| `CartPricer::shippingCost()` | přijme příznak `freeShipping` a vrátí nulu bez ohledu na `freeFrom()` |
| `CartPricer::vatBreakdown()` | čte **už zlevněné** řádkové součty — žádná zvláštní daňová větev |
| `OrderPlacer` | po `recomputeLines()` zavolá engine **znovu** (autorita), zapíše alokaci na `order_items.discount_total`, `orders.discount_total`, snímek do `order_discounts` a `redeem()` **uvnitř téže transakce** jako odpis skladu |
| `OrderWorkflow` (storno) + expirační job | volají `release()` tam, kde dnes vracejí sklad |
| `docs` | řádky nesou zlevněné částky; pod tabulkou poznámka o uplatněné slevě |

Sleva se tedy vyhodnocuje **třikrát nezávisle** (zobrazení košíku, zobrazení pokladny, odeslání objednávky) a poslední vyhodnocení je jediné závazné — stejná politika jako u ceny (`PriceChanged`).

## Datový model

Vše tenant-scoped s `BelongsToTenant`, migrace v modulu `discounts` (kromě alterů na cizích tabulkách, které patří k dotčenému modulu).

### `discounts`

| Sloupec | Typ | Poznámka |
|---------|-----|----------|
| `name` | string | interní i zákaznický popis |
| `code` | string nullable | NULL = automatické pravidlo |
| `active` | bool | |
| `type` | enum | `percent` / `fixed` / `free_shipping` |
| `value` | unsignedInteger | permille u procenta, haléře u pevné částky, 0 u dopravy |
| `currency` | char(3) | |
| `scope` | enum | `cart` / `categories` / `products` |
| `starts_at`, `ends_at` | timestamp nullable | |
| `min_cart_total` | unsignedInteger nullable | |
| `usage_limit` | unsignedInteger nullable | |
| `usage_limit_per_email` | unsignedInteger nullable | |
| `requires_login` | bool | |
| `first_order_only` | bool | |
| `combinable` | bool | smí se sčítat s kupónem; engine ho čte **jen u automatických pravidel**, u kupónu se ignoruje |
| `priority` | unsignedInteger | pořadí vyhodnocení |
| `used_count` | unsignedInteger | denormalizovaný čítač pro admin a rychlý limit check |

Unique `(tenant_id, code)`. NULL kódy zůstávají v obou DB vždy odlišné — přesně to zde chceme, automatických pravidel může být víc.

### `discount_targets`

`discount_id`, `target_type` (`category` / `product`), `target_id`. Bez FK přes hranici modulu (vzor `carts.shipping_method_id`) — cizí klíč by z `categories`/`products` udělal nevypnutelný modul.

### `discount_redemptions`

`discount_id`, `order_id`, `email` (lowercase), `customer_id` nullable, `amount`, `released_at` nullable.
Unique `(tenant_id, discount_id, order_id)` — idempotence retry odeslání.
Index `(tenant_id, discount_id, email)` — limit na e-mail.

### Altery

- `orders`: `discount_total` unsignedInteger default 0
- `order_items`: `discount_total` unsignedInteger default 0; `line_total` je **už po slevě**
- `order_discounts` (nová, modul `orders`): immutable snímek `code`, `name`, `type`, `value`, `amount` — objednávka musí přežít smazání kupónu (politika `variant_label` z 2.4)
- `carts`: `coupon_code` string nullable — jediné, co si klient nese

## Požadavky

### Backend

1. Jádrové kontrakty, hodnotové objekty a null bindingy podle sekce Architektura.
2. Modul `discounts` s evaluátorem, alokátorem, modely a migracemi.
3. Vyhodnocení podmínek: aktivita, platnost, min. hodnota košíku, cíl (kategorie/produkty), `requires_login`, `first_order_only`, globální limit, limit na e-mail, `combinable`.
4. Alokace slevy do řádků s haléřovou přesností; `fixed` sleva se ořízne na hodnotu zboží.
5. `free_shipping` vynuluje cenu dopravy v košíku i na objednávce.
6. Čerpání limitu v téže transakci jako objednávka; storno a expirace vracejí.
7. Endpointy `POST /kosik/sleva` a `POST /kosik/sleva/zrusit` (CSRF, PRG, redirect jen na whitelistované vlastní cesty).
8. Admin CRUD `/admin/m/discounts` za `module:discounts` → `tenant.member`, permission `discounts.manage`.

### Frontend

1. Pole „Slevový kód" v `/kosik` a `/pokladna/udaje` — čistý `<form method="post">`, funkční bez JS.
2. Řádek „Sleva KOD10 −200 Kč" v rekapitulaci; u `free_shipping` přeškrtnutá původní cena dopravy.
3. Chybová hláška ke kódu vázaná přes `aria-describedby` a `role="alert"`, nikdy jen barvou (WCAG 2.2 AA).
4. Admin (Inertia, `resources/js/Pages/Modules/Discounts/`): výpis, formulář s generátorem kódu, výběr cílových kategorií/produktů, detail s čerpáním, potvrzovací dialog u mazání.

## Akceptační kritéria

1. Zákazník bez JS zadá platný kód v košíku, vidí sníženou částku a dokončí objednávku; sleva sedí na `orders.total`.
2. Neplatný, expirovaný nebo vyčerpaný kód se **neuloží** do košíku a vrátí konkrétní důvod.
3. Kód, který zneplatní až mezi zobrazením pokladny a odesláním, objednávku nevytvoří — zákazník se vrátí do pokladny s vysvětlením.
4. Sleva rozpuštěná do řádků se sečte přesně na celkovou slevu; DPH rekapitulace objednávky sedí na `orders.total` i u košíku se dvěma sazbami.
5. Automatické pravidlo „doprava zdarma nad X" se aplikuje bez kódu a přebije `freeFrom()` metody dopravy.
6. Pravidlo s `combinable = false` se s kupónem nesčítá.
7. Kupón s `usage_limit` se při souběžných objednávkách nepřečerpá.
8. Kupón s limitem na e-mail odmítne druhou objednávku téhož e-mailu, ať je zákazník přihlášený nebo host.
9. Storno objednávky vrátí čerpání; zákazník smí kód použít znovu.
10. Objednávka za 0 Kč po slevě nevolá platební bránu a rovnou dostane `payment_status = paid`.
11. Faktura nese zlevněné částky a poznámku o slevě; dobropis je jejich negací.
12. Vypnutý modul `discounts`: pole se nezobrazí, checkout se chová jako dnes, žádná chyba.
13. Kupón tenanta A neplatí u tenanta B.
14. Celá stávající testovací sada zůstává zelená.

## Technické poznámky

- **Všechny částky jsou hrubé (s DPH)**, jako všude v projektu — `min_cart_total` i `value` u `fixed` se porovnávají a odečítají proti `itemsTotal` včetně DPH. Čistou částku dopočítá `TaxRate::net()` až v rekapitulaci.
- Modul se přidá do `plan_modules` u tarifu premium; stávající premium tenanti ho dostanou backfill migrací, tarif Start nikoli.
- Rozpočet slevy počítá `DiscountAllocator`; zaokrouhlovací zbytek dostane poslední zlevněný řádek, aby `sum(perLine) === total`.
- `redeem()` čte `discounts.used_count` pod `lockForUpdate` uvnitř objednávkové transakce; překročení limitu vyhodí `DiscountNoLongerValid` a transakce padá stejně jako při nedostatku skladu.
- E-mail se pro limit normalizuje na lowercase před zápisem i před dotazem.
- Editace objednávky v adminu slevu **nepřepočítává** (snímek zůstává); UI na to upozorní.
- Dvojí výpočet DPH (`CartPricer` + `OrderPlacer`) je existující dluh; engine je volán z obou míst, takže se dluh nezhoršuje.
- Route names: `storefront.cart.discount.apply`, `storefront.cart.discount.remove`, `admin.discounts.*`.

## Reference

- Produktová spec: §3.1 (rozsah), §260 (`PriceModifier`), §797 (pole v košíku), §909 (modul `coupons`, premium), §579 (novela o slevách — týká se vlny 2.7)
- Plán: `docs/superpowers/plans/2026-07-28-vlna-26-slevovy-engine.md`
- As-is (po dokončení): `docs/as-is/2026-07-28-slevovy-engine.md`
