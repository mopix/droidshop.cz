# Moduly `checkout` + `orders` (vlna 1.3, sloučená etapa 4+5) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Zákazník projde nákup od košíku po děkovnou stránku bez zapnutého JavaScriptu, vznikne reálná objednávka, a nájemce ji v adminu vidí, edituje, mění stavy a stornuje. Sloučená etapa: checkout (košík, pokladna, odeslání) i orders (perzistence, admin, automat, e-maily) v jedné vlně — rozhodnutí vlastníka 2026-07-21, protože checkout bez perzistence neprojde AK „objednávka vznikne" a nedrží zelený stav.

**Architecture:** Dva nové moduly. `checkout` (tabulky `carts`/`cart_items`, storefront Blade SSR, transakce odeslání) a `orders` (tabulky `orders`/`order_items`/`order_events`, admin Inertia, dvojitý stavový automat, stavové e-maily). Tři nové jádrové kontrakty v `app/Core/` po vzoru `ProductCatalog` — `CartRepository`, `OrderPlacement`, `OrderBook` — každý s guest-safe null bindingem v jádře, přebitým modulem, s runtime gate přes `ShopModules`. `checkout` nedeklaruje `requires` na `orders` ani `shipping`: objednávku zakládá přes `app(OrderPlacement::class)` (null odmítne), dopravu bere přes `ShippingOptions` (null → vestavěná nouzovka „osobní odběr zdarma"). Cenová autorita je vždy `ProductCatalog`, nikdy POST data ani `cart_items.unit_price`.

**Tech Stack:** Laravel 13, PHP 8.3, MySQL 8, PHPUnit, Blade SSR + Alpine/Vue ostrůvky (storefront), Inertia/Vue (orders admin), `spatie/laravel-multitenancy`, `endroid/qr-code ^6.1` (SPAYD QR jako inline SVG, SvgWriter — nevyžaduje GD).

## Global Constraints

- Každá doménová tabulka nese `tenant_id` jako druhý sloupec přes `->constrained()->cascadeOnDelete()` a má složený index vedený `tenant_id`. `tests/Feature/Core/SchemaConventionTest.php` obojí vynucuje. Žádná z nových tabulek se nepřidává do `PLATFORM_TABLES`.
- Modely nad tenant tabulkami používají `App\Core\Tenancy\BelongsToTenant`.
- Peníze jsou integer minor-unit sloupec s castem `App\Core\Money\MoneyCast` (+ companion sloupec `currency`, jak zjistila etapa 3 — MoneyCast píše i ten). Ceny nikdy float. Převody DPH přes `App\Models\TaxRate` / `App\Core\Tax\TaxRates`, nikdy přes `Money`.
- **Veškerá cenová logika na serveru** (spec §16.3, pravidlo `.claude/rules/storefront-rendering.md`). Podvržená cena, cena dopravy nebo poplatek v POST datech se ignoruje. Cenová autorita je `ProductCatalog::price()`, ne `cart_items.unit_price`.
- **Celý tok pokladny musí projít bez JavaScriptu.** Blade SSR, `noindex`, vyřazeno z page cache pravidlem routy (ne cookie): `/kosik`, `/pokladna/*`, `/dekujeme/*`. Ostrůvky (mini-košík, přidat do košíku bez reloadu, ARES autofill) jen jako nadstavba nad hotovým HTML.
- Orders admin je Inertia v `resources/js/Pages/Modules/Orders/`, ne uvnitř modulu (rozhodnutí 2026-07-20). Admin stránky mají `noindex` z admin layoutu.
- Admin routy modulů jdou za `['web','module:{key}','tenant.member']`; alias `auth` se nepoužívá (rozhodnutí 2026-07-20). Autorizace v controlleru `abort_unless($request->user('web')->can(...), 403)` — pozor `user('web')`.
- Route názvy: `admin.<modul>.*` a `storefront.<modul>.*`.
- Řazení (kde je) tlačítky ovladatelnými klávesnicí, ne drag&drop jako jediná cesta (WCAG 2.1.1).
- Mazací a stornovací akce mají potvrzovací dialog (`resources/js/Components/Ui/ConfirmDialog.vue`), který věc říká jasně.
- CSRF na všech formulářích košíku a pokladny. `carts.token` kryptograficky náhodný, nikdy autoinkrement. `order_events.payload` nikdy neloguje heslo ani platební údaje.
- Kód a komentáře anglicky; user-facing stringy česky se správnou diakritikou.
- Nikdy needituj `.env`. V kódu `config()`, ne `env()`.
- `composer require endroid/qr-code:^6.1` je jediná povolená změna závislostí (schváleno vlastníkem 2026-07-21). Jinak `composer.json`/`package.json` neměň.
- Nové soubory přes `php artisan make:* --no-interaction` kde generátor existuje; migrace vždy přes `make:migration`.
- Před commitem `./vendor/bin/pint` na změněné PHP soubory; při Vue změnách ověř `npm run build`.
- **Testy pouštěj jeden po druhém** — souběžné běhy poškodí sdílenou MySQL test DB.

## Reference points v existujícím kódu

Přečti před začátkem; plán jejich tvary předpokládá.

| Co | Kde |
|----|-----|
| Kontrakt katalogu + shape + výjimka skladu | `app/Core/Catalog/Contracts/ProductCatalog.php` (`price(int,$ctx)`, `decrementStock(int,int)`, `findById`), `CatalogProduct.php`, `app/Core/Catalog/Exceptions/InsufficientStock.php` |
| Implementace katalogu (rozšíříš o sazbu) | `Modules/Products/Services/EloquentProductCatalog.php`, `Modules/Products/Models/Product.php` |
| Kontrakty dopravy/plateb (etapa 3) | `app/Core/Shipping/Contracts/ShippingOptions.php` (`available(int):Collection`, `find`), `PaymentOptions.php` (`forShipping(int):Collection`, `find`), shapes `ShippingOption`/`PaymentOption` |
| Kontrakt zákazníka (etapa 2) | `app/Core/Customers/Contracts/CustomerIdentity.php` (`current`, `findByEmail`, `findById`), `CustomerAccount.php` (`accountId`, `accountEmail`, `accountFullName`, `accountPhone`) |
| Runtime „běží tento modul" | `Modules/Storefront/Support/ShopModules.php` (`has(string):bool`) |
| Kernel null binding + runtime module check (vzor) | `app/Core/Customers/NullCustomerIdentity.php` bind v `app/Providers/AppServiceProvider.php`; `Modules/Customers/Services/EloquentCustomerIdentity.php` se ptá `ShopModules` |
| Číselné řady | `app/Core/Sequences/SequenceService.php` (`next(string):string`, `configure`) |
| Sazby DPH | `app/Core/Tax/TaxRates.php` (`all`, `findById`, `default`), `app/Models/TaxRate.php` (`percent()`) |
| MailService (etapa 1) | `app/Core/Mail/Contracts/MailService.php`, `MailKind` (povinný arg `Transactional`/`Bulk`); vzor Mailable + fronta v `Modules/Customers` (reset hesla) |
| Guard `customer` + storefront Blade SSR + noindex | `Modules/Customers/routes/storefront.php`, controllery a Blade v `Modules/Customers` |
| Admin CRUD controller / Form Request / routy / registrar | `Modules/Products/Http/Controllers/ProductAdminController.php`, `Modules/Products/routes/admin.php`, `app/Core/Modules/ModuleRouteRegistrar.php` |
| Kořenová delegace / storefront routy modulu | `Modules/Storefront`, `Modules/Pages/routes/storefront.php` |
| Inertia stránky modulu v core stromu | `resources/js/Pages/Modules/Products/`, sdílený `ConfirmDialog.vue` |
| Manifest modulu | `Modules/Shipping/module.json`, `Modules/Customers/module.json` |
| Migrace + pivot s `tenant_id` (pozor na délku názvu indexu, explicitní jméno) | `Modules/Shipping/Database/Migrations/2026_07_21_045158_create_shipping_tables.php` |
| Testovací konvence admin + `ActivatesModules` + `TenantContext::runAs` | `tests/Feature/Modules/ProductAdminTest.php`, `tests/Concerns/ActivatesModules.php` |
| Schema konvence test | `tests/Feature/Core/SchemaConventionTest.php` |

### Rozhodnutí, která tento plán dělá, a proč

**1. `checkout` nedeklaruje `requires` na `orders` ani `shipping`.**
Deklarovaná závislost by z obou udělala nevypnutelný modul (stejný důvod jako `storefront` → katalog, rozhodnutí 2026-07-20). Checkout zakládá objednávku přes `app(OrderPlacement::class)`; když `orders` neběží, null binding vyhodí `OrderPlacementUnavailable` a pokladna zobrazí, že e-shop momentálně nepřijímá objednávky. Dopravu bere přes `ShippingOptions`; když `shipping` neběží, `available()` vrátí prázdno a checkout nabídne vestavěnou nouzovku „osobní odběr zdarma" a krok dopravy přeskočí. Obojí je runtime otázka přes `ShopModules`, ne manifest.

**2. Cenová autorita je `ProductCatalog::price()`, `cart_items.unit_price` je jen snímek pro banner.**
`unit_price` drží cenu viděnou při vložení. Při každém renderu i při odeslání se přepočítá ze zdroje pravdy; rozdíl vyrobí banner „cena položky se změnila z X na Y" (§16.3) a přepočte součet. Podvržená cena v POST se nikdy nečte. To je AK 4 a 5.

**3. Odeslání objednávky je jedna DB transakce včetně odpisu skladu.**
Kroky v pořadí ze spec §Odeslání. `decrementStock()` je uvnitř transakce záměrně — sklad musí spadnout stejným rollbackem jako objednávka, jinak dva souběžné checkouty prodají poslední kus dvakrát (AK 3). Idempotence přes `UNIQUE(tenant_id, cart_id, checkout_token)`: druhé odeslání téhož formuláře najde existující objednávku a jen přesměruje (AK 2).

**4. `order_items` drží snímek, `product_id` je nullable.**
Název, kód, jednotková cena a sazba se kopírují do `order_items` při vzniku. Smazaný produkt objednávku nerozbije (AK 12) — proto `product_id` nullable a `nullOnDelete`.

**5. `CatalogProduct` se rozšíří o `catalogTaxRatePercent(): float`.**
`order_items.tax_rate` a `orders.vat_summary` (rozpis DPH po sazbách) potřebují procento sazby, které dnešní `CatalogProduct` neexponuje (má jen `catalogVat()` jako `Money`). Dopočítávat sazbu z gross/net je křehké u zaokrouhlení. Kontrakt se rozšíří o jednu metodu, `Product` ji implementuje z `tax_rate_id`. Rozšíření kontraktu, ne nový — katalog dál nikdo neobchází.

**6. Dva nezávislé stavové automaty vynucuje service, ne UI.**
`fulfillment` (`new → accepted → processing → shipped → delivered`, storno z ne-koncového stavu → `cancelled`) a `payment` (`unpaid → paid → refunded`) jsou oddělené. Přechod řídí `OrderWorkflow`; nepovolený přechod je výjimka, ne tichý zápis (AK 8). Každý přechod zapíše `order_events` (kdo, kdy, z čeho na co, poznámka, systémový vs. ruční).

**7. Identita košíku = kryptografický `token` v host-only cookie.**
Nikdy autoinkrement (bezpečnost). Uhodnutý token z cizího tenanta nevrátí nic — `carts` je tenant-scoped, hledá se `(tenant_id, token)` (AK 6). Po přihlášení se anonymní košík **připojí** k účtu: položky se sloučí (stejný produkt → sečte množství), nepřepíšou.

**8. Cena dopravy a poplatek platby jsou vlastní řádky totálu, ne rozpuštěné v položkách.**
`orders.items_total`, `shipping_total`, `payment_fee`, `total`. Doprava zdarma od prahu (`free_from`) se počítá ze `items_total` serverem. `vat_summary` grupuje DPH z položek i dopravy/poplatku po sazbách.

---

## File Structure

**Vytvořit — modul `checkout`:**

| Cesta | Odpovědnost |
|-------|-------------|
| `Modules/Checkout/module.json` | Manifest: `requires: {products}`, `provides: {cart}`, storefront nav ne (košík je v hlavičce šablony) |
| `Modules/Checkout/Models/Cart.php` | Košík: token, vazby, expirace, converted_at |
| `Modules/Checkout/Models/CartItem.php` | Položka košíku se snímkem `unit_price` |
| `Modules/Checkout/Database/Migrations/…_create_checkout_tables.php` | `carts`, `cart_items` |
| `Modules/Checkout/Services/EloquentCartRepository.php` | Impl `CartRepository`, gate `ShopModules` |
| `Modules/Checkout/Services/CartPricer.php` | Přepočet košíku ze zdroje pravdy: mezisoučty, doprava zdarma, banner změny ceny, DPH rozpis |
| `Modules/Checkout/Services/CartMerger.php` | Připojení anonymního košíku k účtu po přihlášení |
| `Modules/Checkout/Http/Controllers/CartController.php` | `/kosik` — zobrazit, ± množství, odstranit |
| `Modules/Checkout/Http/Controllers/CheckoutController.php` | `/pokladna/doprava`, `/pokladna/udaje`, odeslání |
| `Modules/Checkout/Http/Controllers/ThankYouController.php` | `/dekujeme/{uuid}` + QR SPAYD |
| `Modules/Checkout/Http/Controllers/CartSummaryController.php` | `GET /api/kosik/souhrn` — mini-košík ostrůvek, `private, no-store` |
| `Modules/Checkout/Http/Requests/*.php` | Form Requests (množství, výběr dopravy/platby, údaje) |
| `Modules/Checkout/Support/Spayd.php` | SPAYD řetězec z účtu + částky + VS |
| `Modules/Checkout/routes/storefront.php` | Storefront routy |
| `Modules/Checkout/Resources/views/…` | Blade: košík, dva kroky pokladny, děkovná stránka |
| `Modules/Checkout/Providers/ModuleProvider.php` | Bind `CartRepository`; naslouchá login eventu pro merge |

**Vytvořit — modul `orders`:**

| Cesta | Odpovědnost |
|-------|-------------|
| `Modules/Orders/module.json` | Manifest: `provides: {order-book}`, práva `orders.view/edit/cancel`, admin nav |
| `Modules/Orders/Models/Order.php`, `OrderItem.php`, `OrderEvent.php` | Modely |
| `Modules/Orders/Database/Migrations/…_create_orders_tables.php` | `orders`, `order_items`, `order_events` |
| `Modules/Orders/Services/OrderPlacer.php` | Impl `OrderPlacement` — transakce odeslání |
| `Modules/Orders/Services/EloquentOrderBook.php` | Impl `OrderBook` — čtení pro admin i účet zákazníka |
| `Modules/Orders/Services/OrderWorkflow.php` | Dvojitý automat, přechody, `order_events` |
| `Modules/Orders/Services/OrderEditor.php` | Editace položek/adres s delta skladem, ruční objednávka, storno |
| `Modules/Orders/Http/Controllers/OrderAdminController.php` | Admin seznam/detail |
| `Modules/Orders/Http/Controllers/OrderStateController.php` | Změny stavů |
| `Modules/Orders/Http/Controllers/OrderEditController.php` | Editace, ruční, storno |
| `Modules/Orders/Http/Requests/*.php` | Form Requests |
| `Modules/Orders/Mail/*.php` | Mailables: potvrzení (zákazník + nájemce), změna stavu, storno |
| `Modules/Orders/Resources/views/mail/*.blade.php` | Šablony e-mailů |
| `Modules/Orders/routes/admin.php` | Admin routy |
| `Modules/Orders/Providers/ModuleProvider.php` | Bind `OrderPlacement`, `OrderBook`; `SequenceService::configure('orders')` |

**Vytvořit — jádro a frontend:**

| Cesta | Odpovědnost |
|-------|-------------|
| `app/Core/Checkout/Contracts/CartRepository.php` | Jak se pracuje s košíkem |
| `app/Core/Checkout/NullCartRepository.php` | Guest-safe default |
| `app/Core/Orders/Contracts/OrderPlacement.php` | Jak checkout založí objednávku |
| `app/Core/Orders/Contracts/PlacedOrder.php` | Read-only shape založené objednávky (uuid, number, total…) |
| `app/Core/Orders/Contracts/OrderBook.php` | Jak admin i účet čtou objednávky |
| `app/Core/Orders/Contracts/OrderView.php` | Read-only shape objednávky pro čtení |
| `app/Core/Orders/NullOrderPlacement.php`, `NullOrderBook.php` | Guest-safe defaulty |
| `app/Core/Orders/Exceptions/OrderPlacementUnavailable.php`, `PriceChanged.php`, `IllegalTransition.php` | Chyby kontraktu |
| `resources/js/Pages/Modules/Orders/Index.vue`, `Show.vue`, `Create.vue` | Admin objednávek |

**Modifikovat:**

| Cesta | Změna |
|-------|-------|
| `app/Providers/AppServiceProvider.php` | Bind tři null kontrakty |
| `app/Core/Catalog/Contracts/CatalogProduct.php` | Přidat `catalogTaxRatePercent(): float` |
| `Modules/Products/Models/Product.php` | Implementovat `catalogTaxRatePercent()` |
| `Modules/Customers/routes/storefront.php` (+ controller) | `/ucet/objednavky`, `/ucet/objednavky/{uuid}` přes `OrderBook` |
| `composer.json` | `endroid/qr-code:^6.1` |

---

### Task 1: Modul `checkout` — tabulky, modely, `CartRepository`

**Files:**
- Create: `Modules/Checkout/module.json`, `Modules/Checkout/Models/Cart.php`, `CartItem.php`, `Modules/Checkout/Database/Migrations/…_create_checkout_tables.php`, `app/Core/Checkout/Contracts/CartRepository.php`, `app/Core/Checkout/NullCartRepository.php`, `Modules/Checkout/Services/EloquentCartRepository.php`, `Modules/Checkout/Providers/ModuleProvider.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Modules/Checkout/CartRepositoryTest.php`

**Interfaces:**
- Consumes: `App\Core\Tenancy\BelongsToTenant`, `App\Core\Money\MoneyCast`, `App\Core\Catalog\Contracts\ProductCatalog`, `Modules\Storefront\Support\ShopModules`
- Produces:
  - `App\Core\Checkout\Contracts\CartRepository` — `forToken(?string $token): Cart` (najde nebo založí, vrátí model implementující shape), `addItem(Cart $cart, int $productId, int $quantity): void`, `setQuantity(Cart $cart, int $itemId, int $quantity): void`, `removeItem(Cart $cart, int $itemId): void`, `attachToCustomer(Cart $cart, int $customerId): void`
  - `Modules\Checkout\Models\Cart` s konstantami stavů a vazbou `items()`

- [ ] **Step 1: Napiš padající test**

Vytvoř `tests/Feature/Modules/Checkout/CartRepositoryTest.php`. Pokryj, každý svou metodou:
1. `forToken(null)` založí nový košík s náhodným tokenem a `expires_at` za 14 dní; dva volání s různým tokenem = dva košíky.
2. `forToken($existing)` vrátí existující košík téhož tenanta.
3. Cizí token jiného tenanta z aktuálního tenanta nevrátí nic (založí nový) — tenant izolace (AK 6). Assertuj přes `runAs($a)` vytvoř, `runAs($b)` `forToken(tokenA)` → jiný košík.
4. `addItem` na produkt založí `cart_item` s `unit_price` = `ProductCatalog::price()` v čase vložení; dvojí `addItem` téhož produktu sečte množství, nezaloží druhý řádek.
5. `setQuantity` na 0 řádek odstraní; `removeItem` odstraní.
6. Tenant bez modulu `checkout`: `EloquentCartRepository` gated přes `ShopModules` — assertuj že bez aktivace vrací prázdný/null košík stejným vzorem jako etapa 3 (resolvuj impl a ověř gate). *(Pozn.: modul provider se v testech vždy načte z disku, takže null binding assertuj přímo přes `new NullCartRepository`.)*

Použij `ActivatesModules`, `TenantContext::runAs`, seed produktu přes `ProductWriter` jako `ProductAdminTest`.

- [ ] **Step 2: Spusť test, potvrď selhání**

Run: `php artisan test --filter=CartRepositoryTest`
Expected: FAIL — třídy neexistují.

- [ ] **Step 3: Manifest**

Vytvoř `Modules/Checkout/module.json`:

```json
{
    "name": "checkout",
    "version": "1.0.0",
    "title": { "cs": "Košík a pokladna" },
    "description": { "cs": "Nákupní košík, pokladna a odeslání objednávky." },
    "core": false,
    "billable": false,
    "level": "base",
    "requires": { "products": "*" },
    "provides": ["cart"],
    "listens": [],
    "permissions": [],
    "settings_schema": null,
    "nav": []
}
```

- [ ] **Step 4: Migrace**

Run: `php artisan make:migration create_checkout_tables --path=Modules/Checkout/Database/Migrations --no-interaction`

```php
Schema::create('carts', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->string('token', 64);
    $table->foreignId('customer_id')->nullable(); // guard customer; ne FK na modul customers
    $table->unsignedBigInteger('shipping_method_id')->nullable();
    $table->unsignedBigInteger('payment_method_id')->nullable();
    $table->json('meta')->nullable();
    $table->timestamp('expires_at')->nullable();
    $table->timestamp('converted_at')->nullable();
    $table->timestamps();
    $table->unique(['tenant_id', 'token']);
});

Schema::create('cart_items', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
    $table->unsignedBigInteger('product_id');
    $table->unsignedInteger('quantity');
    // Snímek ceny při vložení; NENÍ autorita (viz rozhodnutí 2).
    $table->unsignedInteger('unit_price');
    $table->string('currency', 3)->default('CZK');
    $table->timestamps();
    $table->index(['tenant_id', 'cart_id']);
    $table->unique(['tenant_id', 'cart_id', 'product_id'], 'cart_item_unique');
});
```

Pozn.: `customer_id`, `shipping_method_id`, `payment_method_id` **nejsou** FK — míří na jiné moduly, které mohou být vypnuté; cizí klíč přes hranici modulu by z nich udělal nevypnutelné. Integritu drží aplikace.

- [ ] **Step 5: Modely** — `Cart` a `CartItem` s `BelongsToTenant`, `guarded = []`, cast `expires_at`/`converted_at` na `datetime`, `meta` na `array`, `CartItem.unit_price` přes `MoneyCast`, vazba `Cart::items()`.

- [ ] **Step 6: Kontrakt, null, impl** — `CartRepository` (signatury výše), `NullCartRepository` (prázdný přechodný košík / no-op), `EloquentCartRepository` s gate `if (! $this->modules->has('checkout')) …` v každé metodě. Token přes `Str::random(40)` (kryptografické, ne autoinkrement). `addItem` čte `ProductCatalog::price($productId)` pro `unit_price`.

- [ ] **Step 7: Bind** — v jádře null (`AppServiceProvider::register`), v modulu `ModuleProvider::register` přebij `EloquentCartRepository`.

- [ ] **Step 8: Spusť testy** — `CartRepositoryTest` zelený; `SchemaConventionTest`, `ManifestTest` zelené.

- [ ] **Step 9: Commit** `feat: add checkout cart tables, model and CartRepository`

---

### Task 2: Modul `orders` — tabulky, modely, kontrakty `OrderPlacement`/`OrderBook`

**Files:**
- Create: `Modules/Orders/module.json`, modely `Order`/`OrderItem`/`OrderEvent`, migrace, `app/Core/Orders/Contracts/OrderPlacement.php`, `PlacedOrder.php`, `OrderBook.php`, `OrderView.php`, `app/Core/Orders/NullOrderPlacement.php`, `NullOrderBook.php`, `app/Core/Orders/Exceptions/OrderPlacementUnavailable.php`, `PriceChanged.php`, `IllegalTransition.php`, `Modules/Orders/Providers/ModuleProvider.php`
- Modify: `app/Providers/AppServiceProvider.php`, `app/Core/Catalog/Contracts/CatalogProduct.php`, `Modules/Products/Models/Product.php`
- Test: `tests/Feature/Modules/Orders/OrderSchemaTest.php`, `tests/Feature/Modules/Products/CatalogTaxRateTest.php`

**Interfaces:**
- Consumes: `ProductCatalog`, `TaxRates`, `SequenceService`, `ShopModules`
- Produces:
  - `App\Core\Orders\Contracts\OrderPlacement` — `place(PlacementRequest $request): PlacedOrder` (kontrakt transakce, impl v Task 3), `find(string $uuid): ?OrderView`
  - `App\Core\Orders\Contracts\OrderBook` — `forCustomer(int $customerId): Collection<OrderView>`, `findForCustomer(int $customerId, string $uuid): ?OrderView`, `paginateForAdmin(OrderFilter $filter): LengthAwarePaginator`, `findForAdmin(string $uuid): ?OrderView`
  - shapes `PlacedOrder` (`uuid():string`, `number():string`, `total():Money`, `paymentProvider():?string`), `OrderView` (čtecí pohled)
  - `CatalogProduct::catalogTaxRatePercent(): float`

- [ ] **Step 1: Padající testy**

`OrderSchemaTest`: tenant izolace `orders` (AK 6), round-trip peněz (`items_total`/`total` přes `MoneyCast`), `vat_summary`/`billing`/`shipping` jako JSON, `order_items.product_id` nullable a přežije smazání produktu (AK 12 — vytvoř order_item s `product_id`, smaž produkt přes nullOnDelete, řádek zůstane s `product_id=null` a snímkem).

`CatalogTaxRateTest`: `EloquentProductCatalog::findById($id)->catalogTaxRatePercent()` vrátí procento sazby produktu.

- [ ] **Step 2: Spusť, potvrď selhání.**

- [ ] **Step 3: Manifest** `Modules/Orders/module.json`: `provides: ["order-book"]`, `permissions: ["orders.view","orders.edit","orders.cancel"]`, nav `admin.orders.index` (ikona `receipt`, order 50).

- [ ] **Step 4: Migrace** (`create_orders_tables`) — tři tabulky přesně dle spec §Datový model. Klíčové sloupce:

```php
Schema::create('orders', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->uuid('uuid');
    $table->string('number');
    $table->unsignedBigInteger('customer_id')->nullable();
    $table->unsignedBigInteger('cart_id')->nullable();
    $table->string('checkout_token', 64);
    $table->string('source')->default('storefront'); // storefront | manual
    $table->string('email');
    $table->string('phone')->nullable();
    $table->json('billing');
    $table->json('shipping')->nullable();
    $table->json('shipping_snapshot')->nullable();
    $table->json('payment_snapshot')->nullable();
    $table->unsignedInteger('items_total')->default(0);
    $table->unsignedInteger('shipping_total')->default(0);
    $table->unsignedInteger('payment_fee')->default(0);
    $table->unsignedInteger('total')->default(0);
    $table->string('currency', 3)->default('CZK');
    $table->json('vat_summary')->nullable();
    $table->string('fulfillment_status')->default('new');
    $table->string('payment_status')->default('unpaid');
    $table->string('note')->nullable();
    $table->timestamp('placed_at')->nullable();
    $table->timestamps();
    $table->unique(['tenant_id', 'uuid']);
    $table->unique(['tenant_id', 'cart_id', 'checkout_token'], 'order_idem_unique');
    $table->index(['tenant_id', 'fulfillment_status']);
    $table->index(['tenant_id', 'customer_id']);
});
```

`order_items`: `order_id` FK cascade, `tenant_id`, `product_id` nullable (`nullOnDelete` není možný přes hranici modulu — necháme jen nullable a nastavíme null aplikačně při smazání; NEBO product_id bez FK a mažeme přes catalog event — **zvol: product_id bez FK, nullable**, snímky `name`/`sku`/`unit_price`/`tax_rate`(decimal)/`quantity`/`line_total`, `currency`. `order_events`: `order_id` FK cascade, `tenant_id`, `actor_type`(system|admin|customer), `actor_id` nullable, `type`, `from`/`to` nullable, `note` nullable, `payload` JSON nullable. Indexy vedené `tenant_id`; pozor na délku názvu (explicitní jména jako v etapě 3).

- [ ] **Step 5: Modely** — `Order` (casty peněz přes `MoneyCast`, JSON pole na `array`, `uuid`/`placed_at`; konstanty `FULFILLMENT_*` a `PAYMENT_*`; vazby `items()`, `events()`; implementuje `OrderView`), `OrderItem` (`unit_price`/`line_total` MoneyCast, `tax_rate` decimal cast), `OrderEvent`.

- [ ] **Step 6: Rozšíření katalogu** — přidej `catalogTaxRatePercent(): float` do `CatalogProduct`; implementuj v `Product` z `TaxRates::findById($this->tax_rate_id)->percent()` (fallback default rate).

- [ ] **Step 7: Kontrakty, shapes, nully, výjimky** — dle Interfaces. `PlacementRequest` je jednoduchý DTO (cart, doprava/platba id, kontaktní/adresní data, checkout_token, customer_id?, source). Null `OrderPlacement::place()` vyhodí `OrderPlacementUnavailable`; `find()` vrátí null. Null `OrderBook` vrací prázdno/null.

- [ ] **Step 8: Bind** — jádro null (`AppServiceProvider`), modul `ModuleProvider` přebij `OrderPlacer`/`EloquentOrderBook` a v `boot()` `SequenceService::configure('orders', prefix: '', startAt: 1)` (idempotentně).

- [ ] **Step 9: Spusť** `OrderSchemaTest`, `CatalogTaxRateTest`, `SchemaConventionTest`, `ManifestTest` + regrese katalogu (`ProductAdminTest`, storefront testy — rozšíření kontraktu nesmí nic rozbít). Zelené.

- [ ] **Step 10: Commit** `feat: add orders tables, models and placement/orderbook contracts`

---

### Task 3: `OrderPlacer` — transakce odeslání (jádro správnosti)

**Files:**
- Create: `Modules/Orders/Services/OrderPlacer.php`
- Test: `tests/Feature/Modules/Orders/OrderPlacerTest.php`

**Interfaces:**
- Consumes: `ProductCatalog` (`price`, `decrementStock`, `catalogTaxRatePercent` přes `findById`), `SequenceService::next('orders')`, `TaxRates`, modely `Order`/`OrderItem`/`OrderEvent`, `Cart`/`CartItem`
- Produces: `OrderPlacer implements OrderPlacement`; `place(PlacementRequest): PlacedOrder`

Transakce v pořadí ze spec §Odeslání (rozhodnutí 3). Testy (každý svou metodou) — toto je korektnostní jádro, piš je jako první a důkladně:

1. **Šťastná cesta:** z košíku se dvěma položkami vznikne `orders` + dva `order_items` se snímky (název, sku, `unit_price`, `tax_rate`, `line_total`), `items_total`/`shipping_total`/`payment_fee`/`total` a `vat_summary` počítané serverem; `placed_at` vyplněno; `order_events` typu `created`; košík dostal `converted_at`.
2. **Idempotence (AK 2):** dvě volání `place()` se stejným `(cart_id, checkout_token)` vytvoří **jednu** objednávku; druhé vrátí tutéž `PlacedOrder`.
3. **Souběh na posledním kusu (AK 3):** produkt se skladem 1, dvě `place()` (simuluj dvě transakce / dva košíky na tentýž produkt). Jedna uspěje, druhá vyhodí `InsufficientStock`, žádná druhá objednávka, sklad = 0. *(Použij `decrementStock`, který je atomický; assertuj že druhý běh spadl a rollbacknul.)*
4. **Změna ceny (AK 4):** `cart_items.unit_price` ≠ `ProductCatalog::price()` → `place()` vyhodí `PriceChanged` (nese starou i novou cenu), žádná objednávka. Controller z toho udělá banner.
5. **Podvržená cena (AK 5):** i kdyby `PlacementRequest` nesl vlastní částky, `place()` je ignoruje a počítá z katalogu — assertuj že total odpovídá katalogu, ne vstupu.
6. **Doprava zdarma:** `items_total ≥ shipping_method.free_from` → `shipping_total = 0`.
7. **Modul orders vypnutý:** null binding `place()` vyhodí `OrderPlacementUnavailable` (assertuj přes `new NullOrderPlacement`).
8. **Tenant izolace:** objednávka vzniká pod aktuálním tenantem; `find(uuid)` z jiného tenanta vrátí null.

Implementace: `DB::transaction`, na začátku `SELECT` existující objednávky dle idempotence klíče; přepočet přes `ProductCatalog`; `decrementStock` **uvnitř** transakce; `vat_summary` grupuje po `catalogTaxRatePercent`. Commit `feat: add order placement transaction with idempotency and stock safety`.

---

### Task 4: `/kosik` — Blade SSR

**Files:**
- Create: `Modules/Checkout/Http/Controllers/CartController.php`, `Modules/Checkout/Services/CartPricer.php`, `Modules/Checkout/Http/Requests/UpdateCartItemRequest.php`, `Modules/Checkout/routes/storefront.php`, `Modules/Checkout/Resources/views/cart.blade.php`, `Modules/Checkout/Http/Controllers/CartSummaryController.php`
- Modify: šablona storefrontu (tlačítko „do košíku" na detailu produktu → POST), page-cache výjimka routy
- Test: `tests/Feature/Modules/Checkout/CartPageTest.php`

`CartController`: `show` (GET `/kosik`), `add` (POST `/kosik`), `update` (PATCH množství), `remove` (DELETE). `CartPricer` přepočítá ze zdroje pravdy: mezisoučty z `ProductCatalog::price()`, banner „cena se změnila z X na Y" když `unit_price` ≠ aktuální (rozhodnutí 2), lišta „doprava zdarma — zbývá Y Kč" z nejnižšího `free_from` aktivních metod. Cookie s tokenem host-only, `Str::random(40)`. `/kosik` `noindex` a vyřazeno z page cache pravidlem routy.

Testy (přes HTTP formuláře, ne API — testuje variantu bez JS): přidání produktu vytvoří košík a řádek; ± množství a odstranění; **cena v HTML pochází z katalogu, ne z POST** (podvržený POST `price` se ignoruje, AK 5); banner změny ceny když se katalog změní; tenant izolace košíku přes cookie token (AK 6); mini-košík `GET /api/kosik/souhrn` má `Cache-Control: private, no-store`. Commit `feat: add cart page with server-side pricing`.

---

### Task 5: `/pokladna/doprava` — doprava a platba

**Files:**
- Create: `Modules/Checkout/Http/Controllers/CheckoutController.php` (metoda `shipping`, `chooseShipping`), `Modules/Checkout/Http/Requests/ChooseShippingRequest.php`, `Modules/Checkout/Resources/views/checkout/shipping.blade.php`
- Modify: `Modules/Checkout/routes/storefront.php`
- Test: `tests/Feature/Modules/Checkout/CheckoutShippingTest.php`

Radio dopravy z `ShippingOptions::available($weightGrams)` (váha z košíku); po volbě dopravy platby z `PaymentOptions::forShipping($shippingId)` (matice). Změna dopravy = POST + redirect (bez JS). Když `shipping` neběží (`ShippingOptions` vrací prázdno) → vestavěná nouzovka „osobní odběr zdarma" a krok se přeskočí (rozhodnutí 1). Celkovou cenu i filtr plateb počítá server (AK 10).

Testy: `available` respektuje váhu košíku; volba dopravy přefiltruje platby dle matice a přepočte total serverem (AK 10); prázdná matice = všechny aktivní platby (návaznost na etapu 3); vypnutý `shipping` → nouzovka „osobní odběr zdarma"; podvržená cena dopravy v POST ignorována (AK 5). Commit `feat: add checkout shipping and payment step`.

---

### Task 6: `/pokladna/udaje`, odeslání, `/dekujeme/{uuid}`, e-maily, QR

**Files:**
- Create: `CheckoutController::details`, `place`; `ThankYouController`; `Modules/Checkout/Http/Requests/PlaceOrderRequest.php`; `Modules/Checkout/Support/Spayd.php`; Blade `checkout/details.blade.php`, `thank-you.blade.php`; `Modules/Orders/Mail/OrderPlacedCustomer.php`, `OrderPlacedTenant.php` + šablony
- Modify: `composer.json` (`endroid/qr-code:^6.1`), `Modules/Checkout/routes/storefront.php`, `Modules/Orders/Providers/ModuleProvider.php` (naslouchá / job na potvrzovací e-mail po commitu)
- Test: `tests/Feature/Modules/Checkout/PlaceOrderTest.php`

`details`: formulář (e-mail, telefon, jméno, fakturační adresa + firma/IČO, volitelná doručovací adresa, poznámka, souhlasy) + rekapitulace s DPH rozpisem + tlačítko „Objednat s povinností platby". `place`: validace, hidden `checkout_token`, volá `app(OrderPlacement::class)->place(...)`. `PriceChanged` → redirect `/kosik` s bannerem; `InsufficientStock` → redirect `/kosik` s hláškou; úspěch → po commitu zařaď `MailService` potvrzení (zákazník + nájemce, `MailKind::Transactional`), redirect `/dekujeme/{uuid}`. `ThankYouController`: číslo, instrukce; u `bank_transfer` QR ze `Spayd` přes `endroid/qr-code` SvgWriter inline. Kontrola vlastnictví děkovné stránky přes uuid (veřejné, ale bez úniku cizí objednávky — jen data nutná k potvrzení).

Nejdřív `composer require endroid/qr-code:^6.1` (ověř `composer.json` diff, jen tento balíček). Testy (HTTP, bez JS): plný průchod od košíku po `/dekujeme` vytvoří objednávku (AK 1); dvojí submit `place` = jedna objednávka (AK 2); souběh na posledním kusu (AK 3); změna ceny → banner (AK 4); potvrzovací e-mail zařazen (fake mail); QR SVG přítomné u převodu; Lighthouse a11y ≥ 90 na `/kosik` a obou krocích (manuální, zapiš do as-is). Commit `feat: add checkout details, order submission, thank-you page with QR and confirmation emails`.

---

### Task 7: Orders admin — čtení, detail, stavový automat

**Files:**
- Create: `Modules/Orders/Services/OrderWorkflow.php`, `EloquentOrderBook.php`, `Modules/Orders/Http/Controllers/OrderAdminController.php`, `OrderStateController.php`, `Modules/Orders/Http/Requests/ChangeStateRequest.php`, `Modules/Orders/routes/admin.php`, `resources/js/Pages/Modules/Orders/Index.vue`, `Show.vue`
- Test: `tests/Feature/Modules/Orders/OrderWorkflowTest.php`, `OrderAdminTest.php`

`OrderWorkflow` vynucuje dva nezávislé automaty (rozhodnutí 6); nepovolený přechod → `IllegalTransition`, nic nezapíše; povolený zapíše `order_events` (actor, from, to, note, system/manual). `EloquentOrderBook` čte pro admin (`paginateForAdmin` s filtry + badge stavů) i účet (`forCustomer`, `findForCustomer` s kontrolou vlastnictví). Admin: seznam s filtry, rychlá změna stavu; detail (hlavička, dva selecty, položky, adresy, historie, interní poznámky). Autorizace `orders.view`/`orders.edit` přes `user('web')->can`.

Testy: každý zakázaný přechod obou automatů → výjimka a žádný zápis (AK 8); povolený přechod zapíše `order_events`; `paginateForAdmin` tenant-scoped a filtruje; `findForCustomer` cizího zákazníka/tenanta vrátí null (AK 6, 7); bez `orders.view` 403. Commit `feat: add orders admin listing, detail and state machine`.

---

### Task 8: Orders admin — editace, ruční objednávka, storno

**Files:**
- Create: `Modules/Orders/Services/OrderEditor.php`, `Modules/Orders/Http/Controllers/OrderEditController.php`, Form Requests, `Modules/Orders/Mail/OrderStateChanged.php`, `OrderCancelled.php` + šablony, `resources/js/Pages/Modules/Orders/Create.vue`, storno dialog v `Show.vue`
- Modify: `Modules/Orders/routes/admin.php`
- Test: `tests/Feature/Modules/Orders/OrderEditTest.php`

`OrderEditor`: editace položek/adres do stavu `shipped`, sklad se upraví **podle delty** (přidaný kus se odepíše přes `decrementStock`, ubraný vrátí) a přepočte totály; ruční objednávka (`source=manual`, bez online platby); storno (důvod, vrátit sklad ano/ne → přesně odebrané množství zpět, poslat e-mail ano/ne) přes `OrderWorkflow` na `cancelled`. Storno i GDPR mají potvrzovací dialog. Stavové e-maily přes `MailService`.

Testy: editace položky přepočte total a upraví sklad o deltu; ruční objednávka bez online platby; storno s „vrátit sklad" vrátí přesně odebrané množství (AK 9); storno bez vrácení skladu sklad nezmění; `orders.cancel` vyžadováno; e-mail zařazen jen když admin zvolil. Commit `feat: add order editing, manual orders and cancellation`.

---

### Task 9: Účet zákazníka — historie objednávek + docs + verze

**Files:**
- Modify: `Modules/Customers` storefront routy + controller (`/ucet/objednavky`, `/ucet/objednavky/{uuid}`) přes `OrderBook`, Blade views; `docs/as-is/STATUS.md`, `CLAUDE.md`, `VERSION`, `CHANGELOG.md`, nový `docs/as-is/2026-…-checkout.md`
- Test: `tests/Feature/Modules/Customers/AccountOrdersTest.php`

Účet: seznam a detail objednávek přihlášeného zákazníka přes `OrderBook::forCustomer`/`findForCustomer` — **kontrola vlastnictví, ne jen znalost UUID** (AK 7, bezpečnost). Nahradí placeholder z etapy 2. Blade SSR, `noindex`, za guardem `customer`.

Testy: zákazník vidí jen své objednávky; cizí uuid vrátí 404/403 i když existuje (AK 7); host na `/ucet/objednavky` → redirect na přihlášení.

Docs:
- [ ] `php artisan test` celý zelený, zaznamenej počet.
- [ ] `STATUS.md` — řádky `checkout` a `orders` hotové; aktualizuj „Objednávky / pokladna" a verzi.
- [ ] `CLAUDE.md` Rozhodnutí — append: checkout bez `requires` na orders/shipping (runtime gate); cenová autorita `ProductCatalog`, `cart_items.unit_price` jen snímek; odpis skladu uvnitř transakce odeslání; dvojitý automat vynucuje service; `CatalogProduct` rozšířen o `catalogTaxRatePercent`.
- [ ] `VERSION` → `0.12.0` (dva moduly, minor) + `CHANGELOG.md` sekce dle struktury `0.11.0`.
- [ ] `grep -rn` staré verze v `CHANGELOG.md docs/ VERSION`, oprav.
- [ ] Nový `docs/as-is/2026-…-checkout.md` (mapa změn, plnění spec, testy, **Odchylky** povinně, technický dluh, pre-deploy checklist), viz `.claude/rules/as-is-on-milestone.md`.

Commit `docs: record checkout and orders modules and bump to 0.12.0`.

---

## Etapa hotová když

- `php artisan test` zelený.
- S vypnutým JavaScriptem projde zákazník od detailu produktu po `/dekujeme` a vznikne jedna objednávka; dvojí submit vytvoří jednu; souběh na posledním kusu prodá jeden kus jednou.
- Podvržená cena/doprava v POST se ignoruje; změna ceny mezi vložením a odesláním ukáže banner a přepočte.
- Nájemce v adminu objednávku vidí, edituje (sklad dle delty), mění oba stavy (zakázaný přechod = výjimka), zakládá ručně a stornuje (vrácení skladu přesné).
- Tenant A nevidí košíky, objednávky ani zákazníky B; uhodnutý cizí `carts.token` nevrátí nic; zákazník čte jen své objednávky.
- Reset hesla, potvrzení objednávky a stavové e-maily jdou přes `MailService`.

Následující vlny: 1.4 `payments` (online brána, webhook, `/platba/navrat`) se pověsí na `payment_snapshot` a stav `payment`; 1.5 `docs` (faktury, PDF, číselné řady) na hotová `orders`.
