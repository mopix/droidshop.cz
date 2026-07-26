# Vlna 2.5 — Zásilkovna (Packeta) — implementační plán

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Zadání:** [`docs/superpowers/specs/2026-07-27-vlna-25-zasilkovna-design.md`](../specs/2026-07-27-vlna-25-zasilkovna-design.md)

**Goal:** Nájemce prodává s dopravou na výdejní místo Zásilkovny — zákazník si místo vybere v pokladně i bez JavaScriptu, nájemce objednávku podá do Zásilkovny přes API, stáhne štítek a zákazník dostane sledovací odkaz.

**Architecture:** Nový modul `Modules/Packeta` drží driver, HTTP klienta, katalog výdejních míst a zásilky. Existující modul `shipping` drží dál jen konfiguraci metod (přibude hodnota `packeta` v enum `provider`). Jádro dostane kontrakty `Carrier` / `CarrierRegistry` / `PickupPointCatalog` / `ShipmentBook` s null bindingy, takže `checkout` a `orders` se ptají kontraktu a nikdy nesahají na modul — přesná kopie vzoru `PaymentGatewayRegistry` z vlny 1.4.

**Tech Stack:** Laravel 13, PHP 8.3, MySQL 8 (testy MySQL), Blade SSR na storefrontu, Vue 3 + Inertia v adminu, Tailwind, PHPUnit, `Http` fasáda pro Packeta REST/XML.

## Global Constraints

- **PHP 8.3** — žádné property hooks, lazy objects ani `array_find` (rozhodnutí 2026-07-19).
- **Žádná nová composer ani npm závislost.** SOAP se nepoužívá (žádná `ext-soap`), Packeta jede přes `Http` fasádu na REST/XML. JS ostrůvek je vanilla, žádné Alpine (rozhodnutí 2026-07-26).
- **Storefront je Blade SSR.** Výběr výdejního místa musí projít **bez JavaScriptu** (`.claude/rules/storefront-rendering.md`, spec §16.3). Žádná cenová ani rozhodovací logika v JS.
- **Kód anglicky** (názvy, komentáře, commit zprávy), **UI a chat česky**.
- **Tenant izolace:** každá doménová tabulka nese `tenant_id` a index začínající `tenant_id`. Netenantová tabulka musí být v `SchemaConventionTest::PLATFORM_TABLES`.
- **Server-authoritative:** klient posílá jen kód výdejního místa. Název a adresa se vždy čtou z katalogu, nikdy z requestu.
- **`./vendor/bin/pint`** na dotčené soubory před každým commitem.
- **Testy:** `php artisan test --compact --filter=<Test>` po každém tasku; plnou sadu jen jako gate před mergem, **ve foregroundu**.
- Commit zprávy končí:
  ```
  Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
  ```

---

## File Structure

### Jádro (`app/Core/Shipping/`)

| Soubor | Odpovědnost |
|--------|-------------|
| `Contracts/Carrier.php` | driver dopravce — podání, štítky, zrušení, tracking URL |
| `Contracts/CarrierRegistry.php` | `for(provider): ?Carrier` |
| `Contracts/PickupPointCatalog.php` | čtení katalogu výdejních míst |
| `Contracts/PickupPoint.php` | read-only shape jednoho místa |
| `Contracts/ShipmentBook.php` | `forOrder(int): ?ShipmentView` pro detail objednávky |
| `Contracts/ShipmentView.php` | read-only shape zásilky |
| `ShipmentResult.php` | hodnotový objekt návratu z `submit()` |
| `NullCarrierRegistry.php` | `for()` vždy `null` |
| `NullPickupPointCatalog.php` | prázdná kolekce / `null` |
| `NullShipmentBook.php` | `null` |
| `Exceptions/CarrierError.php` | jádrová výjimka (vzor `GatewayError`) |
| `Contracts/ShippingOption.php` | **modify** — přibude `provider()` |

### Modul `Modules/Packeta/`

| Soubor | Odpovědnost |
|--------|-------------|
| `module.json` | manifest, práva `packeta.manage` / `packeta.ship` |
| `Providers/ModuleProvider.php` | bindingy kontraktů, registrace commandu a rout |
| `Database/Migrations/…_create_pickup_points_table.php` | netenantový katalog |
| `Database/Migrations/…_create_shipments_table.php` | tenant-scoped zásilky |
| `Database/Migrations/…_attach_packeta_to_plans.php` | zařazení modulu do tarifů |
| `Models/PickupPoint.php` | implementuje kontrakt `PickupPoint` |
| `Models/Shipment.php` | implementuje `ShipmentView` |
| `Services/PacketaClient.php` | **jen HTTP** — REST/XML volání, žádná doménová logika |
| `Services/PacketaCarrier.php` | driver: mapuje objednávku na packetAttributes |
| `Services/EloquentCarrierRegistry.php` | resolve driveru per tenant |
| `Services/EloquentPickupPointCatalog.php` | hledání v katalogu |
| `Services/EloquentShipmentBook.php` | read pro detail objednávky |
| `Services/ShipmentSubmitter.php` | claim → commit → HTTP → update, idempotence |
| `Services/PickupPointSync.php` | upsert feedu, deaktivace zmizelých |
| `Console/SyncPickupPointsCommand.php` | `packeta:sync-points` |
| `Http/Controllers/DispatchQueueController.php` | expediční fronta (Inertia) |
| `Http/Controllers/ShipmentAdminController.php` | podání, štítky, zrušení |
| `Http/Requests/SubmitShipmentsRequest.php` | validace hromadné akce |
| `routes/admin.php` | admin routy modulu |

### Zásahy do existujících modulů

| Soubor | Změna |
|--------|-------|
| `Modules/Shipping/Database/Migrations/…_add_packeta_provider.php` | **create** — enum `provider` + re-encrypt `settings` |
| `Modules/Shipping/Models/ShippingMethod.php` | `PROVIDER_PACKETA`, `provider()`, cast `encrypted:array` |
| `Modules/Shipping/Services/EloquentShippingOptions.php` | filtr metod bez běžícího driveru |
| `Modules/Shipping/Services/ShippingMethodWriter.php` | keep-on-update pro `apiPassword` |
| `Modules/Shipping/Http/Requests/*ShippingMethodRequest.php` | pole `api_password`, `api_key`, `eshop` |
| `Modules/Checkout/Database/Migrations/…_add_pickup_point_to_carts.php` | **create** |
| `Modules/Checkout/Http/Controllers/PickupPointController.php` | **create** — výběr místa bez JS |
| `Modules/Checkout/Resources/views/checkout/pickup-point.blade.php` | **create** |
| `Modules/Checkout/Resources/views/checkout/shipping.blade.php` | blok vybraného místa |
| `Modules/Checkout/Services/EloquentCartRepository.php` | `choosePickupPoint()`, nulování při změně dopravy |
| `Modules/Orders/Services/OrderPlacer.php` | gate na chybějící místo + snapshot |
| `resources/js/storefront.js` | ostrůvek widgetu |
| `resources/js/Pages/Modules/Orders/Show.vue` | blok Doprava z propu |
| `resources/js/Pages/Modules/Packeta/Dispatch.vue` | **create** — expediční fronta |
| `config/packeta.php` | **create** |
| `tests/Feature/Core/SchemaConventionTest.php` | `pickup_points` na allowlist |

---

## Etapa 1 — kontrakty, provider, šifrování

### Task 1: Kernel kontrakty a null bindingy

**Files:**
- Create: `app/Core/Shipping/Contracts/Carrier.php`, `CarrierRegistry.php`, `PickupPointCatalog.php`, `PickupPoint.php`, `ShipmentBook.php`, `ShipmentView.php`
- Create: `app/Core/Shipping/ShipmentResult.php`, `NullCarrierRegistry.php`, `NullPickupPointCatalog.php`, `NullShipmentBook.php`
- Create: `app/Core/Shipping/Exceptions/CarrierError.php`
- Modify: `app/Providers/AppServiceProvider.php` (registrace null bindingů — najdi, kde se registruje `NullPaymentGatewayRegistry`, a přidej vedle)
- Test: `tests/Feature/Core/CarrierContractsTest.php`

**Interfaces:**
- Produces: `App\Core\Shipping\Contracts\CarrierRegistry::for(string $provider): ?Carrier`; `Carrier::key(): string`, `requiresPickupPoint(): bool`, `submit(OrderView $order, string $pickupPointCode, Money $codAmount, int $weightGrams): ShipmentResult`, `labels(array $shipmentIds, string $format): string`, `cancel(string $packetId): void`, `trackingUrl(string $barcode): string`; `PickupPointCatalog::search(string $query, int $limit = 20): Collection`, `find(string $carrier, string $code): ?PickupPoint`; `ShipmentBook::forOrder(int $orderId): ?ShipmentView`.

- [ ] **Step 1: Napiš failing test**

```php
<?php

namespace Tests\Feature\Core;

use App\Core\Shipping\Contracts\CarrierRegistry;
use App\Core\Shipping\Contracts\PickupPointCatalog;
use App\Core\Shipping\Contracts\ShipmentBook;
use Tests\TestCase;

/**
 * The kernel must answer these three questions safely even when no carrier
 * module is deployed — a storefront on a shop without Packeta may not blow up
 * asking whether a delivery method needs a pickup point.
 */
class CarrierContractsTest extends TestCase
{
    public function test_registry_resolves_to_null_without_a_carrier_module(): void
    {
        $registry = $this->app->make(CarrierRegistry::class);

        $this->assertNull($registry->for('packeta'));
    }

    public function test_pickup_point_catalog_is_empty_without_a_carrier_module(): void
    {
        $catalog = $this->app->make(PickupPointCatalog::class);

        $this->assertTrue($catalog->search('Brno')->isEmpty());
        $this->assertNull($catalog->find('packeta', '12345'));
    }

    public function test_shipment_book_answers_null_without_a_carrier_module(): void
    {
        $book = $this->app->make(ShipmentBook::class);

        $this->assertNull($book->forOrder(1));
    }
}
```

- [ ] **Step 2: Spusť test, ověř selhání**

Run: `php artisan test --compact --filter=CarrierContractsTest`
Expected: FAIL — `Target interface [App\Core\Shipping\Contracts\CarrierRegistry] is not instantiable`

- [ ] **Step 3: Napiš kontrakty**

`app/Core/Shipping/Contracts/Carrier.php`:

```php
<?php

namespace App\Core\Shipping\Contracts;

use App\Core\Money\Money;
use App\Core\Orders\Contracts\OrderView;
use App\Core\Shipping\ShipmentResult;

/**
 * One carrier's API driver (spec §16.5).
 *
 * The same shape PaymentGateway has for payment providers: checkout and the
 * admin resolve a driver by provider key through CarrierRegistry and never
 * import a module class. Adding PPL or Balíkovna is another driver plus one
 * arm of the registry's match, with no change to checkout.
 */
interface Carrier
{
    /** The `shipping_methods.provider` value this driver answers to. */
    public function key(): string;

    /**
     * Whether an order using this carrier cannot be placed without a chosen
     * pickup point. Checkout asks this, not the module.
     */
    public function requiresPickupPoint(): bool;

    /**
     * Hands the order to the carrier and returns their identifiers.
     *
     * The caller supplies the COD amount and weight rather than letting the
     * driver derive them, so the one authority on "how much money is on this
     * packet" stays where the order total is known.
     *
     * @throws \App\Core\Shipping\Exceptions\CarrierError
     */
    public function submit(OrderView $order, string $pickupPointCode, Money $codAmount, int $weightGrams): ShipmentResult;

    /**
     * A print-ready PDF for our own shipment ids (not the carrier's packet
     * ids — the driver looks those up itself, so no caller has to know the
     * carrier's identifier scheme).
     *
     * @param  list<int>  $shipmentIds
     *
     * @throws \App\Core\Shipping\Exceptions\CarrierError
     */
    public function labels(array $shipmentIds, string $format): string;

    /**
     * @throws \App\Core\Shipping\Exceptions\CarrierError
     */
    public function cancel(string $packetId): void;

    public function trackingUrl(string $barcode): string;
}
```

`app/Core/Shipping/Contracts/CarrierRegistry.php`:

```php
<?php

namespace App\Core\Shipping\Contracts;

/**
 * The one place checkout, orders and the admin resolve a carrier driver — by
 * provider key, never by class. The kernel binds NullCarrierRegistry; a
 * carrier module overrides it.
 *
 * A null answer means "this shop cannot ship with that provider right now",
 * which is exactly what a deactivated module should look like from outside.
 */
interface CarrierRegistry
{
    public function for(string $provider): ?Carrier;

    /**
     * Provider keys that are both running and configured.
     *
     * @return list<string>
     */
    public function available(): array;
}
```

`app/Core/Shipping/Contracts/PickupPoint.php`:

```php
<?php

namespace App\Core\Shipping\Contracts;

/**
 * One pickup point as the storefront and the order snapshot see it.
 *
 * A read-only shape, not the Eloquent model: checkout renders and snapshots a
 * point without depending on the carrier module's table.
 */
interface PickupPoint
{
    public function pointCarrier(): string;

    public function pointCode(): string;

    public function pointName(): string;

    public function pointStreet(): string;

    public function pointCity(): string;

    public function pointZip(): string;

    /** @return array<string, mixed>|null */
    public function pointOpeningHours(): ?array;
}
```

`app/Core/Shipping/Contracts/PickupPointCatalog.php`:

```php
<?php

namespace App\Core\Shipping\Contracts;

use Illuminate\Support\Collection;

/**
 * Reads the shared pickup point catalogue (spec §16.5).
 *
 * The catalogue is platform-wide, not per tenant: every shop delivering to
 * Zásilkovna resolves the same points, so one sync feeds all of them.
 */
interface PickupPointCatalog
{
    /**
     * Active points matching a free-text query (town, zip or name).
     *
     * @return Collection<int, PickupPoint>
     */
    public function search(string $query, int $limit = 20): Collection;

    /**
     * One active point by its carrier identifier, or null when the code is
     * unknown or the point has been deactivated — the guard that keeps a
     * stale widget answer from becoming an unshippable order.
     */
    public function find(string $carrier, string $code): ?PickupPoint;
}
```

`app/Core/Shipping/Contracts/ShipmentView.php`:

```php
<?php

namespace App\Core\Shipping\Contracts;

use App\Core\Money\Money;
use Illuminate\Support\Carbon;

/**
 * What a caller outside the carrier module may rely on about a shipment.
 *
 * Mirrors OrderView: every accessor is prefixed `shipment` so it can never
 * collide with an Eloquent relation on the model implementing it.
 */
interface ShipmentView
{
    public function shipmentId(): int;

    public function shipmentCarrier(): string;

    public function shipmentStatus(): string;

    public function shipmentPacketId(): ?string;

    public function shipmentBarcode(): ?string;

    public function shipmentCodAmount(): Money;

    public function shipmentError(): ?string;

    public function shipmentSubmittedAt(): ?Carbon;
}
```

`app/Core/Shipping/Contracts/ShipmentBook.php`:

```php
<?php

namespace App\Core\Shipping\Contracts;

/**
 * Read side of shipments, for the order detail screen (wave 2.5).
 *
 * The same read/write split docs keeps between DocumentBook and
 * DocumentIssuer: the orders module renders a shipment block from this
 * contract and never imports the carrier module's model. When no carrier
 * module runs, forOrder() answers null and the block disappears.
 */
interface ShipmentBook
{
    public function forOrder(int $orderId): ?ShipmentView;
}
```

`app/Core/Shipping/ShipmentResult.php`:

```php
<?php

namespace App\Core\Shipping;

/**
 * What a carrier hands back after accepting a packet.
 *
 * A value object rather than an array so a driver cannot quietly stop
 * returning the barcode the tracking link is built from.
 */
final class ShipmentResult
{
    public function __construct(
        public readonly string $packetId,
        public readonly string $barcode,
    ) {}
}
```

`app/Core/Shipping/Exceptions/CarrierError.php`:

```php
<?php

namespace App\Core\Shipping\Exceptions;

use RuntimeException;

/**
 * A carrier's API refused or could not be reached (wave 2.5).
 *
 * A kernel exception, like GatewayError, so the admin can catch it without
 * importing the carrier module.
 */
class CarrierError extends RuntimeException
{
    public static function unreachable(string $carrier, string $reason): self
    {
        return new self(sprintf('Dopravce %s neodpověděl: %s', $carrier, $reason));
    }

    public static function rejected(string $carrier, string $reason): self
    {
        return new self(sprintf('Dopravce %s odmítl zásilku: %s', $carrier, $reason));
    }

    public static function notConfigured(string $carrier): self
    {
        return new self(sprintf('Dopravce %s nemá vyplněné přístupové údaje.', $carrier));
    }
}
```

- [ ] **Step 4: Napiš null bindingy**

`app/Core/Shipping/NullCarrierRegistry.php`:

```php
<?php

namespace App\Core\Shipping;

use App\Core\Shipping\Contracts\Carrier;
use App\Core\Shipping\Contracts\CarrierRegistry;

/**
 * No carrier module deployed or active: nothing ships through an API.
 *
 * Guest-safe by construction — a shop without the module offers no
 * API-backed delivery instead of erroring on a missing class.
 */
final class NullCarrierRegistry implements CarrierRegistry
{
    public function for(string $provider): ?Carrier
    {
        return null;
    }

    public function available(): array
    {
        return [];
    }
}
```

`app/Core/Shipping/NullPickupPointCatalog.php`:

```php
<?php

namespace App\Core\Shipping;

use App\Core\Shipping\Contracts\PickupPoint;
use App\Core\Shipping\Contracts\PickupPointCatalog;
use Illuminate\Support\Collection;

final class NullPickupPointCatalog implements PickupPointCatalog
{
    public function search(string $query, int $limit = 20): Collection
    {
        return new Collection;
    }

    public function find(string $carrier, string $code): ?PickupPoint
    {
        return null;
    }
}
```

`app/Core/Shipping/NullShipmentBook.php`:

```php
<?php

namespace App\Core\Shipping;

use App\Core\Shipping\Contracts\ShipmentBook;
use App\Core\Shipping\Contracts\ShipmentView;

final class NullShipmentBook implements ShipmentBook
{
    public function forOrder(int $orderId): ?ShipmentView
    {
        return null;
    }
}
```

Registrace v `app/Providers/AppServiceProvider.php` — vedle existující `NullPaymentGatewayRegistry`:

```php
$this->app->bind(CarrierRegistry::class, NullCarrierRegistry::class);
$this->app->bind(PickupPointCatalog::class, NullPickupPointCatalog::class);
$this->app->bind(ShipmentBook::class, NullShipmentBook::class);
```

- [ ] **Step 5: Spusť test, ověř průchod**

Run: `php artisan test --compact --filter=CarrierContractsTest`
Expected: PASS (3 testy)

- [ ] **Step 6: Pint a commit**

```bash
./vendor/bin/pint app/Core/Shipping app/Providers/AppServiceProvider.php tests/Feature/Core/CarrierContractsTest.php
git add app/Core/Shipping app/Providers/AppServiceProvider.php tests/Feature/Core/CarrierContractsTest.php
git commit -m "feat(shipping): carrier contracts with guest-safe null bindings

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: `provider()` na `ShippingOption` a hodnota `packeta`

**Files:**
- Modify: `app/Core/Shipping/Contracts/ShippingOption.php`
- Modify: `Modules/Shipping/Models/ShippingMethod.php`
- Create: `Modules/Shipping/Database/Migrations/2026_07_27_090000_add_packeta_provider_to_shipping_methods.php`
- Test: `tests/Feature/Modules/Shipping/PacketaProviderTest.php`

**Interfaces:**
- Consumes: nic z Tasku 1 (nezávislé).
- Produces: `ShippingOption::provider(): string`; konstanta `ShippingMethod::PROVIDER_PACKETA = 'packeta'`.

- [ ] **Step 1: Napiš failing test**

```php
<?php

namespace Tests\Feature\Modules\Shipping;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Shipping\Models\ShippingMethod;
use Tests\TestCase;

class PacketaProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_shipping_method_can_be_stored_with_the_packeta_provider(): void
    {
        $tenant = Tenant::factory()->create();

        $method = tenancy()->runAs($tenant, fn () => ShippingMethod::create([
            'provider' => ShippingMethod::PROVIDER_PACKETA,
            'name' => 'Zásilkovna',
            'price' => 8900,
            'currency' => 'CZK',
        ]));

        $this->assertSame('packeta', $method->fresh()->provider);
    }

    public function test_shipping_option_exposes_its_provider(): void
    {
        $tenant = Tenant::factory()->create();

        $method = tenancy()->runAs($tenant, fn () => ShippingMethod::create([
            'provider' => ShippingMethod::PROVIDER_PICKUP,
            'name' => 'Osobní odběr',
            'price' => 0,
            'currency' => 'CZK',
        ]));

        $this->assertSame('pickup', $method->provider());
    }
}
```

> **Pozn. k `tenancy()->runAs`:** ověř v existujících testech modulu `shipping`, jakým helperem se v projektu nastavuje tenant kontext, a použij tentýž — nevymýšlej nový.

- [ ] **Step 2: Spusť test, ověř selhání**

Run: `php artisan test --compact --filter=PacketaProviderTest`
Expected: FAIL — SQL chyba na enum (`Data truncated for column 'provider'`) a `Call to undefined method provider()`

- [ ] **Step 3: Migrace enum**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the packeta provider (wave 2.5).
 *
 * The provider column was created as an enum in wave 1.3, so a new carrier
 * genuinely needs a schema change — the model's original comment claiming
 * otherwise was wrong. Raw ALTER rather than a Blueprint change: Laravel has
 * no portable enum-widening primitive, and doctrine/dbal is not installed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            // SQLite stores enums as plain text and accepts the new value as
            // it is; nothing to widen.
            return;
        }

        DB::statement("ALTER TABLE shipping_methods MODIFY provider ENUM('pickup', 'flat', 'packeta') NOT NULL");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::table('shipping_methods')->where('provider', 'packeta')->delete();

        DB::statement("ALTER TABLE shipping_methods MODIFY provider ENUM('pickup', 'flat') NOT NULL");
    }
};
```

- [ ] **Step 4: Doplň kontrakt a model**

Do `app/Core/Shipping/Contracts/ShippingOption.php` přidej:

```php
    /**
     * The carrier key behind this option (`pickup`, `flat`, `packeta`).
     *
     * Checkout needs it to ask CarrierRegistry whether the option requires a
     * pickup point; it deliberately does not ask the shipping module, which
     * knows nothing about carriers' APIs.
     */
    public function provider(): string;
```

Do `Modules/Shipping/Models/ShippingMethod.php`:

```php
    public const PROVIDER_PACKETA = 'packeta';
```

a metodu:

```php
    public function provider(): string
    {
        return $this->attributes['provider'];
    }
```

> **Pozor:** metoda se jmenuje stejně jako sloupec. Čtení přes `$this->attributes['provider']` (ne `$this->provider`) je nutné, jinak by Eloquent `__get` narazil na metodu a vrátil ji místo hodnoty.

- [ ] **Step 5: Spusť testy**

Run: `php artisan test --compact --filter=PacketaProviderTest`
Expected: PASS (2 testy)

Run: `php artisan test --compact --filter="Shipping|Checkout"`
Expected: PASS — nic se nesmí rozbít přidáním metody do kontraktu

- [ ] **Step 6: Pint a commit**

```bash
./vendor/bin/pint app/Core/Shipping Modules/Shipping tests/Feature/Modules/Shipping/PacketaProviderTest.php
git add app/Core/Shipping Modules/Shipping tests/Feature/Modules/Shipping/PacketaProviderTest.php
git commit -m "feat(shipping): packeta provider and provider() on the option contract

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Šifrování `shipping_methods.settings` + credentials v adminu

**Files:**
- Create: `Modules/Shipping/Database/Migrations/2026_07_27_090100_encrypt_shipping_method_settings.php`
- Modify: `Modules/Shipping/Models/ShippingMethod.php` (cast `settings`)
- Modify: `Modules/Shipping/Services/ShippingMethodWriter.php`
- Modify: `Modules/Shipping/Http/Requests/StoreShippingMethodRequest.php`, `UpdateShippingMethodRequest.php`
- Modify: `Modules/Shipping/Http/Controllers/ShippingMethodAdminController.php` (maskování)
- Test: `tests/Feature/Modules/Shipping/ShippingCredentialsTest.php`

**Interfaces:**
- Consumes: `ShippingMethod::PROVIDER_PACKETA` (Task 2).
- Produces: `settings` klíče `api_password`, `api_key`, `eshop`, `default_weight_g`; keep-on-update chování — prázdné `api_password` na update ponechá uložené.

- [ ] **Step 1: Napiš failing test**

```php
<?php

namespace Tests\Feature\Modules\Shipping;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Shipping\Models\ShippingMethod;
use Tests\TestCase;

class ShippingCredentialsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_are_encrypted_at_rest(): void
    {
        $tenant = Tenant::factory()->create();

        $method = tenancy()->runAs($tenant, fn () => ShippingMethod::create([
            'provider' => ShippingMethod::PROVIDER_PACKETA,
            'name' => 'Zásilkovna',
            'price' => 8900,
            'currency' => 'CZK',
            'settings' => ['api_password' => 'super-secret-password'],
        ]));

        $raw = DB::table('shipping_methods')->where('id', $method->id)->value('settings');

        $this->assertStringNotContainsString('super-secret-password', (string) $raw);
        $this->assertSame('super-secret-password', $method->fresh()->settings['api_password']);
    }

    public function test_pickup_settings_written_before_the_change_still_read(): void
    {
        // The migration re-encrypts existing plaintext rows; a method configured
        // in wave 1.3 must keep working after deploy.
        $tenant = Tenant::factory()->create();

        $method = tenancy()->runAs($tenant, fn () => ShippingMethod::create([
            'provider' => ShippingMethod::PROVIDER_PICKUP,
            'name' => 'Osobní odběr',
            'price' => 0,
            'currency' => 'CZK',
            'settings' => ['address' => 'Nádražní 1, Brno', 'hours' => 'Po–Pá 9–17'],
        ]));

        $this->assertSame('Nádražní 1, Brno', $method->fresh()->settings['address']);
    }
}
```

- [ ] **Step 2: Spusť test, ověř selhání**

Run: `php artisan test --compact --filter=ShippingCredentialsTest`
Expected: FAIL — `assertStringNotContainsString` najde heslo v plaintextu

- [ ] **Step 3: Migrace re-encryptu**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Encrypts shipping_methods.settings (wave 2.5).
 *
 * The wave 1.3 decision "delivery settings are not secret" held only while the
 * column carried a pickup address and opening hours. Packeta's apiPassword is
 * a credential (spec §16.5), so the column joins payment_methods.settings in
 * being encrypted at rest — and the existing plaintext rows must be rewritten,
 * or the cast would fail to decrypt them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipping_methods', function (Blueprint $table) {
            // json -> text: ciphertext is not valid JSON.
            $table->text('settings')->nullable()->change();
        });

        DB::table('shipping_methods')
            ->whereNotNull('settings')
            ->orderBy('id')
            ->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    $decoded = json_decode((string) $row->settings, true);

                    if (! is_array($decoded)) {
                        // Already ciphertext (re-run), or unreadable: leave it.
                        continue;
                    }

                    DB::table('shipping_methods')
                        ->where('id', $row->id)
                        ->update(['settings' => Crypt::encryptString(json_encode($decoded))]);
                }
            });
    }

    public function down(): void
    {
        DB::table('shipping_methods')
            ->whereNotNull('settings')
            ->orderBy('id')
            ->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    try {
                        $plain = Crypt::decryptString((string) $row->settings);
                    } catch (\Throwable) {
                        continue;
                    }

                    DB::table('shipping_methods')->where('id', $row->id)->update(['settings' => $plain]);
                }
            });

        Schema::table('shipping_methods', function (Blueprint $table) {
            $table->json('settings')->nullable()->change();
        });
    }
};
```

> `->change()` bez `doctrine/dbal` funguje na Laravelu 11+ nativně. Ověř, že migrace projde i na SQLite, pokud ji projekt v testech používá — projekt jede testy na MySQL, takže primární je MySQL.

- [ ] **Step 4: Cast, writer, request, maskování**

Model `ShippingMethod::casts()`:

```php
            // Holds a credential since wave 2.5 (Packeta apiPassword), so it is
            // encrypted at rest exactly like payment_methods.settings. The
            // pickup address inside it is not secret; the column is, because
            // one column cannot be half-encrypted.
            'settings' => 'encrypted:array',
```

`ShippingMethodWriter` — přidej fold podle vzoru `PaymentMethodWriter::foldSecret()`:

```php
    /**
     * Folds submitted carrier credentials into the encrypted settings.
     *
     * A blank api_password on update means "keep the stored one": the admin
     * only ever sees a mask, so re-typing the password just to rename the
     * method would be a trap that silently wipes it.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $input
     */
    private function foldSettings(array $attributes, array $input, ?ShippingMethod $existing): array
    {
        if ($input['provider'] !== ShippingMethod::PROVIDER_PACKETA) {
            $attributes['settings'] = $input['settings'] ?? null;

            return $attributes;
        }

        $settings = [
            'api_key' => (string) ($input['api_key'] ?? ''),
            'eshop' => (string) ($input['eshop'] ?? ''),
            'default_weight_g' => (int) ($input['default_weight_g'] ?? 1000),
        ];

        $submitted = trim((string) ($input['api_password'] ?? ''));

        if ($submitted !== '') {
            $settings['api_password'] = $submitted;
        } else {
            $stored = $existing?->settings['api_password'] ?? null;

            if ($stored !== null) {
                $settings['api_password'] = $stored;
            }
        }

        $attributes['settings'] = $settings;

        return $attributes;
    }
```

FormRequesty — `api_password` `required` při create s providerem `packeta`, `nullable` při update; `api_key` a `eshop` vždy `required` pro `packeta`:

```php
            'api_password' => [Rule::requiredIf($this->input('provider') === ShippingMethod::PROVIDER_PACKETA), 'nullable', 'string', 'max:255'],
            'api_key' => [Rule::requiredIf($this->input('provider') === ShippingMethod::PROVIDER_PACKETA), 'nullable', 'string', 'max:64'],
            'eshop' => [Rule::requiredIf($this->input('provider') === ShippingMethod::PROVIDER_PACKETA), 'nullable', 'string', 'max:64'],
            'default_weight_g' => ['nullable', 'integer', 'min:1', 'max:30000'],
```

> Ve `StoreShippingMethodRequest` je `requiredIf` bez `nullable` (create musí heslo mít), v `UpdateShippingMethodRequest` jen `nullable` (blank = ponechat).

Controller — do propů posílej maskovanou hodnotu, nikdy skutečnou:

```php
            'has_api_password' => filled($method->settings['api_password'] ?? null),
```

a heslo samotné **nikdy** neposílej do Inertia propů.

- [ ] **Step 5: Spusť testy**

Run: `php artisan test --compact --filter=ShippingCredentialsTest`
Expected: PASS (2 testy)

Run: `php artisan test --compact --filter=Shipping`
Expected: PASS — existující admin testy dopravy musí projít beze změny chování

- [ ] **Step 6: Doplň test na keep-on-update a maskování**

```php
    public function test_blank_password_on_update_keeps_the_stored_one(): void
    {
        // Sestav tenant + přihlášeného člena s právem shipping.manage podle
        // vzoru v existujícím ShippingMethodAdminTest, ulož metodu s heslem,
        // pak ji patchni bez api_password a čekej, že heslo zůstalo.
    }

    public function test_admin_props_never_carry_the_api_password(): void
    {
        // GET na editaci metody: assertInertia, že prop nese jen has_api_password.
    }
```

Doplň těla podle existujícího `ShippingMethodAdminTest` (stejný setup tenanta a oprávnění).

- [ ] **Step 7: Pint a commit**

```bash
./vendor/bin/pint Modules/Shipping tests/Feature/Modules/Shipping
git add Modules/Shipping tests/Feature/Modules/Shipping
git commit -m "feat(shipping): encrypt method settings, carrier credentials in admin

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Etapa 2 — katalog výdejních míst

### Task 4: Tabulka `pickup_points`, model, katalog

**Files:**
- Create: `Modules/Packeta/module.json`, `Providers/ModuleProvider.php`
- Create: `Modules/Packeta/Database/Migrations/2026_07_27_100000_create_pickup_points_table.php`
- Create: `Modules/Packeta/Models/PickupPoint.php`
- Create: `Modules/Packeta/Services/EloquentPickupPointCatalog.php`
- Modify: `tests/Feature/Core/SchemaConventionTest.php`
- Test: `tests/Feature/Modules/Packeta/PickupPointCatalogTest.php`

**Interfaces:**
- Consumes: `App\Core\Shipping\Contracts\PickupPoint`, `PickupPointCatalog` (Task 1).
- Produces: model `Modules\Packeta\Models\PickupPoint` (bez `BelongsToTenant`), `EloquentPickupPointCatalog`, statická `PickupPoint::normalise(string): string`.

- [ ] **Step 1: Napiš failing test**

```php
<?php

namespace Tests\Feature\Modules\Packeta;

use App\Core\Shipping\Contracts\PickupPointCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Packeta\Models\PickupPoint;
use Tests\TestCase;

class PickupPointCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        PickupPoint::create([
            'carrier' => 'packeta', 'code' => '1001', 'name' => 'Žabovřesky — Večerka',
            'street' => 'Horova 1', 'city' => 'Brno', 'zip' => '61600', 'country' => 'CZ',
            'search_text' => PickupPoint::normalise('Žabovřesky — Večerka Horova 1 Brno 61600'),
            'is_active' => true,
        ]);

        PickupPoint::create([
            'carrier' => 'packeta', 'code' => '1002', 'name' => 'Praha — Trafika',
            'street' => 'Vinohradská 5', 'city' => 'Praha', 'zip' => '13000', 'country' => 'CZ',
            'search_text' => PickupPoint::normalise('Praha — Trafika Vinohradská 5 Praha 13000'),
            'is_active' => false,
        ]);
    }

    public function test_search_matches_without_diacritics(): void
    {
        $catalog = $this->app->make(PickupPointCatalog::class);

        $hit = $catalog->search('zabovresky');

        $this->assertCount(1, $hit);
        $this->assertSame('1001', $hit->first()->pointCode());
    }

    public function test_search_matches_by_zip(): void
    {
        $this->assertCount(1, $this->app->make(PickupPointCatalog::class)->search('61600'));
    }

    public function test_search_skips_inactive_points(): void
    {
        $this->assertCount(0, $this->app->make(PickupPointCatalog::class)->search('Trafika'));
    }

    public function test_find_returns_null_for_an_inactive_point(): void
    {
        $catalog = $this->app->make(PickupPointCatalog::class);

        $this->assertNotNull($catalog->find('packeta', '1001'));
        $this->assertNull($catalog->find('packeta', '1002'));
        $this->assertNull($catalog->find('packeta', 'nope'));
    }
}
```

- [ ] **Step 2: Spusť test, ověř selhání**

Run: `php artisan test --compact --filter=PickupPointCatalogTest`
Expected: FAIL — `Class "Modules\Packeta\Models\PickupPoint" not found`

- [ ] **Step 3: Manifest a provider modulu**

`Modules/Packeta/module.json`:

```json
{
    "name": "packeta",
    "version": "1.0.0",
    "title": {
        "cs": "Zásilkovna"
    },
    "description": {
        "cs": "Doprava na výdejní místa Zásilkovny — výběr místa v pokladně, podání zásilek, štítky a sledování."
    },
    "core": false,
    "billable": false,
    "level": "base",
    "requires": {},
    "provides": [
        "carrier-packeta"
    ],
    "listens": [],
    "permissions": [
        "packeta.manage",
        "packeta.ship"
    ],
    "settings_schema": null,
    "nav": [
        {
            "area": "admin",
            "label": "Expedice",
            "route": "admin.packeta.dispatch",
            "icon": "package",
            "order": 45
        }
    ]
}
```

> **Bez `requires`** — stejný důvod jako u `checkout` (rozhodnutí 2026-07-21): deklarovaná závislost na `shipping` by ze `shipping` udělala nevypnutelný modul. Runtime gate dělá `ShopModules`.

`Modules/Packeta/Providers/ModuleProvider.php`:

```php
<?php

namespace Modules\Packeta\Providers;

use App\Core\Shipping\Contracts\CarrierRegistry;
use App\Core\Shipping\Contracts\PickupPointCatalog;
use App\Core\Shipping\Contracts\ShipmentBook;
use Illuminate\Support\ServiceProvider;
use Modules\Packeta\Services\EloquentPickupPointCatalog;

class ModuleProvider extends ServiceProvider
{
    public function register(): void
    {
        // Overrides the kernel's null bindings. Per-tenant activation is
        // answered at call time inside the implementations by ShopModules,
        // not here — this binding is per deploy.
        $this->app->bind(PickupPointCatalog::class, EloquentPickupPointCatalog::class);
    }
}
```

> `CarrierRegistry` a `ShipmentBook` se sem doplní v Tasku 10 a 15; teď je nech nezaregistrované, ať Task 4 stojí samostatně.

- [ ] **Step 4: Migrace, model, katalog**

Migrace:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The shared pickup point catalogue (wave 2.5).
 *
 * Deliberately without tenant_id: every shop delivering to Zásilkovna resolves
 * the very same points, so one platform-wide sync feeds all of them — the same
 * class of table as plans or tax_rates. Listed in
 * SchemaConventionTest::PLATFORM_TABLES.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pickup_points', function (Blueprint $table) {
            $table->id();

            $table->string('carrier', 20);
            $table->string('code', 40);

            $table->string('name');
            $table->string('street')->default('');
            $table->string('city')->default('');
            $table->string('zip', 10)->default('');
            $table->char('country', 2)->default('CZ');

            // Diacritics stripped and lowercased at write time, so a LIKE can
            // match "zabovresky" against "Žabovřesky" — the same normalisation
            // products.search_text uses (rozhodnutí 2026-07-20). InnoDB
            // fulltext is not an option: it handles neither Czech inflection
            // nor SQLite in tests.
            $table->string('search_text', 512)->default('');

            $table->json('opening_hours')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamp('synced_at')->nullable();

            $table->timestamps();

            $table->unique(['carrier', 'code']);
            $table->index(['carrier', 'country', 'zip']);
            $table->index('search_text');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pickup_points');
    }
};
```

Model:

```php
<?php

namespace Modules\Packeta\Models;

use App\Core\Shipping\Contracts\PickupPoint as PickupPointContract;
use Illuminate\Database\Eloquent\Model;

/**
 * One carrier pickup point (wave 2.5).
 *
 * No BelongsToTenant on purpose — the catalogue is platform-wide (see the
 * migration). Implements the kernel's read-only shape directly, the way
 * ShippingMethod implements ShippingOption, so checkout never touches it.
 */
class PickupPoint extends Model implements PickupPointContract
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'opening_hours' => 'array',
            'is_active' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * Lowercase, diacritics stripped — the form both search_text and every
     * query term are put in, so the two can be compared at all.
     */
    public static function normalise(string $value): string
    {
        $ascii = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $value);

        return trim(preg_replace('/\s+/', ' ', (string) $ascii) ?? '');
    }

    public function pointCarrier(): string
    {
        return $this->carrier;
    }

    public function pointCode(): string
    {
        return $this->code;
    }

    public function pointName(): string
    {
        return $this->name;
    }

    public function pointStreet(): string
    {
        return $this->street;
    }

    public function pointCity(): string
    {
        return $this->city;
    }

    public function pointZip(): string
    {
        return $this->zip;
    }

    public function pointOpeningHours(): ?array
    {
        return $this->opening_hours;
    }
}
```

> `transliterator_transliterate` je z `ext-intl`, kterou Laravel už vyžaduje. Ověř, že ji projekt používá i jinde (`grep -rn "transliterator" app/ Modules/`); pokud ne, použij tutéž normalizaci, jakou dělá `products.search_text`.

Katalog:

```php
<?php

namespace Modules\Packeta\Services;

use App\Core\Shipping\Contracts\PickupPoint as PickupPointContract;
use App\Core\Shipping\Contracts\PickupPointCatalog;
use Illuminate\Support\Collection;
use Modules\Packeta\Models\PickupPoint;

/**
 * Reads the shared pickup point catalogue.
 *
 * No ShopModules gate here, unlike the tenant-scoped services: the catalogue
 * carries no tenant data, and a shop that cannot offer Zásilkovna never gets
 * as far as searching it — the delivery method is filtered out first.
 */
final class EloquentPickupPointCatalog implements PickupPointCatalog
{
    public function search(string $query, int $limit = 20): Collection
    {
        $term = PickupPoint::normalise($query);

        if ($term === '') {
            return new Collection;
        }

        return PickupPoint::query()
            ->where('is_active', true)
            ->where('search_text', 'like', '%'.$term.'%')
            ->orderBy('city')
            ->orderBy('name')
            ->limit(max(1, min($limit, 100)))
            ->get();
    }

    public function find(string $carrier, string $code): ?PickupPointContract
    {
        return PickupPoint::query()
            ->where('carrier', $carrier)
            ->where('code', $code)
            ->where('is_active', true)
            ->first();
    }
}
```

- [ ] **Step 5: Allowlist v `SchemaConventionTest`**

Do `PLATFORM_TABLES` přidej:

```php
        // Carrier pickup points (wave 2.5). Non-tenant on purpose: every shop
        // delivering to Zásilkovna resolves the same physical points, so one
        // platform-wide sync feeds all tenants.
        'pickup_points',
```

- [ ] **Step 6: Spusť testy**

Run: `php artisan modules:sync`
Expected: registr obsahuje `packeta`

Run: `php artisan test --compact --filter="PickupPointCatalogTest|SchemaConventionTest"`
Expected: PASS

- [ ] **Step 7: Pint a commit**

```bash
./vendor/bin/pint Modules/Packeta tests
git add Modules/Packeta tests/Feature/Core/SchemaConventionTest.php tests/Feature/Modules/Packeta
git commit -m "feat(packeta): shared pickup point catalogue

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: Sync feedu výdejních míst

**Files:**
- Create: `config/packeta.php`
- Create: `Modules/Packeta/Services/PickupPointSync.php`
- Create: `Modules/Packeta/Console/SyncPickupPointsCommand.php`
- Modify: `Modules/Packeta/Providers/ModuleProvider.php` (registrace commandu)
- Modify: `routes/console.php` nebo `bootstrap/app.php` — kam projekt plánuje `domains:sweep-pending`, tam přidej denní `packeta:sync-points`
- Test: `tests/Feature/Modules/Packeta/PickupPointSyncTest.php`

**Interfaces:**
- Consumes: `Modules\Packeta\Models\PickupPoint` (Task 4).
- Produces: `PickupPointSync::run(string $apiKey): array{created:int,updated:int,deactivated:int}`; command `packeta:sync-points`.

- [ ] **Step 1: Napiš failing test**

```php
<?php

namespace Tests\Feature\Modules\Packeta;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Packeta\Models\PickupPoint;
use Modules\Packeta\Services\PickupPointSync;
use Tests\TestCase;

class PickupPointSyncTest extends TestCase
{
    use RefreshDatabase;

    private function feed(array $points): array
    {
        return ['data' => $points];
    }

    private function point(string $id, string $name, string $city): array
    {
        return [
            'id' => $id, 'name' => $name, 'city' => $city,
            'street' => 'Hlavní 1', 'zip' => '60200', 'country' => 'cz',
            'latitude' => '49.19', 'longitude' => '16.60',
        ];
    }

    public function test_sync_inserts_points_from_the_feed(): void
    {
        Http::fake(['*' => Http::response($this->feed([
            $this->point('1', 'Večerka', 'Brno'),
            $this->point('2', 'Trafika', 'Praha'),
        ]))]);

        $result = $this->app->make(PickupPointSync::class)->run('test-key');

        $this->assertSame(2, $result['created']);
        $this->assertSame(2, PickupPoint::where('is_active', true)->count());
    }

    public function test_points_missing_from_the_feed_are_deactivated_not_deleted(): void
    {
        PickupPoint::create([
            'carrier' => 'packeta', 'code' => '9', 'name' => 'Zrušená',
            'city' => 'Ostrava', 'zip' => '70200', 'country' => 'CZ',
            'search_text' => 'zrusena ostrava', 'is_active' => true,
        ]);

        Http::fake(['*' => Http::response($this->feed([$this->point('1', 'Večerka', 'Brno')]))]);

        $this->app->make(PickupPointSync::class)->run('test-key');

        $this->assertSame(1, $result = PickupPoint::where('code', '9')->count());
        $this->assertFalse(PickupPoint::where('code', '9')->first()->is_active);
    }

    public function test_an_empty_feed_is_not_applied(): void
    {
        PickupPoint::create([
            'carrier' => 'packeta', 'code' => '1', 'name' => 'Večerka',
            'city' => 'Brno', 'zip' => '60200', 'country' => 'CZ',
            'search_text' => 'vecerka brno', 'is_active' => true,
        ]);

        Http::fake(['*' => Http::response($this->feed([]))]);

        $this->expectException(\App\Core\Shipping\Exceptions\CarrierError::class);

        try {
            $this->app->make(PickupPointSync::class)->run('test-key');
        } finally {
            // One bad response must never wipe every tenant's pickup points.
            $this->assertTrue(PickupPoint::where('code', '1')->first()->is_active);
        }
    }

    public function test_search_text_is_normalised_on_write(): void
    {
        Http::fake(['*' => Http::response($this->feed([$this->point('1', 'Žabovřesky', 'Brno')]))]);

        $this->app->make(PickupPointSync::class)->run('test-key');

        $this->assertStringContainsString('zabovresky', PickupPoint::first()->search_text);
    }
}
```

- [ ] **Step 2: Spusť test, ověř selhání**

Run: `php artisan test --compact --filter=PickupPointSyncTest`
Expected: FAIL — `Class "Modules\Packeta\Services\PickupPointSync" not found`

- [ ] **Step 3: Config**

`config/packeta.php`:

```php
<?php

return [
    // Platform-wide key used only to download the shared pickup point feed.
    // Falls back to the first configured tenant's key (see PickupPointSync),
    // so the catalogue works before we have our own Packeta account.
    'feed_api_key' => env('PACKETA_FEED_API_KEY'),

    'feed_url' => env('PACKETA_FEED_URL', 'https://www.zasilkovna.cz/api/v4/{key}/branch.json'),

    'api_url' => env('PACKETA_API_URL', 'https://www.zasilkovna.cz/api/rest'),

    'timeout' => (int) env('PACKETA_TIMEOUT', 30),

    // A feed answer with fewer points than this is treated as broken and is
    // not applied — deactivating thousands of pickup points because of one bad
    // response would break checkout for every tenant at once.
    'feed_min_points' => (int) env('PACKETA_FEED_MIN_POINTS', 100),

    'tracking_url' => env('PACKETA_TRACKING_URL', 'https://tracking.packeta.com/cs/?id={barcode}'),
];
```

> Do `.env.example` přidej `PACKETA_FEED_API_KEY=`. **Nikdy needituj `.env`.**
> V testech nastav `config(['packeta.feed_min_points' => 1])` tam, kde test posílá jen dva body — jinak by legitimní fake feed spadl na guardu. Test `test_an_empty_feed_is_not_applied` guard naopak testuje, ten `feed_min_points` nepřepisuje.

- [ ] **Step 4: Sync služba**

```php
<?php

namespace Modules\Packeta\Services;

use App\Core\Shipping\Exceptions\CarrierError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Packeta\Models\PickupPoint;

/**
 * Downloads the pickup point feed into our shared catalogue (wave 2.5).
 *
 * Deliberately not incremental: the feed is the full truth, a few thousand
 * rows for CZ, and reconciling it wholesale is both simpler and safer than
 * trusting a delta we cannot verify. Points that vanish are deactivated, never
 * deleted — an order placed yesterday still snapshots one of them.
 */
final class PickupPointSync
{
    public const CARRIER = 'packeta';

    /**
     * @return array{created: int, updated: int, deactivated: int}
     */
    public function run(string $apiKey): array
    {
        $points = $this->fetch($apiKey);

        if (count($points) < (int) config('packeta.feed_min_points')) {
            // Refuse rather than apply: an empty or truncated answer would
            // deactivate the whole catalogue and break checkout for every
            // tenant at once.
            throw CarrierError::unreachable(self::CARRIER, sprintf(
                'feed vrátil jen %d míst, což je pod prahem %d — katalog se nepřepisuje',
                count($points),
                (int) config('packeta.feed_min_points'),
            ));
        }

        $seen = [];
        $created = 0;
        $updated = 0;

        foreach ($points as $point) {
            $code = (string) ($point['id'] ?? '');

            if ($code === '') {
                continue;
            }

            $seen[] = $code;

            $name = (string) ($point['name'] ?? '');
            $street = (string) ($point['street'] ?? '');
            $city = (string) ($point['city'] ?? '');
            $zip = (string) ($point['zip'] ?? '');

            $attributes = [
                'name' => $name,
                'street' => $street,
                'city' => $city,
                'zip' => $zip,
                'country' => strtoupper((string) ($point['country'] ?? 'CZ')),
                'search_text' => PickupPoint::normalise(implode(' ', [$name, $street, $city, $zip])),
                'opening_hours' => $point['openingHours'] ?? null,
                'latitude' => $point['latitude'] ?? null,
                'longitude' => $point['longitude'] ?? null,
                'is_active' => true,
                'synced_at' => now(),
            ];

            $existing = PickupPoint::where('carrier', self::CARRIER)->where('code', $code)->first();

            if ($existing === null) {
                PickupPoint::create($attributes + ['carrier' => self::CARRIER, 'code' => $code]);
                $created++;
            } else {
                $existing->update($attributes);
                $updated++;
            }
        }

        $deactivated = 0;

        // Chunked so a catalogue of thousands does not build one giant IN().
        foreach (array_chunk($seen, 1000) as $chunk) {
            // no-op per chunk; the deactivation below needs the full set
        }

        $deactivated = PickupPoint::query()
            ->where('carrier', self::CARRIER)
            ->where('is_active', true)
            ->whereNotIn('code', $seen)
            ->update(['is_active' => false]);

        return ['created' => $created, 'updated' => $updated, 'deactivated' => $deactivated];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetch(string $apiKey): array
    {
        $url = str_replace('{key}', $apiKey, (string) config('packeta.feed_url'));

        try {
            $response = Http::timeout((int) config('packeta.timeout'))->get($url);
        } catch (\Throwable $e) {
            throw CarrierError::unreachable(self::CARRIER, $e->getMessage());
        }

        if ($response->failed()) {
            throw CarrierError::unreachable(self::CARRIER, 'HTTP '.$response->status());
        }

        $data = $response->json('data');

        return is_array($data) ? $data : [];
    }
}
```

> **Uklid smyčku `array_chunk`** — je tam jako připomínka, že `whereNotIn` s desítkami tisíc kódů je dotaz na hraně. Pokud katalog přesáhne ~5 000 míst, přepiš deaktivaci na „označ vše neaktivní → upsert je vrátí aktivní" v jedné transakci. Pro CZ katalog (~4 000) stačí `whereNotIn`. Smyčku smaž, ať v kódu nezůstane mrtvý blok.

- [ ] **Step 5: Command**

```php
<?php

namespace Modules\Packeta\Console;

use App\Core\Shipping\Exceptions\CarrierError;
use Illuminate\Console\Command;
use Modules\Packeta\Services\PickupPointSync;
use Modules\Shipping\Models\ShippingMethod;
use Spatie\Multitenancy\Jobs\NotTenantAware;

/**
 * Refreshes the shared pickup point catalogue (wave 2.5).
 *
 * NotTenantAware because the table it writes carries no tenant_id: one run
 * feeds every shop. The key is the platform's, with a fallback to the first
 * tenant that has Zásilkovna configured, so the catalogue works before we
 * have our own Packeta account.
 */
class SyncPickupPointsCommand extends Command implements NotTenantAware
{
    protected $signature = 'packeta:sync-points';

    protected $description = 'Download the Packeta pickup point catalogue';

    public function handle(PickupPointSync $sync): int
    {
        $key = $this->apiKey();

        if ($key === null) {
            $this->error('No Packeta API key: set PACKETA_FEED_API_KEY, or configure a Zásilkovna delivery method for at least one tenant.');

            return self::FAILURE;
        }

        try {
            $result = $sync->run($key);
        } catch (CarrierError $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Pickup points: %d created, %d updated, %d deactivated.',
            $result['created'],
            $result['updated'],
            $result['deactivated'],
        ));

        return self::SUCCESS;
    }

    private function apiKey(): ?string
    {
        $configured = (string) (config('packeta.feed_api_key') ?? '');

        if ($configured !== '') {
            return $configured;
        }

        // Fallback: any tenant's widget key downloads the same public
        // catalogue. Deliberately reads across tenants — this command has no
        // ambient tenant, and the catalogue it fills is shared anyway.
        $method = ShippingMethod::withoutGlobalScopes()
            ->where('provider', ShippingMethod::PROVIDER_PACKETA)
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->first(fn (ShippingMethod $m) => filled($m->settings['api_key'] ?? null));

        $key = $method?->settings['api_key'] ?? null;

        return filled($key) ? (string) $key : null;
    }
}
```

> Ověř přesný název scope-vypínače, který projekt používá pro `BelongsToTenant` (`withoutGlobalScopes()` vs. vlastní scope třída) — `grep -rn "withoutGlobalScope" app/ Modules/`.

Registrace v `ModuleProvider::boot()`:

```php
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([SyncPickupPointsCommand::class]);
        }
    }
```

Plánování — tam, kde je `domains:sweep-pending`:

```php
$schedule->command('packeta:sync-points')->dailyAt('03:30');
```

- [ ] **Step 6: Spusť testy**

Run: `php artisan test --compact --filter=PickupPointSyncTest`
Expected: PASS (4 testy)

- [ ] **Step 7: Pint a commit**

```bash
./vendor/bin/pint Modules/Packeta config/packeta.php tests/Feature/Modules/Packeta
git add Modules/Packeta config/packeta.php .env.example tests/Feature/Modules/Packeta routes bootstrap
git commit -m "feat(packeta): daily pickup point feed sync

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Etapa 3 — výběr místa v pokladně bez JS

### Task 6: `carts.pickup_point_code` a zápis volby

**Files:**
- Create: `Modules/Checkout/Database/Migrations/2026_07_27_110000_add_pickup_point_to_carts.php`
- Modify: `app/Core/Checkout/Contracts/CartRepository.php`, `CartShape.php`
- Modify: `Modules/Checkout/Services/EloquentCartRepository.php`
- Modify: `Modules/Checkout/Models/Cart.php`
- Modify: `app/Core/Checkout/NullCartRepository.php`
- Test: `tests/Feature/Modules/Checkout/PickupPointSelectionTest.php`

**Interfaces:**
- Consumes: `PickupPointCatalog` (Task 4).
- Produces: `CartRepository::choosePickupPoint(CartShape $cart, ?string $code): void`; `CartShape::cartPickupPointCode(): ?string`.

- [ ] **Step 1: Napiš failing test**

```php
<?php

namespace Tests\Feature\Modules\Checkout;

use App\Core\Checkout\Contracts\CartRepository;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Shipping\Models\ShippingMethod;
use Tests\TestCase;

class PickupPointSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_chosen_pickup_point_is_persisted_on_the_cart(): void
    {
        $tenant = Tenant::factory()->create();

        tenancy()->runAs($tenant, function () {
            $carts = $this->app->make(CartRepository::class);
            $cart = $carts->forToken(null);

            $carts->choosePickupPoint($cart, '1001');

            $this->assertSame('1001', $carts->forToken($cart->cartToken())->cartPickupPointCode());
        });
    }

    public function test_switching_to_a_method_without_pickup_clears_the_point(): void
    {
        $tenant = Tenant::factory()->create();

        tenancy()->runAs($tenant, function () {
            $flat = ShippingMethod::create([
                'provider' => ShippingMethod::PROVIDER_FLAT,
                'name' => 'Kurýr', 'price' => 12900, 'currency' => 'CZK',
            ]);

            $carts = $this->app->make(CartRepository::class);
            $cart = $carts->forToken(null);
            $carts->choosePickupPoint($cart, '1001');

            $carts->chooseShipping($cart, $flat->id, null);

            $this->assertNull($carts->forToken($cart->cartToken())->cartPickupPointCode());
        });
    }
}
```

> Ověř přesný název akcesoru tokenu na `CartShape` (`cartToken()` vs jiný) v existujícím `CartRepositoryTest` a použij tentýž.

- [ ] **Step 2: Spusť test, ověř selhání**

Run: `php artisan test --compact --filter=PickupPointSelectionTest`
Expected: FAIL — `Call to undefined method choosePickupPoint()`

- [ ] **Step 3: Migrace**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The pickup point a shopper chose (wave 2.5).
 *
 * A carrier code, not a foreign key: the catalogue is platform-wide and
 * resynced daily, so rows come and go, while the carrier's own code is stable.
 * Whether the code still resolves to an active point is re-checked when the
 * order is placed, not enforced by the schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->string('pickup_point_code', 40)->nullable()->after('shipping_method_id');
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->dropColumn('pickup_point_code');
        });
    }
};
```

- [ ] **Step 4: Kontrakt, model, repozitář**

`CartShape` — přidej:

```php
    /**
     * The carrier code of the chosen pickup point, or null.
     *
     * Only the code is ever stored: the point's name and address are re-read
     * from the catalogue wherever they are shown, so a renamed or moved point
     * never leaves a stale address in a live cart.
     */
    public function cartPickupPointCode(): ?string;
```

`CartRepository` — přidej:

```php
    /**
     * Persists the pickup point chosen for a carrier that requires one.
     *
     * The caller has already resolved the code against PickupPointCatalog;
     * this method writes what it is given and nothing else. Null clears the
     * choice.
     */
    public function choosePickupPoint(CartShape $cart, ?string $code): void;
```

`NullCartRepository` — no-op implementace obou.

`EloquentCartRepository`:

```php
    public function choosePickupPoint(CartShape $cart, ?string $code): void
    {
        $this->model($cart)->forceFill(['pickup_point_code' => $code])->save();
    }
```

a v `chooseShipping()` doplň nulování:

```php
        // A pickup point belongs to the method it was chosen for. Leaving it
        // behind when the shopper switches to a courier would ship a parcel to
        // a branch nobody selected.
        $carrierChanged = $method?->provider() !== ShippingMethod::PROVIDER_PACKETA;

        $attributes = [
            'shipping_method_id' => $shippingMethodId,
            'payment_method_id' => $paymentMethodId,
        ];

        if ($carrierChanged) {
            $attributes['pickup_point_code'] = null;
        }
```

> `chooseShipping` dnes dostává jen id. Načti metodu přes `ShippingOptions::find($shippingMethodId)` (kontrakt, který repozitář už smí použít), nebo — čistší — porovnej provider v controlleru a zavolej `choosePickupPoint($cart, null)` explicitně. **Zvol druhou variantu**, ať repozitář nezískává novou závislost.

- [ ] **Step 5: Spusť testy**

Run: `php artisan test --compact --filter="PickupPointSelectionTest|CartRepositoryTest|CartVariantTest"`
Expected: PASS

- [ ] **Step 6: Pint a commit**

```bash
./vendor/bin/pint app/Core/Checkout Modules/Checkout tests/Feature/Modules/Checkout
git add app/Core/Checkout Modules/Checkout tests/Feature/Modules/Checkout
git commit -m "feat(checkout): carts carry a chosen pickup point

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: Stránka výběru místa bez JS

**Files:**
- Create: `Modules/Checkout/Http/Controllers/PickupPointController.php`
- Create: `Modules/Checkout/Resources/views/checkout/pickup-point.blade.php`
- Modify: `Modules/Checkout/routes/web.php` (najdi, kde jsou `storefront.checkout.*` routy)
- Modify: `Modules/Checkout/Resources/views/checkout/shipping.blade.php`
- Modify: `Modules/Checkout/Http/Controllers/CheckoutController.php` (prop vybraného místa)
- Modify: `Modules/Shipping/Services/EloquentShippingOptions.php` (filtr metod bez driveru)
- Test: `tests/Feature/Modules/Checkout/PickupPointCheckoutTest.php`

**Interfaces:**
- Consumes: `choosePickupPoint()` (Task 6), `PickupPointCatalog` (Task 4), `CarrierRegistry` (Task 1).
- Produces: routy `storefront.checkout.pickupPoint` (GET) a `storefront.checkout.choosePickupPoint` (POST).

- [ ] **Step 1: Napiš failing test**

```php
<?php

namespace Tests\Feature\Modules\Checkout;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Packeta\Models\PickupPoint;
use Modules\Shipping\Models\ShippingMethod;
use Tests\TestCase;

class PickupPointCheckoutTest extends TestCase
{
    use RefreshDatabase;

    // Postav tenanta s aktivními moduly checkout/shipping/packeta, produkt
    // v košíku a metodu Zásilkovna — podle vzoru v CheckoutFlowTest.

    public function test_the_pickup_point_page_lists_matching_points(): void
    {
        // GET /pokladna/vydejni-misto?q=Brno
        // assertOk, assertSee názvu místa, assertSee adresy
    }

    public function test_choosing_a_point_stores_only_its_code(): void
    {
        // POST /pokladna/vydejni-misto s pickup_point_code=1001
        // a podvrženými name/street v těle
        // -> redirect na krok dopravy, v DB je kód, název NENÍ z requestu
    }

    public function test_an_unknown_code_is_rejected(): void
    {
        // POST s code=does-not-exist -> redirect zpět s chybou, v DB nic
    }

    public function test_an_inactive_point_is_rejected(): void
    {
        // point s is_active=false -> stejné jako neznámý kód
    }

    public function test_the_page_renders_without_javascript(): void
    {
        // Odpověď obsahuje <form method="POST"> a radio inputy —
        // nikoli prázdný kontejner čekající na JS.
    }

    public function test_a_packeta_method_is_hidden_when_the_module_is_off(): void
    {
        // Vypni modul packeta pro tenanta.
        // GET /pokladna/doprava neobsahuje metodu Zásilkovna.
    }
}
```

> Těla doplň podle existujícího checkout feature testu (jak se v projektu skládá košík a prochází pokladnou). Každý test musí projít **čistě přes HTTP**, žádné volání služeb přímo — jde o důkaz, že cesta bez JS funguje.

- [ ] **Step 2: Spusť test, ověř selhání**

Run: `php artisan test --compact --filter=PickupPointCheckoutTest`
Expected: FAIL — 404 na `/pokladna/vydejni-misto`

- [ ] **Step 3: Filtr metod bez běžícího driveru**

`EloquentShippingOptions::available()` — za existující dotaz:

```php
        $methods = ShippingMethod::query()
            ->where('is_active', true)
            ->where(function ($q) use ($weightGrams) {
                $q->whereNull('max_weight_g')->orWhere('max_weight_g', '>=', $weightGrams);
            })
            ->orderBy('position')
            ->get();

        // A method whose carrier module is off would be offered but never
        // fulfillable: nobody could submit the parcel, and the shopper would
        // have no way to pick a branch. The tenant's configuration stays
        // untouched — switch the module back on and the method returns.
        return $methods->filter(function (ShippingMethod $method) {
            $builtIn = in_array($method->provider(), [
                ShippingMethod::PROVIDER_PICKUP,
                ShippingMethod::PROVIDER_FLAT,
            ], true);

            return $builtIn || $this->carriers->for($method->provider()) !== null;
        })->values();
```

s `CarrierRegistry $carriers` v konstruktoru.

- [ ] **Step 4: Controller**

```php
<?php

namespace Modules\Checkout\Http\Controllers;

use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Shipping\Contracts\PickupPointCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Choosing a pickup point, server-rendered (wave 2.5).
 *
 * This is the primary path, not a fallback: the whole checkout must work with
 * JavaScript off (spec §16.3). The optional map widget posts to exactly this
 * endpoint with exactly this payload, so there is one code path on the server.
 */
class PickupPointController extends Controller
{
    public function __construct(
        private readonly CartRepository $carts,
        private readonly PickupPointCatalog $points,
    ) {}

    public function show(Request $request): View
    {
        $query = trim((string) $request->query('q', ''));

        return view('checkout::checkout.pickup-point', [
            'query' => $query,
            'points' => $query === '' ? collect() : $this->points->search($query),
            'selected' => $this->cart($request)->cartPickupPointCode(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $code = (string) $request->input('pickup_point_code', '');

        // Only the code is trusted. Name and address are always re-read from
        // the catalogue — the widget returns them too, and a forged POST would
        // otherwise print an address of the buyer's choosing on the order.
        $point = $this->points->find('packeta', $code);

        if ($point === null) {
            return redirect()
                ->route('storefront.checkout.pickupPoint')
                ->withErrors(['pickup_point_code' => 'Toto výdejní místo neznáme nebo už není v provozu. Vyberte prosím jiné.']);
        }

        $this->carts->choosePickupPoint($this->cart($request), $point->pointCode());

        return redirect()->route('storefront.checkout.shipping');
    }
}
```

> `cart(Request)` vezmi ze vzoru v `CheckoutController` (jak se v projektu resolvuje košík z cookie tokenu) — nevymýšlej vlastní.

Routy (do skupiny, kde jsou ostatní `storefront.checkout.*`):

```php
Route::get('/pokladna/vydejni-misto', [PickupPointController::class, 'show'])->name('checkout.pickupPoint');
Route::post('/pokladna/vydejni-misto', [PickupPointController::class, 'store'])->name('checkout.choosePickupPoint');
```

- [ ] **Step 5: Blade**

`pickup-point.blade.php`:

```blade
@extends('storefront::layouts.shop')

@section('meta')
    <meta name="robots" content="noindex, nofollow">
@endsection

@section('content')
    <h1 class="text-2xl font-semibold text-slate-900 sm:text-3xl">Výdejní místo</h1>

    @if ($errors->any())
        <div role="alert" class="mt-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            <ul class="list-disc pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="GET" action="{{ route('storefront.checkout.pickupPoint') }}" class="mt-6 flex gap-2">
        <label for="q" class="sr-only">Hledat výdejní místo</label>
        <input id="q" name="q" value="{{ $query }}" placeholder="Město, PSČ nebo název"
               class="flex-1 rounded-lg border border-slate-300 p-3">
        <button type="submit" class="btn btn-primary">Hledat</button>
    </form>

    @if ($query !== '' && $points->isEmpty())
        <p class="mt-6 text-slate-600">Pro „{{ $query }}" jsme nic nenašli. Zkuste jiné město nebo PSČ.</p>
    @endif

    @if ($points->isNotEmpty())
        <form method="POST" action="{{ route('storefront.checkout.choosePickupPoint') }}" class="mt-6 space-y-4">
            @csrf

            <fieldset>
                <legend class="text-base font-medium text-slate-900">Vyberte místo</legend>
                <div class="mt-2 space-y-2">
                    @foreach ($points as $point)
                        <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-slate-200 p-3 has-[:checked]:border-brand has-[:checked]:bg-slate-50">
                            <input type="radio" name="pickup_point_code" value="{{ $point->pointCode() }}"
                                   class="mt-1 h-4 w-4 border-slate-300 text-brand focus:ring-brand"
                                   @checked($selected === $point->pointCode())>
                            <span class="flex-1">
                                <span class="block font-medium text-slate-900">{{ $point->pointName() }}</span>
                                <span class="block text-sm text-slate-600">
                                    {{ $point->pointStreet() }}, {{ $point->pointZip() }} {{ $point->pointCity() }}
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <button type="submit" class="btn btn-primary">Vybrat toto místo</button>
        </form>
    @endif

    <p class="mt-6">
        <a href="{{ route('storefront.checkout.shipping') }}" class="text-brand underline">Zpět na dopravu a platbu</a>
    </p>
@endsection
```

> Ověř, že layout `storefront::layouts.shop` má sekci `@yield('meta')`; pokud ne, přidej `noindex` způsobem, jakým to dělá košík.

Do `shipping.blade.php` pod radio metody Zásilkovny:

```blade
                            @if ($option->provider() === \Modules\Shipping\Models\ShippingMethod::PROVIDER_PACKETA)
                                <div class="ml-7 mt-2 text-sm">
                                    @if ($pickupPoint !== null)
                                        <p class="text-slate-700">
                                            <span class="font-medium">{{ $pickupPoint->pointName() }}</span>,
                                            {{ $pickupPoint->pointStreet() }}, {{ $pickupPoint->pointZip() }} {{ $pickupPoint->pointCity() }}
                                        </p>
                                        <a href="{{ route('storefront.checkout.pickupPoint') }}" class="text-brand underline">Změnit výdejní místo</a>
                                    @else
                                        <a href="{{ route('storefront.checkout.pickupPoint') }}" class="text-brand underline">Vybrat výdejní místo</a>
                                    @endif
                                </div>
                            @endif
```

`CheckoutController::shipping()` doplní prop:

```php
            'pickupPoint' => $cart->cartPickupPointCode() === null
                ? null
                : $this->points->find('packeta', $cart->cartPickupPointCode()),
```

- [ ] **Step 6: Spusť testy**

Run: `php artisan test --compact --filter=PickupPointCheckoutTest`
Expected: PASS (6 testů)

Run: `php artisan test --compact --filter=Checkout`
Expected: PASS

- [ ] **Step 7: Pint a commit**

```bash
./vendor/bin/pint Modules/Checkout Modules/Shipping tests/Feature/Modules/Checkout
git add Modules/Checkout Modules/Shipping tests/Feature/Modules/Checkout
git commit -m "feat(checkout): server-rendered pickup point selection, no JS required

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 8: Gate na odeslání objednávky a snapshot místa

**Files:**
- Modify: `Modules/Orders/Services/OrderPlacer.php`
- Create: `Modules/Orders/Exceptions/PickupPointMissing.php` (vedle existujících výjimek modulu — ověř adresář, `PriceChanged` už někde je)
- Modify: `Modules/Checkout/Http/Controllers/CheckoutController.php` (chycení výjimky)
- Test: `tests/Feature/Modules/Orders/PickupPointOrderTest.php`

**Interfaces:**
- Consumes: `CarrierRegistry` (Task 1), `PickupPointCatalog` (Task 4), `cartPickupPointCode()` (Task 6).
- Produces: klíč `pickup_point` v `orders.shipping_snapshot`.

- [ ] **Step 1: Napiš failing test**

```php
<?php

namespace Tests\Feature\Modules\Orders;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PickupPointOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_order_cannot_be_placed_without_a_pickup_point(): void
    {
        // Košík s metodou Zásilkovna, bez zvoleného místa.
        // POST na dokončení objednávky -> redirect zpět s chybou,
        // žádný řádek v orders.
    }

    public function test_the_snapshot_carries_the_point_read_from_the_catalogue(): void
    {
        // Košík s platným kódem 1001.
        // Po odeslání: orders.shipping_snapshot['pickup_point'] má code, name,
        // street, city, zip — hodnoty Z KATALOGU.
    }

    public function test_a_point_deactivated_after_selection_blocks_placement(): void
    {
        // Zvol místo, pak je deaktivuj, pak odešli objednávku
        // -> stejná chyba jako chybějící místo, žádná objednávka.
    }

    public function test_a_method_without_a_carrier_needs_no_pickup_point(): void
    {
        // Osobní odběr projde beze změny chování.
    }
}
```

- [ ] **Step 2: Spusť test, ověř selhání**

Run: `php artisan test --compact --filter=PickupPointOrderTest`
Expected: FAIL — objednávka se založí i bez místa

- [ ] **Step 3: Výjimka**

```php
<?php

namespace Modules\Orders\Exceptions;

use RuntimeException;

/**
 * The chosen delivery needs a pickup point and the cart has none, or the one
 * it has no longer resolves (wave 2.5).
 *
 * Raised inside OrderPlacer rather than validated in the controller: the
 * checkout screen is not the only way an order can be assembled, and an order
 * nobody can hand to the carrier must never exist.
 */
class PickupPointMissing extends RuntimeException
{
    public static function make(): self
    {
        return new self('Pro zvolenou dopravu je potřeba vybrat výdejní místo.');
    }
}
```

- [ ] **Step 4: Gate a snapshot v `OrderPlacer`**

V `place()`, **před** jakýmkoli zápisem a před odpisem skladu:

```php
        // A carrier that delivers to a branch cannot be handed an order with no
        // branch on it. Checked here rather than in the controller because the
        // controller is not the only caller, and re-resolved from the catalogue
        // rather than trusted from the cart: a point deactivated between
        // selection and submit must block placement, not produce an
        // unshippable parcel.
        $pickupPointSnapshot = null;

        if ($shipping !== null) {
            $carrier = $this->carriers->for($shipping->provider());

            if ($carrier?->requiresPickupPoint()) {
                $code = $cart->cartPickupPointCode();
                $point = $code === null ? null : $this->points->find($carrier->key(), $code);

                if ($point === null) {
                    throw PickupPointMissing::make();
                }

                $pickupPointSnapshot = [
                    'code' => $point->pointCode(),
                    'name' => $point->pointName(),
                    'street' => $point->pointStreet(),
                    'city' => $point->pointCity(),
                    'zip' => $point->pointZip(),
                ];
            }
        }
```

a do skládání `shipping_snapshot`:

```php
        if ($pickupPointSnapshot !== null) {
            $shippingSnapshot['pickup_point'] = $pickupPointSnapshot;
        }
```

> **Pořadí je load-bearing:** výjimka musí padnout dřív, než `decrementStock()` cokoli odepíše — jinak by odmítnutá objednávka ukousla sklad. Ve vlně 2.4 se řešil přesně tenhle druh chyby („fix placement exception order"), takže to ohlídej i testem.

`CheckoutController` chytí výjimku vedle existující `PriceChanged`:

```php
        } catch (PickupPointMissing $e) {
            return redirect()
                ->route('storefront.checkout.shipping')
                ->withErrors(['pickup_point_code' => $e->getMessage()]);
        }
```

- [ ] **Step 5: Spusť testy**

Run: `php artisan test --compact --filter=PickupPointOrderTest`
Expected: PASS (4 testy)

Run: `php artisan test --compact --filter="Orders|Checkout"`
Expected: PASS

- [ ] **Step 6: Pint a commit**

```bash
./vendor/bin/pint Modules/Orders Modules/Checkout tests/Feature/Modules/Orders
git add Modules/Orders Modules/Checkout tests/Feature/Modules/Orders
git commit -m "feat(orders): require and snapshot the pickup point at placement

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Etapa 4 — widget jako ostrůvek

### Task 9: JS ostrůvek mapy

**Files:**
- Modify: `resources/js/storefront.js`
- Modify: `Modules/Checkout/Resources/views/checkout/pickup-point.blade.php`
- Test: `tests/Feature/Modules/Checkout/PickupPointWidgetTest.php`

**Interfaces:**
- Consumes: routy z Tasku 7.
- Produces: nic pro další tasky — ostrůvek je koncový.

- [ ] **Step 1: Napiš failing test**

```php
<?php

namespace Tests\Feature\Modules\Checkout;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PickupPointWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_widget_button_carries_the_api_key_from_the_method(): void
    {
        // GET /pokladna/vydejni-misto: obsahuje data-packeta-widget
        // s data-api-key z nastavení metody.
    }

    public function test_no_widget_markup_without_an_api_key(): void
    {
        // Metoda bez api_key: stránka funguje, tlačítko mapy chybí,
        // server-rendered hledání je beze změny.
    }

    public function test_the_api_password_never_reaches_the_page(): void
    {
        // Klíčové: apiPassword je credential a nesmí být v HTML.
        // assertDontSee uloženého hesla.
    }
}
```

- [ ] **Step 2: Spusť test, ověř selhání**

Run: `php artisan test --compact --filter=PickupPointWidgetTest`
Expected: FAIL — `data-packeta-widget` v HTML není

- [ ] **Step 3: Markup ostrůvku**

Do `pickup-point.blade.php` nad vyhledávací formulář:

```blade
    @if ($widgetApiKey !== null)
        {{--
            Enhancement only. The widget script lives on Packeta's domain and
            is fetched on click, never on load: until the shopper asks for the
            map, checkout makes no third-party request at all (performance,
            ePrivacy, CSP). Everything below works without it.
        --}}
        <div class="mt-6" data-packeta-widget data-api-key="{{ $widgetApiKey }}" hidden>
            <button type="button" data-packeta-open class="btn btn-secondary">Vybrat na mapě</button>
        </div>
    @endif
```

Controller doplní prop — **jen `api_key`, nikdy `api_password`**:

```php
    private function widgetApiKey(): ?string
    {
        $method = ShippingMethod::query()
            ->where('provider', ShippingMethod::PROVIDER_PACKETA)
            ->where('is_active', true)
            ->first();

        $key = $method?->settings['api_key'] ?? null;

        return filled($key) ? (string) $key : null;
    }
```

- [ ] **Step 4: Ostrůvek v `storefront.js`**

Přidej vedle existujících ostrůvků (galerie, varianty), stejným stylem:

```js
/**
 * Packeta pickup point widget — enhancement over the server-rendered picker.
 *
 * The container ships hidden and is only revealed once this runs, so a shopper
 * without JavaScript never sees a button that would do nothing. The widget
 * script is loaded on first click, not on page load: no third-party request
 * happens unless the shopper actually asks for the map.
 *
 * The widget only ever gives us a point id; the name and address it also
 * returns are deliberately ignored, because the server re-reads them from our
 * own catalogue.
 */
function initPacketaWidget() {
    const mount = document.querySelector('[data-packeta-widget]');

    if (!mount) {
        return;
    }

    const apiKey = mount.dataset.apiKey;
    const button = mount.querySelector('[data-packeta-open]');

    if (!apiKey || !button) {
        return;
    }

    mount.hidden = false;

    let loading = null;

    const loadLibrary = () => {
        if (window.Packeta) {
            return Promise.resolve();
        }

        if (loading) {
            return loading;
        }

        loading = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = 'https://widget.packeta.com/v6/www/js/library.js';
            script.async = true;
            script.onload = () => resolve();
            script.onerror = () => reject(new Error('widget unavailable'));
            document.head.appendChild(script);
        });

        return loading;
    };

    button.addEventListener('click', () => {
        button.disabled = true;

        loadLibrary()
            .then(() => {
                window.Packeta.Widget.pick(apiKey, (point) => {
                    button.disabled = false;

                    if (!point || !point.id) {
                        return;
                    }

                    // Submit the same form the no-JS path posts, with the same
                    // single field. One server code path, one set of rules.
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = mount.dataset.action || window.location.pathname;

                    const token = document.querySelector('meta[name="csrf-token"]');

                    const fields = {
                        _token: token ? token.content : '',
                        pickup_point_code: String(point.id),
                    };

                    Object.entries(fields).forEach(([name, value]) => {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = name;
                        input.value = value;
                        form.appendChild(input);
                    });

                    document.body.appendChild(form);
                    form.submit();
                });
            })
            .catch(() => {
                button.disabled = false;
                button.textContent = 'Mapa se nenačetla — vyhledejte místo níže';
            });
    });
}
```

a zavolej `initPacketaWidget()` tam, kde se volají ostatní inicializace.

> Doplň `data-action="{{ route('storefront.checkout.choosePickupPoint') }}"` na `[data-packeta-widget]` a ověř, že layout renderuje `<meta name="csrf-token">`; pokud ne, vezmi token z `@csrf` pole ve vyhledávacím formuláři na stránce.

- [ ] **Step 5: Build a testy**

Run: `npm run build`
Expected: projde, bundle storefrontu zůstane pod 100 kB gzip

Run: `php artisan test --compact --filter=PickupPointWidgetTest`
Expected: PASS (3 testy)

- [ ] **Step 6: Pint a commit**

```bash
./vendor/bin/pint Modules/Checkout tests/Feature/Modules/Checkout
git add resources/js/storefront.js Modules/Checkout tests/Feature/Modules/Checkout
git commit -m "feat(checkout): packeta map widget as an opt-in island

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Etapa 5 — podání, štítky, tracking

### Task 10: HTTP klient a driver

**Files:**
- Create: `Modules/Packeta/Services/PacketaClient.php`
- Create: `Modules/Packeta/Services/PacketaCarrier.php`
- Create: `Modules/Packeta/Services/EloquentCarrierRegistry.php`
- Modify: `Modules/Packeta/Providers/ModuleProvider.php` (binding `CarrierRegistry`)
- Test: `tests/Feature/Modules/Packeta/PacketaCarrierTest.php`

**Interfaces:**
- Consumes: `Carrier`, `CarrierRegistry`, `ShipmentResult`, `CarrierError` (Task 1); credentials ze `settings` (Task 3).
- Produces: `PacketaClient::createPacket(array $attributes): ShipmentResult`, `labelsPdf(array $packetIds, string $format): string`, `cancelPacket(string $packetId): void`.

- [ ] **Step 1: Napiš failing test**

```php
<?php

namespace Tests\Feature\Modules\Packeta;

use App\Core\Shipping\Contracts\CarrierRegistry;
use App\Core\Shipping\Exceptions\CarrierError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PacketaCarrierTest extends TestCase
{
    use RefreshDatabase;

    // Setup: tenant s aktivním modulem packeta + metoda Zásilkovna
    // se settings api_password/api_key/eshop.

    public function test_registry_resolves_the_driver_when_configured(): void
    {
        // $this->app->make(CarrierRegistry::class)->for('packeta') !== null
    }

    public function test_registry_returns_null_without_credentials(): void
    {
        // Metoda bez api_password -> for('packeta') === null,
        // takže checkout metodu vůbec nenabídne.
    }

    public function test_registry_returns_null_when_the_module_is_off(): void
    {
    }

    public function test_submit_sends_the_expected_packet_attributes(): void
    {
        Http::fake(['*' => Http::response(
            '<response><status>ok</status><result><id>777</id><barcode>Z123</barcode></result></response>'
        )]);

        // submit() vrátí ShipmentResult('777', 'Z123')
        // Http::assertSent: tělo obsahuje apiPassword, number, addressId,
        // eshop, cod, weight
    }

    public function test_a_fault_response_raises_a_carrier_error(): void
    {
        Http::fake(['*' => Http::response(
            '<response><status>fault</status><string>Invalid API password</string></response>'
        )]);

        $this->expectException(CarrierError::class);
        // submit()
    }

    public function test_a_network_failure_raises_a_carrier_error(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));

        $this->expectException(CarrierError::class);
    }

    public function test_tracking_url_is_built_from_the_barcode(): void
    {
        // trackingUrl('Z123') === 'https://tracking.packeta.com/cs/?id=Z123'
    }
}
```

- [ ] **Step 2: Spusť test, ověř selhání**

Run: `php artisan test --compact --filter=PacketaCarrierTest`
Expected: FAIL — třídy neexistují

- [ ] **Step 3: HTTP klient**

```php
<?php

namespace Modules\Packeta\Services;

use App\Core\Shipping\Exceptions\CarrierError;
use App\Core\Shipping\ShipmentResult;
use Illuminate\Support\Facades\Http;

/**
 * Packeta REST/XML transport (wave 2.5).
 *
 * Only HTTP lives here — no order mapping, no persistence. REST/XML rather
 * than the SOAP WSDL Packeta also publishes: SOAP would add an ext-soap
 * dependency for no gain, and the Http facade is the precedent set by the
 * Comgate driver in wave 1.4.
 */
final class PacketaClient
{
    public function __construct(private readonly string $apiPassword) {}

    /**
     * @param  array<string, scalar|null>  $attributes
     */
    public function createPacket(array $attributes): ShipmentResult
    {
        $xml = $this->request('createPacket', $this->buildPacketXml($attributes));

        $id = (string) ($xml->result->id ?? '');
        $barcode = (string) ($xml->result->barcode ?? '');

        if ($id === '') {
            throw CarrierError::rejected('packeta', 'odpověď neobsahuje id zásilky');
        }

        return new ShipmentResult($id, $barcode);
    }

    /**
     * @param  list<string>  $packetIds
     */
    public function labelsPdf(array $packetIds, string $format): string
    {
        $body = '<packetIds>';

        foreach ($packetIds as $index => $packetId) {
            $body .= '<id'.$index.'>'.htmlspecialchars($packetId, ENT_XML1).'</id'.$index.'>';
        }

        $body .= '</packetIds><format>'.htmlspecialchars($format, ENT_XML1).'</format><offset>0</offset>';

        $xml = $this->request('packetsLabelsPdf', $body);

        $pdf = base64_decode((string) ($xml->result ?? ''), true);

        if ($pdf === false || $pdf === '') {
            throw CarrierError::rejected('packeta', 'štítek se nepodařilo načíst');
        }

        return $pdf;
    }

    public function cancelPacket(string $packetId): void
    {
        $this->request('cancelPacket', '<packetId>'.htmlspecialchars($packetId, ENT_XML1).'</packetId>');
    }

    /**
     * @param  array<string, scalar|null>  $attributes
     */
    private function buildPacketXml(array $attributes): string
    {
        $body = '<packetAttributes>';

        foreach ($attributes as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $body .= '<'.$key.'>'.htmlspecialchars((string) $value, ENT_XML1).'</'.$key.'>';
        }

        return $body.'</packetAttributes>';
    }

    private function request(string $method, string $body): \SimpleXMLElement
    {
        $payload = sprintf(
            '<?xml version="1.0" encoding="utf-8"?><%1$s><apiPassword>%2$s</apiPassword>%3$s</%1$s>',
            $method,
            htmlspecialchars($this->apiPassword, ENT_XML1),
            $body,
        );

        try {
            $response = Http::withBody($payload, 'text/xml')
                ->timeout((int) config('packeta.timeout'))
                ->post((string) config('packeta.api_url'));
        } catch (\Throwable $e) {
            throw CarrierError::unreachable('packeta', $e->getMessage());
        }

        if ($response->failed()) {
            throw CarrierError::unreachable('packeta', 'HTTP '.$response->status());
        }

        $xml = @simplexml_load_string($response->body());

        if ($xml === false) {
            throw CarrierError::unreachable('packeta', 'odpověď není platné XML');
        }

        if ((string) ($xml->status ?? '') !== 'ok') {
            throw CarrierError::rejected('packeta', (string) ($xml->string ?? 'neznámý důvod'));
        }

        return $xml;
    }
}
```

- [ ] **Step 4: Driver**

```php
<?php

namespace Modules\Packeta\Services;

use App\Core\Money\Money;
use App\Core\Orders\Contracts\OrderView;
use App\Core\Shipping\Contracts\Carrier;
use App\Core\Shipping\Exceptions\CarrierError;
use App\Core\Shipping\ShipmentResult;
use Modules\Packeta\Models\Shipment;
use Modules\Shipping\Models\ShippingMethod;

/**
 * Packeta driver: maps our order onto their packet attributes (wave 2.5).
 *
 * Money never crosses in the wrong unit — our Money is haléře, Packeta wants
 * crowns, and that conversion happens here, once.
 */
final class PacketaCarrier implements Carrier
{
    public function __construct(
        private readonly PacketaClient $client,
        private readonly string $eshop,
    ) {}

    public function key(): string
    {
        return ShippingMethod::PROVIDER_PACKETA;
    }

    public function requiresPickupPoint(): bool
    {
        return true;
    }

    public function submit(OrderView $order, string $pickupPointCode, Money $codAmount, int $weightGrams): ShipmentResult
    {
        $billing = $order->orderBilling();

        [$firstName, $lastName] = $this->splitName((string) ($billing['name'] ?? ''));

        return $this->client->createPacket([
            'number' => $order->orderNumber(),
            'name' => $firstName,
            'surname' => $lastName,
            'email' => $order->orderEmail(),
            'phone' => $order->orderPhone(),
            'addressId' => $pickupPointCode,
            'cod' => $codAmount->isZero() ? null : $this->crowns($codAmount),
            'value' => $this->crowns($order->orderTotal()),
            'currency' => $order->orderCurrency(),
            'weight' => round($weightGrams / 1000, 3),
            'eshop' => $this->eshop,
        ]);
    }

    public function labels(array $shipmentIds, string $format): string
    {
        $packetIds = Shipment::query()
            ->whereIn('id', $shipmentIds)
            ->whereNotNull('packet_id')
            ->pluck('packet_id')
            ->all();

        if ($packetIds === []) {
            throw CarrierError::rejected('packeta', 'žádná z vybraných objednávek nemá podanou zásilku');
        }

        return $this->client->labelsPdf($packetIds, $format);
    }

    public function cancel(string $packetId): void
    {
        $this->client->cancelPacket($packetId);
    }

    public function trackingUrl(string $barcode): string
    {
        return str_replace('{barcode}', rawurlencode($barcode), (string) config('packeta.tracking_url'));
    }

    private function crowns(Money $money): string
    {
        return number_format($money->amount() / 100, 2, '.', '');
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitName(string $full): array
    {
        $parts = preg_split('/\s+/', trim($full), 2) ?: [''];

        return [$parts[0] ?? '', $parts[1] ?? $parts[0] ?? ''];
    }
}
```

> Ověř přesný akcesor částky na `Money` (`amount()` vs `minorUnits()` apod.) — `grep -n "public function" app/Core/Money/Money.php`.

- [ ] **Step 5: Registry**

```php
<?php

namespace Modules\Packeta\Services;

use App\Core\Shipping\Contracts\Carrier;
use App\Core\Shipping\Contracts\CarrierRegistry;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Storefront\Support\ShopModules;

/**
 * Resolves a carrier driver for the current tenant (wave 2.5).
 *
 * Per-tenant activation is answered here at call time through ShopModules, the
 * same as EloquentPaymentGatewayRegistry — the provider's binding is per
 * deploy, activation is per request. A driver is only built for a provider the
 * tenant has switched on AND configured, so checkout never offers a delivery
 * nobody could hand over.
 */
final class EloquentCarrierRegistry implements CarrierRegistry
{
    public function __construct(private readonly ShopModules $modules) {}

    public function for(string $provider): ?Carrier
    {
        if ($provider !== ShippingMethod::PROVIDER_PACKETA || ! $this->modules->has('packeta')) {
            return null;
        }

        $method = ShippingMethod::query()
            ->where('provider', ShippingMethod::PROVIDER_PACKETA)
            ->where('is_active', true)
            ->orderBy('position')
            ->first();

        $password = $method?->settings['api_password'] ?? null;
        $eshop = $method?->settings['eshop'] ?? null;

        if (blank($password) || blank($eshop)) {
            return null;
        }

        return new PacketaCarrier(new PacketaClient((string) $password), (string) $eshop);
    }

    public function available(): array
    {
        return $this->for(ShippingMethod::PROVIDER_PACKETA) !== null
            ? [ShippingMethod::PROVIDER_PACKETA]
            : [];
    }
}
```

Binding v `ModuleProvider::register()`:

```php
        $this->app->bind(CarrierRegistry::class, EloquentCarrierRegistry::class);
```

- [ ] **Step 6: Spusť testy**

Run: `php artisan test --compact --filter=PacketaCarrierTest`
Expected: PASS (7 testů)

- [ ] **Step 7: Pint a commit**

```bash
./vendor/bin/pint Modules/Packeta tests/Feature/Modules/Packeta
git add Modules/Packeta tests/Feature/Modules/Packeta
git commit -m "feat(packeta): REST/XML client, carrier driver and registry

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 11: Tabulka `shipments` a idempotentní podání

**Files:**
- Create: `Modules/Packeta/Database/Migrations/2026_07_27_120000_create_shipments_table.php`
- Create: `Modules/Packeta/Database/Migrations/2026_07_27_120100_attach_packeta_to_plans.php`
- Create: `Modules/Packeta/Models/Shipment.php`
- Create: `Modules/Packeta/Services/ShipmentSubmitter.php`
- Test: `tests/Feature/Modules/Packeta/ShipmentSubmitterTest.php`

**Interfaces:**
- Consumes: `Carrier`, `CarrierRegistry` (Task 10), `OrderBook` (existující kontrakt modulu orders).
- Produces: `ShipmentSubmitter::submit(string $orderUuid): Shipment`, `Shipment::STATUS_*`.

- [ ] **Step 1: Napiš failing test**

```php
<?php

namespace Tests\Feature\Modules\Packeta;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Packeta\Models\Shipment;
use Modules\Packeta\Services\ShipmentSubmitter;
use Tests\TestCase;

class ShipmentSubmitterTest extends TestCase
{
    use RefreshDatabase;

    public function test_submitting_records_the_carrier_identifiers(): void
    {
        // Http::fake ok odpověď -> shipment submitted, packet_id, barcode,
        // submitted_at vyplněné.
    }

    public function test_submitting_twice_creates_one_shipment_and_calls_the_api_once(): void
    {
        // Dvojí submit téže objednávky.
        // assertSame(1, Shipment::count()); Http::assertSentCount(1);
    }

    public function test_a_carrier_error_marks_the_shipment_failed_and_keeps_it_retryable(): void
    {
        // fault odpověď -> status failed, error vyplněný,
        // druhý pokus po úspěšné fake odpovědi projde a přepíše na submitted.
    }

    public function test_a_pending_shipment_is_retried_not_duplicated(): void
    {
        // Ručně vytvoř pending řádek (simulace pádu mezi commitem a odpovědí).
        // submit() ho převezme, nevytvoří druhý.
    }

    public function test_cod_amount_matches_the_order_total_for_a_cod_payment(): void
    {
        // Objednávka s platbou cod -> shipment cod_amount == order total,
        // a odeslané XML nese cod v korunách.
    }

    public function test_cod_is_zero_for_a_prepaid_order(): void
    {
    }

    public function test_a_shipment_of_another_tenant_cannot_be_submitted(): void
    {
        // Tenant B nesmí podat objednávku tenanta A.
    }
}
```

- [ ] **Step 2: Spusť test, ověř selhání**

Run: `php artisan test --compact --filter=ShipmentSubmitterTest`
Expected: FAIL — třídy neexistují

- [ ] **Step 3: Migrace**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parcels handed to a carrier (wave 2.5).
 *
 * order_id is deliberately not a foreign key: orders belongs to another
 * module, the same boundary carts.shipping_method_id keeps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->unsignedBigInteger('order_id');

            $table->string('carrier', 20);
            $table->string('packet_id', 40)->nullable();
            $table->string('barcode', 60)->nullable();

            $table->enum('status', ['pending', 'submitted', 'failed', 'cancelled'])->default('pending');

            $table->unsignedInteger('cod_amount')->default(0);
            $table->string('currency', 3)->default('CZK');
            $table->unsignedInteger('weight_grams')->default(0);

            $table->text('error')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('label_printed_at')->nullable();

            $table->timestamps();

            // One parcel per order. Without this a double click bills the
            // tenant for two parcels and prints two labels for one box.
            $table->unique(['tenant_id', 'order_id'], 'shipment_order_unique');
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
```

Zařazení do tarifů:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Puts the packeta module in every plan (wave 2.5).
 *
 * Delivery is a baseline shop function, not an upsell — it would belong behind
 * a paywall only if it cost us something. Idempotent so a re-run is harmless.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('modules')->where('key', 'packeta')->exists()) {
            // modules:sync has not run yet on this environment; the deploy
            // runbook runs it before migrations, but never assume.
            return;
        }

        foreach (DB::table('plans')->pluck('id') as $planId) {
            $exists = DB::table('plan_modules')
                ->where('plan_id', $planId)
                ->where('module_key', 'packeta')
                ->exists();

            if (! $exists) {
                DB::table('plan_modules')->insert(['plan_id' => $planId, 'module_key' => 'packeta']);
            }
        }
    }

    public function down(): void
    {
        DB::table('plan_modules')->where('module_key', 'packeta')->delete();
    }
};
```

> Ověř skutečné názvy sloupců v `plan_modules` (`module_key` vs `module_id`) v `database/migrations/2026_07_19_185544_create_plan_modules_table.php` a v `app/Models/Plan.php` — plán vychází z `belongsToMany(Module::class, 'plan_modules', 'plan_id', 'module_key')`.

- [ ] **Step 4: Model**

```php
<?php

namespace Modules\Packeta\Models;

use App\Core\Money\Money;
use App\Core\Money\MoneyCast;
use App\Core\Shipping\Contracts\ShipmentView;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One parcel handed to a carrier (wave 2.5).
 *
 * Implements the kernel's read-only ShipmentView directly, the way
 * ShippingMethod implements ShippingOption, so the orders module renders a
 * shipment block without ever loading this class.
 */
class Shipment extends Model implements ShipmentView
{
    use BelongsToTenant;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'cod_amount' => MoneyCast::class,
            'submitted_at' => 'datetime',
            'label_printed_at' => 'datetime',
        ];
    }

    public function shipmentId(): int
    {
        return (int) $this->getKey();
    }

    public function shipmentCarrier(): string
    {
        return $this->carrier;
    }

    public function shipmentStatus(): string
    {
        return $this->attributes['status'];
    }

    public function shipmentPacketId(): ?string
    {
        return $this->packet_id;
    }

    public function shipmentBarcode(): ?string
    {
        return $this->barcode;
    }

    public function shipmentCodAmount(): Money
    {
        return $this->cod_amount;
    }

    public function shipmentError(): ?string
    {
        return $this->error;
    }

    public function shipmentSubmittedAt(): ?Carbon
    {
        return $this->submitted_at;
    }
}
```

- [ ] **Step 5: Submitter**

```php
<?php

namespace Modules\Packeta\Services;

use App\Core\Money\Money;
use App\Core\Orders\Contracts\OrderBook;
use App\Core\Shipping\Contracts\CarrierRegistry;
use App\Core\Shipping\Exceptions\CarrierError;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Packeta\Models\Shipment;
use Modules\Shipping\Models\ShippingMethod;

/**
 * Hands one order to the carrier, exactly once (wave 2.5).
 *
 * The claim row is committed BEFORE the HTTP call, never inside a transaction
 * wrapping it: an outbound request held open inside a transaction is the tech
 * debt wave 1.8 recorded (PDF render inside the webhook transaction), and a
 * carrier that accepts a parcel while our transaction rolls back would leave
 * the tenant paying for a parcel we have no record of.
 *
 * The cost of committing first is a `pending` row surviving a crash between
 * commit and answer — so a retry adopts a pending row rather than refusing it.
 */
final class ShipmentSubmitter
{
    public function __construct(
        private readonly OrderBook $orders,
        private readonly CarrierRegistry $carriers,
    ) {}

    public function submit(string $orderUuid): Shipment
    {
        $order = $this->orders->findForAdmin($orderUuid);

        if ($order === null) {
            throw CarrierError::rejected('packeta', 'objednávka neexistuje');
        }

        $snapshot = $order->orderShippingSnapshot();
        $provider = (string) ($snapshot['provider'] ?? '');
        $carrier = $this->carriers->for($provider);

        if ($carrier === null) {
            throw CarrierError::notConfigured($provider === '' ? 'packeta' : $provider);
        }

        $pickupPointCode = (string) ($snapshot['pickup_point']['code'] ?? '');

        if ($pickupPointCode === '') {
            throw CarrierError::rejected($carrier->key(), 'objednávka nemá výdejní místo');
        }

        $shipment = $this->claim($order->orderInternalId(), $carrier->key(), $order);

        if ($shipment->shipmentStatus() === Shipment::STATUS_SUBMITTED) {
            // Already handed over — a second click must not create a second
            // parcel, and must not call the carrier again.
            return $shipment;
        }

        try {
            $result = $carrier->submit(
                $order,
                $pickupPointCode,
                $shipment->cod_amount,
                (int) $shipment->weight_grams,
            );
        } catch (CarrierError $e) {
            $shipment->forceFill([
                'status' => Shipment::STATUS_FAILED,
                'error' => $e->getMessage(),
            ])->save();

            throw $e;
        }

        $shipment->forceFill([
            'status' => Shipment::STATUS_SUBMITTED,
            'packet_id' => $result->packetId,
            'barcode' => $result->barcode,
            'error' => null,
            'submitted_at' => now(),
        ])->save();

        return $shipment;
    }

    /**
     * Takes (or adopts) the single shipment row for this order.
     */
    private function claim(int $orderId, string $carrierKey, $order): Shipment
    {
        $existing = Shipment::where('order_id', $orderId)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return Shipment::create([
                'order_id' => $orderId,
                'carrier' => $carrierKey,
                'status' => Shipment::STATUS_PENDING,
                'cod_amount' => $this->codAmount($order),
                'currency' => $order->orderCurrency(),
                'weight_grams' => $this->weightGrams($order),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another request won the race; use its row rather than failing.
            return Shipment::where('order_id', $orderId)->firstOrFail();
        }
    }

    private function codAmount($order): Money
    {
        $payment = $order->orderPaymentSnapshot();
        $isCod = ($payment['provider'] ?? null) === 'cod';

        return $isCod ? $order->orderTotal() : Money::zero($order->orderCurrency());
    }

    private function weightGrams($order): int
    {
        $snapshot = $order->orderShippingSnapshot();

        return (int) ($snapshot['weight_grams'] ?? 1000);
    }
}
```

> **Dvě věci k ověření v kódu, ne k odhadu:**
> 1. `OrderView` dnes **nemá** `orderShippingSnapshot()` (má jen `orderPaymentSnapshot()`). Přidej ho do kontraktu a do modelu `Order` — bez něj se nedostaneš ani k `pickup_point`, ani k `provider`.
> 2. Ověř konstruktor `Money::zero()` a jméno `PaymentMethod::PROVIDER_COD` a použij konstantu, ne řetězec `'cod'`.
> 3. `OrderPlacer` musí do `shipping_snapshot` ukládat i `provider` a `weight_grams` — dnes tam nejsou. Doplň v Tasku 8, jinak sem nedorazí.

- [ ] **Step 6: Spusť testy**

Run: `php artisan test --compact --filter=ShipmentSubmitterTest`
Expected: PASS (7 testů)

- [ ] **Step 7: Pint a commit**

```bash
./vendor/bin/pint Modules/Packeta app/Core/Orders Modules/Orders tests/Feature/Modules/Packeta
git add Modules/Packeta app/Core/Orders Modules/Orders tests/Feature/Modules/Packeta
git commit -m "feat(packeta): idempotent shipment submission

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 12: Štítky a zrušení zásilky

**Files:**
- Create: `Modules/Packeta/Http/Controllers/ShipmentAdminController.php`
- Create: `Modules/Packeta/Http/Requests/SubmitShipmentsRequest.php`
- Create: `Modules/Packeta/routes/admin.php`
- Modify: `Modules/Packeta/Providers/ModuleProvider.php` (mount rout)
- Test: `tests/Feature/Modules/Packeta/ShipmentAdminTest.php`

**Interfaces:**
- Consumes: `ShipmentSubmitter` (Task 11), `Carrier::labels()` a `cancel()` (Task 10).
- Produces: routy `admin.packeta.shipments.submit`, `admin.packeta.shipments.labels`, `admin.packeta.shipments.cancel`.

- [ ] **Step 1: Napiš failing test**

```php
<?php

namespace Tests\Feature\Modules\Packeta;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShipmentAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_submitting_requires_the_ship_permission(): void
    {
        // Člen tenanta bez packeta.ship -> 403
    }

    public function test_labels_stream_a_pdf(): void
    {
        // Http::fake vrátí base64 PDF -> odpověď má content-type
        // application/pdf a tělo začíná %PDF-
    }

    public function test_labels_are_not_written_to_disk(): void
    {
        // Storage::fake + assert, že po stažení nevznikl soubor.
    }

    public function test_cancelling_marks_the_shipment_cancelled(): void
    {
    }

    public function test_bulk_submit_reports_partial_failure(): void
    {
        // 3 objednávky, prostřední selže -> flash zpráva nese
        // "podáno 2 z 3", 2 shipments submitted, 1 failed.
    }

    public function test_a_shipment_of_another_tenant_is_not_reachable(): void
    {
        // 404 (tenant-scoped route model binding), ne 403.
    }

    public function test_a_carrier_error_is_shown_not_thrown(): void
    {
        // fault odpověď -> redirect s chybovou hláškou, žádná 500.
    }
}
```

- [ ] **Step 2: Spusť test, ověř selhání**

Run: `php artisan test --compact --filter=ShipmentAdminTest`
Expected: FAIL — routy neexistují

- [ ] **Step 3: Request**

```php
<?php

namespace Modules\Packeta\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitShipmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('packeta.ship') ?? false;
    }

    public function rules(): array
    {
        return [
            'order_uuids' => ['required', 'array', 'min:1', 'max:100'],
            'order_uuids.*' => ['required', 'string', 'uuid'],
        ];
    }
}
```

- [ ] **Step 4: Controller**

```php
<?php

namespace Modules\Packeta\Http\Controllers;

use App\Core\Shipping\Contracts\CarrierRegistry;
use App\Core\Shipping\Exceptions\CarrierError;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Packeta\Http\Requests\SubmitShipmentsRequest;
use Modules\Packeta\Models\Shipment;
use Modules\Packeta\Services\ShipmentSubmitter;
use Modules\Shipping\Models\ShippingMethod;

/**
 * Handing parcels over, printing labels, cancelling (wave 2.5).
 *
 * Every carrier error is caught and shown: a carrier's outage is an everyday
 * event for a shop, not a 500 page.
 */
class ShipmentAdminController extends Controller
{
    public function __construct(
        private readonly ShipmentSubmitter $submitter,
        private readonly CarrierRegistry $carriers,
    ) {}

    public function submit(SubmitShipmentsRequest $request): RedirectResponse
    {
        $uuids = $request->validated()['order_uuids'];

        $done = 0;
        $errors = [];

        foreach ($uuids as $uuid) {
            try {
                $this->submitter->submit($uuid);
                $done++;
            } catch (CarrierError $e) {
                // One rejected parcel must not abort the rest of the batch —
                // a shop dispatching thirty boxes cannot lose the run because
                // one address is malformed.
                $errors[] = $e->getMessage();
            }
        }

        $message = $errors === []
            ? sprintf('Podáno %d zásilek.', $done)
            : sprintf('Podáno %d z %d. Chyby: %s', $done, count($uuids), implode(' | ', array_unique($errors)));

        return back()->with('status', $message);
    }

    public function labels(Request $request): Response|RedirectResponse
    {
        abort_unless($request->user()?->can('packeta.ship'), 403);

        $ids = array_map('intval', (array) $request->input('shipment_ids', []));
        $format = (string) $request->input('format', 'A7 on A4');

        abort_if($ids === [], 422);

        $carrier = $this->carriers->for(ShippingMethod::PROVIDER_PACKETA);

        if ($carrier === null) {
            return back()->withErrors(['carrier' => 'Zásilkovna není nastavená.']);
        }

        // Tenant scope on the model keeps another shop's ids out of the list.
        $ids = Shipment::whereIn('id', $ids)->pluck('id')->all();

        try {
            $pdf = $carrier->labels($ids, $format);
        } catch (CarrierError $e) {
            return back()->withErrors(['carrier' => $e->getMessage()]);
        }

        Shipment::whereIn('id', $ids)->update(['label_printed_at' => now()]);

        // Streamed, never stored: a label is a one-off print, and FileStorage
        // has nothing to keep.
        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="stitky.pdf"',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    public function cancel(Request $request, Shipment $shipment): RedirectResponse
    {
        abort_unless($request->user()?->can('packeta.ship'), 403);

        $carrier = $this->carriers->for($shipment->shipmentCarrier());

        if ($carrier === null) {
            return back()->withErrors(['carrier' => 'Zásilkovna není nastavená.']);
        }

        if ($shipment->shipmentPacketId() !== null) {
            try {
                $carrier->cancel($shipment->shipmentPacketId());
            } catch (CarrierError $e) {
                return back()->withErrors(['carrier' => $e->getMessage()]);
            }
        }

        $shipment->forceFill(['status' => Shipment::STATUS_CANCELLED])->save();

        return back()->with('status', 'Zásilka byla zrušena.');
    }
}
```

Routy `Modules/Packeta/routes/admin.php` — **mimo `auth` alias**, přesně jako ostatní modulové admin routy (rozhodnutí 2026-07-20):

```php
<?php

use Illuminate\Support\Facades\Route;
use Modules\Packeta\Http\Controllers\DispatchQueueController;
use Modules\Packeta\Http\Controllers\ShipmentAdminController;

Route::prefix('m/packeta')->name('packeta.')->group(function () {
    Route::get('expedice', [DispatchQueueController::class, 'index'])->name('dispatch');
    Route::post('zasilky/podat', [ShipmentAdminController::class, 'submit'])->name('shipments.submit');
    Route::post('zasilky/stitky', [ShipmentAdminController::class, 'labels'])->name('shipments.labels');
    Route::delete('zasilky/{shipment}', [ShipmentAdminController::class, 'cancel'])->name('shipments.cancel');
});
```

> Zkopíruj přesný tvar skupiny (prefix, middleware `module:packeta` → `tenant.member`, jméno) z `Modules/Shipping/routes/admin.php` — mountuje je `ModuleRouteRegistrar`, ne tenhle soubor.

- [ ] **Step 5: Spusť testy**

Run: `php artisan test --compact --filter=ShipmentAdminTest`
Expected: PASS (7 testů)

- [ ] **Step 6: Pint a commit**

```bash
./vendor/bin/pint Modules/Packeta tests/Feature/Modules/Packeta
git add Modules/Packeta tests/Feature/Modules/Packeta
git commit -m "feat(packeta): label printing and shipment cancellation

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 13: Tracking pro zákazníka

**Files:**
- Create: `Modules/Packeta/Services/EloquentShipmentBook.php`
- Modify: `Modules/Packeta/Providers/ModuleProvider.php` (binding `ShipmentBook`)
- Modify: šablona detailu objednávky v účtu zákazníka (`Modules/Orders/Resources/views/...` — najdi ji)
- Modify: e-mail „objednávka odeslána" (šablona v modulu `orders`)
- Test: `tests/Feature/Modules/Packeta/ShipmentTrackingTest.php`

**Interfaces:**
- Consumes: `ShipmentBook` (Task 1), `Shipment` (Task 11), `Carrier::trackingUrl()` (Task 10).
- Produces: `EloquentShipmentBook::forOrder(int): ?ShipmentView`.

- [ ] **Step 1: Napiš failing test**

```php
<?php

namespace Tests\Feature\Modules\Packeta;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShipmentTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_customer_sees_the_tracking_link_on_their_own_order(): void
    {
    }

    public function test_a_customer_cannot_see_another_customers_order(): void
    {
        // 404, žádný leak
    }

    public function test_no_tracking_block_before_the_parcel_is_submitted(): void
    {
    }

    public function test_the_shipment_book_answers_null_across_tenants(): void
    {
        // Zásilka tenanta A není vidět v kontextu tenanta B.
    }
}
```

- [ ] **Step 2: Spusť test, ověř selhání**

Run: `php artisan test --compact --filter=ShipmentTrackingTest`
Expected: FAIL

- [ ] **Step 3: `ShipmentBook`**

```php
<?php

namespace Modules\Packeta\Services;

use App\Core\Shipping\Contracts\ShipmentBook;
use App\Core\Shipping\Contracts\ShipmentView;
use Modules\Packeta\Models\Shipment;
use Modules\Storefront\Support\ShopModules;

/**
 * Read side of shipments for callers outside this module (wave 2.5).
 *
 * Gated on the module being active for the tenant, so a deactivated module
 * answers as if there were no shipment rather than leaking rows it owns —
 * the same shape EloquentShippingOptions keeps.
 */
final class EloquentShipmentBook implements ShipmentBook
{
    public function __construct(private readonly ShopModules $modules) {}

    public function forOrder(int $orderId): ?ShipmentView
    {
        if (! $this->modules->has('packeta')) {
            return null;
        }

        // BelongsToTenant scopes this; another tenant's row is invisible.
        return Shipment::where('order_id', $orderId)->first();
    }
}
```

Binding: `$this->app->bind(ShipmentBook::class, EloquentShipmentBook::class);`

- [ ] **Step 4: Zobrazení u zákazníka a v e-mailu**

Do zákaznického detailu objednávky (Blade, storefront):

```blade
    @if ($shipment !== null && $shipment->shipmentBarcode() !== null)
        <section class="card mt-6 p-4">
            <h2 class="text-lg font-medium text-slate-900">Sledování zásilky</h2>
            <p class="mt-1 text-sm text-slate-600">Číslo zásilky: {{ $shipment->shipmentBarcode() }}</p>
            <a href="{{ $trackingUrl }}" rel="nofollow noopener" target="_blank"
               class="mt-2 inline-block text-brand underline">Sledovat zásilku</a>
        </section>
    @endif
```

Controller zákaznického detailu doplní `shipment` z `ShipmentBook` a `trackingUrl` z `CarrierRegistry`. Totéž do šablony e-mailu „odesláno".

- [ ] **Step 5: Spusť testy**

Run: `php artisan test --compact --filter=ShipmentTrackingTest`
Expected: PASS (4 testy)

- [ ] **Step 6: Pint a commit**

```bash
./vendor/bin/pint Modules/Packeta Modules/Orders tests/Feature/Modules/Packeta
git add Modules/Packeta Modules/Orders tests/Feature/Modules/Packeta
git commit -m "feat(packeta): tracking link for the customer

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Etapa 6 — admin obrazovky

### Task 14: Expediční fronta

**Files:**
- Create: `Modules/Packeta/Http/Controllers/DispatchQueueController.php`
- Create: `resources/js/Pages/Modules/Packeta/Dispatch.vue`
- Test: `tests/Feature/Modules/Packeta/DispatchQueueTest.php`

**Interfaces:**
- Consumes: routy z Tasku 12, `OrderBook` (existující).
- Produces: routa `admin.packeta.dispatch`.

- [ ] **Step 1: Napiš failing test**

```php
<?php

namespace Tests\Feature\Modules\Packeta;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DispatchQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_queue_lists_only_unshipped_packeta_orders(): void
    {
        // Objednávka s osobním odběrem tam být nesmí;
        // už podaná objednávka taky ne.
    }

    public function test_the_queue_requires_the_ship_permission(): void
    {
        // 403 bez packeta.ship
    }

    public function test_the_queue_shows_only_this_tenants_orders(): void
    {
    }

    public function test_a_suspended_tenant_cannot_submit(): void
    {
        // Write-freeze z CheckTenantStatus: POST -> 503.
    }
}
```

- [ ] **Step 2: Spusť test, ověř selhání**

Run: `php artisan test --compact --filter=DispatchQueueTest`
Expected: FAIL — routa neexistuje

- [ ] **Step 3: Controller**

```php
<?php

namespace Modules\Packeta\Http\Controllers;

use App\Core\Orders\Contracts\OrderBook;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The dispatch queue: orders waiting to be handed to Zásilkovna (wave 2.5).
 *
 * Its own screen rather than bulk actions bolted onto the order list, for two
 * reasons: it leaves the orders module untouched, and it matches the actual
 * daily routine — pack the boxes, hand over the batch, print the labels.
 */
class DispatchQueueController extends Controller
{
    public function __construct(private readonly OrderBook $orders) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('packeta.ship'), 403);

        return Inertia::render('Modules/Packeta/Dispatch', [
            'orders' => $this->pending(),
        ]);
    }
}
```

> `OrderBook` dnes nemá dotaz „objednávky s dopravcem X bez zásilky". Přidej mu metodu `awaitingShipment(string $provider): Collection` (kontrakt + implementace + null implementace), nebo — pokud se to ukáže jako příliš invazivní do `orders` — dotaz postav v modulu `packeta` nad `Shipment` a uuid objednávek si vyžádej přes existující `OrderBook` API. **Rozhodni při implementaci a zdůvodni v komentáři.**

- [ ] **Step 4: Vue stránka**

`resources/js/Pages/Modules/Packeta/Dispatch.vue` — checkboxy, „Podat vybrané", „Tisk štítků", select formátu. Drž se vzorů z `resources/js/Pages/Modules/Orders/Index.vue`:
- výběr přes `<input type="checkbox">` s viditelným `<label>` (WCAG 2.2 AA)
- „vybrat vše" jako tlačítko, ne jen hlavičkový checkbox
- akce jako `router.post(...)`, chybové hlášky z `flash`/`errors`
- **žádná mazací akce bez potvrzovacího dialogu** — „Zrušit zásilku" musí mít potvrzení (CLAUDE.md)

- [ ] **Step 5: Spusť testy**

Run: `php artisan test --compact --filter=DispatchQueueTest`
Expected: PASS (4 testy)

Run: `npm run build`
Expected: projde

- [ ] **Step 6: Pint a commit**

```bash
./vendor/bin/pint Modules/Packeta tests/Feature/Modules/Packeta
git add Modules/Packeta resources/js/Pages/Modules/Packeta tests/Feature/Modules/Packeta
git commit -m "feat(packeta): dispatch queue screen

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 15: Blok Doprava v detailu objednávky

**Files:**
- Modify: `Modules/Orders/Http/Controllers/OrderAdminController.php`
- Modify: `resources/js/Pages/Modules/Orders/Show.vue`
- Test: `tests/Feature/Modules/Orders/OrderShipmentBlockTest.php`

**Interfaces:**
- Consumes: `ShipmentBook::forOrder()` (Task 13), routy z Tasku 12.
- Produces: nic dalšího.

- [ ] **Step 1: Napiš failing test**

```php
<?php

namespace Tests\Feature\Modules\Orders;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderShipmentBlockTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_detail_carries_the_pickup_point_from_the_snapshot(): void
    {
        // assertInertia: prop pickupPoint má name a adresu.
    }

    public function test_the_detail_carries_the_shipment_when_one_exists(): void
    {
    }

    public function test_the_shipment_prop_is_null_when_the_module_is_off(): void
    {
        // Vypni packeta -> prop null, stránka se vykreslí.
    }

    public function test_cancelling_an_order_with_a_submitted_shipment_warns(): void
    {
        // Storno projde, prop nese příznak, že zásilka je podaná.
    }
}
```

- [ ] **Step 2: Spusť test, ověř selhání**

Run: `php artisan test --compact --filter=OrderShipmentBlockTest`
Expected: FAIL — prop neexistuje

- [ ] **Step 3: Controller**

```php
        // Rendered only when a carrier module is running; the kernel's null
        // book answers null and the block disappears — exactly how documents
        // reach this same screen (wave 1.5).
        $shipment = $this->shipments->forOrder($order->orderInternalId());

        return Inertia::render('Modules/Orders/Show', [
            // …existující propy…
            'pickupPoint' => $order->orderShippingSnapshot()['pickup_point'] ?? null,
            'shipment' => $shipment === null ? null : [
                'id' => $shipment->shipmentId(),
                'status' => $shipment->shipmentStatus(),
                'barcode' => $shipment->shipmentBarcode(),
                'error' => $shipment->shipmentError(),
                'submitted_at' => $shipment->shipmentSubmittedAt()?->toIso8601String(),
                'tracking_url' => $shipment->shipmentBarcode() === null
                    ? null
                    : $this->carriers->for($shipment->shipmentCarrier())?->trackingUrl($shipment->shipmentBarcode()),
            ],
        ]);
```

- [ ] **Step 4: Vue blok**

Do `Show.vue` sekci „Doprava" (pod existující bloky, vzor bloku dokladů):
- výdejní místo z `pickupPoint`
- stav zásilky, čárový kód, odkaz na tracking
- tlačítka „Podat do Zásilkovny" (když `shipment === null || shipment.status !== 'submitted'`), „Štítek", „Zrušit zásilku" (**s potvrzovacím dialogem**)
- při stornu objednávky s `shipment.status === 'submitted'` varovný text, že zásilka zůstává podaná

- [ ] **Step 5: Spusť testy**

Run: `php artisan test --compact --filter=OrderShipmentBlockTest`
Expected: PASS (4 testy)

Run: `npm run build`
Expected: projde

- [ ] **Step 6: Pint a commit**

```bash
./vendor/bin/pint Modules/Orders tests/Feature/Modules/Orders
git add Modules/Orders resources/js/Pages/Modules/Orders tests/Feature/Modules/Orders
git commit -m "feat(orders): shipment block on the order detail

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 16: Průchod bez JS od košíku po zásilku

**Files:**
- Test: `tests/Feature/Modules/Packeta/PacketaEndToEndTest.php`

**Interfaces:**
- Consumes: všechno předchozí.
- Produces: nic — je to důkaz, ne stavební kámen.

- [ ] **Step 1: Napiš test celého průchodu**

```php
<?php

namespace Tests\Feature\Modules\Packeta;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The whole journey over plain HTTP, no JavaScript anywhere: catalogue → cart
 * → delivery → pickup point → details → placed order → submitted parcel.
 *
 * Every earlier test proves one piece; this one proves they fit together, which
 * is the acceptance criterion that actually matters (spec §16.3).
 */
class PacketaEndToEndTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_shopper_buys_with_zasilkovna_without_javascript(): void
    {
        // 1. Tenant s moduly products/checkout/shipping/orders/packeta,
        //    metoda Zásilkovna s credentials, katalog s jedním místem,
        //    jeden produkt.
        // 2. POST /kosik/pridat
        // 3. GET /pokladna/doprava -> obsahuje Zásilkovnu
        // 4. POST /pokladna/doprava se shipping_method_id
        // 5. GET /pokladna/vydejni-misto?q=Brno -> místo v HTML
        // 6. POST /pokladna/vydejni-misto s kódem
        // 7. GET /pokladna/doprava -> vybrané místo vypsané
        // 8. POST /pokladna/udaje -> objednávka vznikla,
        //    shipping_snapshot nese pickup_point z katalogu
        // 9. Admin: POST admin.packeta.shipments.submit (Http::fake ok)
        //    -> shipment submitted s barcode
        // 10. Zákazník vidí tracking odkaz u své objednávky

        $this->markTestIncomplete('Vyplň podle CheckoutFlowTest a ShipmentAdminTest.');
    }
}
```

- [ ] **Step 2: Vyplň test a spusť**

Run: `php artisan test --compact --filter=PacketaEndToEndTest`
Expected: PASS — a `markTestIncomplete` pryč

- [ ] **Step 3: Plná sada ve foregroundu**

Run: `php artisan test --compact`
Expected: PASS, ~1290+ testů

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Modules/Packeta/PacketaEndToEndTest.php
git commit -m "test(packeta): end-to-end purchase and dispatch without JS

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Po dokončení

1. **Ruční kontrola dle `.claude/rules/storefront-rendering.md`:**
   - `curl -s <url>/pokladna/vydejni-misto?q=Brno | grep` — jsou místa v surovém HTML?
   - JS vypnutý v prohlížeči — projde celý nákup?
2. **A11y:** spusť agenta `a11y-checker` na `Dispatch.vue`, `Show.vue` a `pickup-point.blade.php`.
3. **Code review:** `superpowers:requesting-code-review` nad celou větví.
4. **As-is:** `docs/as-is/2026-07-XX-zasilkovna.md` + řádek v `STATUS.md` + rozhodnutí do `CLAUDE.md`.
5. **Uzavření vlny:** `/finish-wave` (minor bump 0.23.0, merge do `main`, push).

---

## Self-review

**Pokrytí specu:**

| Požadavek specu | Task |
|---|---|
| Kernel kontrakty `Carrier`/`CarrierRegistry`/`PickupPointCatalog`/`ShipmentBook` | 1 |
| `ShippingOption::provider()` | 2 |
| `provider='packeta'` v enum | 2 |
| Šifrování `shipping_methods.settings` | 3 |
| Credentials v adminu (`apiPassword`/`apiKey`/`eshop`) | 3 |
| `pickup_points` netenantová + allowlist | 4 |
| Sync feedu, deaktivace zmizelých, guard prázdného feedu | 5 |
| `carts.pickup_point_code` + nulování | 6 |
| Výběr místa bez JS | 7 |
| Filtr metod bez běžícího driveru | 7 |
| Gate na `place()` + snapshot | 8 |
| JS ostrůvek s widgetem on-click | 9 |
| REST/XML klient, driver, registry | 10 |
| `shipments` + idempotence + COD | 11 |
| Tarif base | 11 |
| Štítky (jednotlivě i hromadně), zrušení | 12 |
| Tracking pro zákazníka | 13 |
| Expediční fronta | 14 |
| Blok Doprava v detailu objednávky | 15 |
| Průchod bez JS end-to-end | 16 |
| Oprávnění `packeta.manage` / `packeta.ship` | 4 (manifest), 12, 14 |

**Nalezené a doplněné mezery:**
- `OrderView` **nemá** `orderShippingSnapshot()` — bez něj se driver nedostane k `pickup_point` ani `provider`. Doplněno jako explicitní krok v Tasku 11 s odkazem zpět na Task 8, který do snapshotu musí zapsat i `provider` a `weight_grams`.
- `OrderBook` nemá dotaz na objednávky čekající na podání — v Tasku 14 explicitně rozhodnout mezi rozšířením kontraktu a dotazem v modulu, se zdůvodněním v kódu.
- Guard `feed_min_points` by shodil testy s malým fake feedem — v Tasku 5 poznámka, kde `config()` v testu přepsat.

**Konzistence typů:** `ShipmentResult(packetId, barcode)` se stejnými názvy použit v Tasku 1, 10 a 11. `Shipment::STATUS_*` v Tasku 11, 12, 13, 15. `PickupPoint::normalise()` v Tasku 4 a 5. `Carrier::key()` vrací `shipping_methods.provider`, konzumováno v Tasku 8, 11, 12.
