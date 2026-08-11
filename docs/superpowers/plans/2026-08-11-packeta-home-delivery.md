# Zásilkovna — doručení na adresu a rozlišení boxů: implementační plán

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Zákazník si zvolí doručení na svou adresu přes Zásilkovnu a nájemce takovou zásilku podá ze stejné expediční fronty; zároveň jde v katalogu odlišit Z-BOX od pobočky.

**Architecture:** Druhý driver `PacketaHomeDelivery` (`packeta_hd`) vedle stávajícího `PacketaCarrier`, oba v modulu `packeta`. Předtím se musí rozpojit tři místa přidrátovaná k Zásilkovně — bez nich doručení na adresu nemůže fungovat, protože snímek objednávky nemá kam uložit dopravce a hmotnost.

**Tech Stack:** Laravel 13, `Http` fasáda (žádný SDK Zásilkovny), Blade SSR checkout bez JS, Inertia admin, Playwright.

**Spec:** `docs/superpowers/specs/2026-08-11-packeta-home-delivery-design.md`

## Global Constraints

- **Žádné reálné volání cizího API v testech.** `Http::fake` všude, vzor vlny 2.5.
- **Klient nikdy nediktuje, co se uloží.** Posílá kód místa nebo volbu metody; název, adresu a parametry si server vždy dohledá sám (rozhodnutí 2026-07-27, stejné pravidlo jako `variant_id` a `image_path`).
- **Checkout musí projít bez JavaScriptu** — akceptační kritérium §16.3, platí i pro doručení na adresu.
- **Idempotence podání se nemění:** CAS claim, staleness reclaim a fail-fast guard na vztah timeout/práh (rozhodnutí 2026-07-27) platí pro oba drivery. Cizí HTTP volání nikdy uvnitř transakce.
- **Objednávky založené před touto vlnou musí jít dál podat.** Žádná migrace snímků.
- Kód, komentáře i commity anglicky; UI česky. Komentář vysvětluje **proč**.
- Modul `packeta` zůstává `level: base`.
- Před commitem PHP: `./vendor/bin/pint`. Projekt **nepoužívá prettier**.
- Testy pouštěj **ve foregroundu** a čekej na ně. Nikdy na pozadí, nikdy přes Monitor — background běh přežije tah agenta a vrátí report, který neodpovídá skutečnosti.
- PHPUnit po adresářích (`php artisan test tests/Feature/Modules/Packeta --compact`), nikdy celou sadu jedním příkazem — přeteče timeout a sdílená testovací databáze kolabuje.
- E2E: `npx playwright test --config=e2e/playwright.config.ts e2e/tests/<soubor>.spec.ts`; po změně frontendu `npm run build`.

---

### Task 1: `provider` a `weight_grams` na top-level snímku dopravy

**Files:**
- Modify: `Modules/Orders/Services/OrderPlacer.php` (`shippingSnapshot()` ~807, volání ~169–235, `resolvePickupPoint()` ~643)
- Modify: `Modules/Packeta/Services/ShipmentSubmitter.php` (~104–115, ~223)
- Test: `tests/Feature/Modules/Packeta/ShipmentSubmitterTest.php` (existuje), `tests/Feature/Modules/Orders/` (snímek)

**Interfaces:**
- Consumes: nic
- Produces: `shipping_snapshot` nese `provider` (string|null) a `weight_grams` (int) na top-level. `pickup_point` beze změny, jen u dopravce, který místo vyžaduje.

- [ ] **Step 1: Napsat padající testy**

Dva testy. První: objednávka s dopravcem **bez** výdejního místa nese ve snímku `provider` a `weight_grams`.

```php
public function test_the_shipping_snapshot_carries_the_provider_at_top_level(): void
{
    // ... založ objednávku s dopravní metodou, jejíž provider je 'packeta_hd'
    // (nebo libovolný, který nevyžaduje výdejní místo)

    $snapshot = $order->shipping_snapshot;

    $this->assertSame('packeta_hd', $snapshot['provider']);
    $this->assertGreaterThan(0, $snapshot['weight_grams']);
    $this->assertArrayNotHasKey('pickup_point', $snapshot);
}
```

Druhý: `ShipmentSubmitter` podá objednávku, jejíž snímek nese `provider` **jen** uvnitř `pickup_point` — tedy tvar, který existoval před touto změnou.

```php
public function test_an_order_snapshotted_before_the_change_can_still_be_submitted(): void
{
    // Zapiš objednávce shipping_snapshot ve STARÉM tvaru:
    // ['id'=>…, 'name'=>…, 'pickup_point'=>['code'=>'123','provider'=>'packeta','weight_grams'=>1000]]
    // bez top-level provider/weight_grams.

    // Http::fake na createPacket
    // submitter->submit($order) musí projít, ne spadnout na notConfigured
}
```

- [ ] **Step 2: Spustit, ověřit pád**

```bash
php artisan test tests/Feature/Modules/Packeta --compact
```

Očekávané: FAIL — snímek `provider` na top-level nemá.

- [ ] **Step 3: Doplnit snímek**

V `OrderPlacer::shippingSnapshot()` přidej `provider` — přesně jako to od začátku dělá `paymentSnapshot()` o pár řádků níž (`'provider' => $option->provider()`). Tenhle plán tedy nezavádí nový vzor, jen dorovnává dopravu k platbě.

`weight_grams` se dnes počítá **uvnitř** `resolvePickupPoint()` (~662) a jinam se nedostane. Vytáhni součet výš, aby byl k dispozici pro snímek vždy, a `resolvePickupPoint()` ať používá tutéž hodnotu — dva nezávislé součty téhož by se dřív nebo později rozešly.

- [ ] **Step 4: Přepsat čtení v `ShipmentSubmitter`**

Na ~110 čte `$order->orderShippingSnapshot()['pickup_point']['provider']`. Nově:

```php
$snapshot = $order->orderShippingSnapshot();
$pickupPoint = $snapshot['pickup_point'] ?? null;

// Top-level first; the nested copy is where orders placed before this wave
// keep it, and those must stay submittable (no snapshot migration — an order
// snapshot is not rewritten retroactively in this project).
$provider = (string) ($snapshot['provider'] ?? $pickupPoint['provider'] ?? '');
$weightGrams = (int) ($snapshot['weight_grams'] ?? $pickupPoint['weight_grams'] ?? 0) ?: 1000;
```

Zachovej stávající fallback na 1000 g i s jeho komentářem.

- [ ] **Step 5: Spustit testy**

```bash
php artisan test tests/Feature/Modules/Packeta tests/Feature/Modules/Orders --compact
```

Očekávané: PASS, včetně existujících testů podání.

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint --dirty
git add -A && git commit -m "refactor(orders): carry the carrier and weight on the shipping snapshot itself"
```

---

### Task 2: `PickupPointCatalog::search()` zná dopravce

**Files:**
- Modify: `app/Core/Shipping/Contracts/PickupPointCatalog.php`
- Modify: `Modules/Packeta/Services/EloquentPickupPointCatalog.php`
- Modify: `app/Core/Shipping/NullPickupPointCatalog.php`
- Modify: `Modules/Checkout/Http/Controllers/PickupPointController.php` (~37, ~56, ~93)
- Test: `tests/Feature/Modules/Packeta/PickupPointCatalogTest.php`, `tests/Feature/Modules/Checkout/`

**Interfaces:**
- Consumes: Task 1
- Produces: `search(string $carrier, string $query, int $limit = 20)` — dopravce jako **první** parametr, stejně jako už ho má `find()`.

- [ ] **Step 1: Napsat padající test**

```php
public function test_the_search_does_not_return_another_carriers_point(): void
{
    // Založ dvě místa: carrier 'packeta' a carrier 'other', obě jménem "Testov"
    $found = app(PickupPointCatalog::class)->search('packeta', 'Testov');

    $this->assertCount(1, $found);
    $this->assertSame('packeta', $found->first()->carrier());
}
```

Plus test controlleru: výběr místa u dopravní metody s providerem `packeta` hledá v katalogu `packeta`, ne napříč.

- [ ] **Step 2: Spustit, ověřit pád**

Očekávané: FAIL — `search()` parametr dopravce nemá, takže test neprojde ani typově.

- [ ] **Step 3: Změnit kontrakt a implementaci**

Přidej parametr do rozhraní, implementace filtruje `where('carrier', $carrier)`. Null binding vrací prázdnou kolekci jako dnes.

- [ ] **Step 4: Odvodit dopravce z košíku, ne z konstanty**

`PickupPointController` má dnes na dvou místech `ShippingMethod::PROVIDER_PACKETA` natvrdo. Dopravce vezmi z dopravní metody vybrané v košíku — tutéž cestou, jakou už `CheckoutController` zjišťuje, jestli metoda vyžaduje výdejní místo.

Klíč widgetu (~93) čte dnes přímo model `ShippingMethod` modulu `shipping` s natvrdo zadaným providerem. Nech ho číst podle **zjištěného** dopravce. Průchod přes `CarrierRegistry` tady **nepoužívej**: registry vrátí driver jen s plnou konfigurací (`api_password` i `eshop`), takže by se výběr místa neotevřel nájemci, který credentials ještě nezadal — a šest záměrných testů zkouší picker a widget nezávisle na credentials.

- [ ] **Step 5: Spustit testy**

```bash
php artisan test tests/Feature/Modules/Packeta tests/Feature/Modules/Checkout --compact
```

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint --dirty
git add -A && git commit -m "feat(shipping): scope the pickup point search to one carrier"
```

---

### Task 3: Typ výdejního místa (pobočka / box)

**Files:**
- Create: `Modules/Packeta/Database/Migrations/…_add_type_to_pickup_points_table.php`
- Modify: `Modules/Packeta/Models/PickupPoint.php`
- Modify: `Modules/Packeta/Services/PickupPointSync.php`
- Modify: `app/Core/Shipping/Contracts/PickupPoint.php` (přibude `pointIsBox(): bool` k `pointCarrier()`, `pointName()` a spol.)
- Modify: `Modules/Checkout/resources/views/checkout/pickup-point.blade.php`
- Test: `tests/Feature/Modules/Packeta/PickupPointSyncTest.php`, `tests/Feature/Modules/Checkout/`

**Interfaces:**
- Consumes: Task 2
- Produces: `pickup_points.type` (`branch` | `box`), na jádrovém hodnotovém typu čitelný jako `isBox()`.

- [ ] **Step 1: Zjistit, co feed vrací — a nahlásit, když nic**

**Než napíšeš řádek kódu:** podívej se do fixture feedu, který používá `PickupPointSyncTest`, a do dokumentace Zásilkovny, jaké pole nese typ místa (kandidáti podle jejich dokumentace: `type`, `group`, `isBox`, `packetaBox`). Pokud fixture takové pole nemá, dohledej reálný tvar feedu v dokumentaci.

**Když typ ve feedu není vůbec, tuhle část nedělej a nahlas to** — `DONE_WITH_CONCERNS` s vysvětlením. Odvozovat typ z názvu místa („Z-BOX Praha 4") je heuristika nad cizím textem: rozbije se, jakmile Zásilkovna pobočky přejmenuje, a rozbije se tiše.

- [ ] **Step 2: Napsat padající test**

```php
public function test_the_sync_records_whether_a_point_is_a_box(): void
{
    // Http::fake feed se dvěma místy: jedno pobočka, jedno box
    // (pole podle toho, co jsi zjistil ve Step 1)

    app(PickupPointSync::class)->run();

    $this->assertSame('box', PickupPoint::where('code', '…')->value('type'));
    $this->assertSame('branch', PickupPoint::where('code', '…')->value('type'));
}
```

- [ ] **Step 3: Spustit, ověřit pád**

- [ ] **Step 4: Migrace a sync**

Sloupec `type` s výchozí hodnotou `branch` — existující řádky jsou pobočky, dokud je další sync nepřepíše, a to je pravdivější než `null`. Sync mapuje pole feedu na `branch`/`box`; **neznámou hodnotu mapuj na `branch`**, ne na výjimku: cizí feed, který přidá třetí typ, nesmí shodit denní sync a s ním celý katalog.

- [ ] **Step 5: Filtr v pokladně**

Do výběru místa přidej přepínač „jen výdejní boxy" jako **odkaz s query parametrem**, ne JS — celý picker je server-rendered a musí fungovat bez JS. U každého místa vypiš, o jaký typ jde (text, ne jen ikona — barva ani obrázek nic nesdělí tomu, kdo je nevidí).

- [ ] **Step 6: Spustit testy**

```bash
php artisan test tests/Feature/Modules/Packeta tests/Feature/Modules/Checkout --compact
```

- [ ] **Step 7: Commit**

```bash
./vendor/bin/pint --dirty
git add -A && git commit -m "feat(packeta): tell pickup boxes apart from staffed branches"
```

---

### Task 4: Kontrakt dopravce a driver doručení na adresu

**Files:**
- Modify: `app/Core/Shipping/Contracts/Carrier.php`
- Modify: `Modules/Packeta/Services/PacketaCarrier.php` (jen nová signatura)
- Create: `Modules/Packeta/Services/PacketaHomeDelivery.php`
- Modify: `Modules/Packeta/Services/PacketaClient.php` (nové metody)
- Modify: `Modules/Packeta/Services/EloquentCarrierRegistry.php`
- Modify: `Modules/Shipping/Models/ShippingMethod.php` (nová konstanta provideru)
- Modify: `Modules/Packeta/Services/ShipmentSubmitter.php` (předání adresy)
- Test: `tests/Feature/Modules/Packeta/PacketaHomeDeliveryTest.php` (nový)

**Interfaces:**
- Consumes: Task 1 (`provider` na top-level)
- Produces: `Carrier::submit(OrderView $order, string $destination, Money $codAmount, int $weightGrams, ?array $dimensionsMm = null, ?array $address = null)`. Nový provider `ShippingMethod::PROVIDER_PACKETA_HD = 'packeta_hd'`.

- [ ] **Step 1: Napsat padající testy**

Tři testy nad `Http::fake`:

```php
public function test_an_address_order_is_created_with_the_carrier_id_and_the_address(): void
{
    // fake createPacket; ověř, že odeslané tělo nese
    //   addressId = id partnerského dopravce (z nastavení metody)
    //   street / houseNumber / city / zip z adresy objednávky
}

public function test_the_courier_number_is_ordered_as_part_of_submitting(): void
{
    // fake createPacket + packetCourierNumber; ověř, že proběhla OBĚ volání
    // a že selhání druhého je selháním podání (CarrierError), ne tichý úspěch
}

public function test_submitting_without_an_address_fails_loudly(): void
{
    // $address = null u driveru, který nevyžaduje výdejní místo
    // → CarrierError, žádné HTTP volání
}
```

- [ ] **Step 2: Spustit, ověřit pád**

```bash
php artisan test tests/Feature/Modules/Packeta --compact
```

- [ ] **Step 3: Rozšířit kontrakt**

`$pickupPointCode` přejmenuj na `$destination` a přidej `?array $address = null` jako poslední parametr. Do docblocku napiš, co je co: u dopravce s výdejním místem je `$destination` kód místa, u dopravce doručujícího na adresu id partnerského dopravce.

Uprav `PacketaCarrier` na novou signaturu (chování beze změny) a všechny volající.

- [ ] **Step 4: Doplnit klienta**

`PacketaClient` dostane `packetCourierNumber(string $packetId): string` a `courierLabelPdf(array $packetIds, string $format): string`. Stejný styl jako existující metody — `Http` fasáda, XML tělo, chyby přes `CarrierError`.

- [ ] **Step 5: Napsat driver**

`PacketaHomeDelivery implements Carrier`:
- `key()` → `packeta_hd`
- `requiresPickupPoint()` → `false`
- `submit()` → `createPacket` s `addressId` = id partnerského dopravce a adresními poli, **pak** `packetCourierNumber`. Chybějící `$address` odmítni dřív, než se sáhne na síť.
- `labels()` → `courierLabelPdf`, ne `labelsPdf`
- `cancel()`, `trackingUrl()` → stejné jako `PacketaCarrier`

Krok „objednat u kurýra" patří do podání, ne do tisku: štítek bez čísla kurýra vytisknout nejde a nájemce, kterému podání projde a tisk pak selže, nemá jak zjistit proč.

- [ ] **Step 6: Registry**

`EloquentCarrierRegistry::for()` obsluhuje oba klíče. Dnes je psaná na jeden — přepiš ji tak, aby hledala dopravní metodu podle **předaného** provideru, a podle něj sestavila správný driver. `available()` vrátí oba klíče, které jsou nakonfigurované.

- [ ] **Step 7: Předat adresu z `ShipmentSubmitter`**

Adresu vezmi z objednávky (doručovací, s fallbackem na fakturační — stejné pravidlo, jaké platí jinde v projektu; ověř, jak to dělá potvrzovací e-mail, a drž se toho). Předávej ji jen driveru, který ji potřebuje.

- [ ] **Step 8: Spustit testy**

```bash
php artisan test tests/Feature/Modules/Packeta tests/Feature/Modules/Orders tests/Feature/Modules/Checkout --compact
```

- [ ] **Step 9: Commit**

```bash
./vendor/bin/pint --dirty
git add -A && git commit -m "feat(packeta): deliver to the shopper's address through partner carriers"
```

---

### Task 5: Pokladna, nastavení dopravní metody a E2E

**Files:**
- Modify: `resources/js/Pages/Modules/Shipping/ShippingMethod.vue` (nastavení metody)
- Modify: `Modules/Checkout/resources/views/checkout/shipping.blade.php` (krok dopravy)
- Test: `e2e/tests/checkout-no-js.spec.ts` nebo nový `e2e/tests/packeta-home-delivery.spec.ts`

**Interfaces:**
- Consumes: Tasky 1–4
- Produces: nic nového

- [ ] **Step 1: Napsat padající E2E test**

Nákup s doručením na adresu **bez JavaScriptu** (projekt `no-js`, soubor musí odpovídat `testMatch: /no-js/`, jinak poběží s JS a nedokáže, co má):

```ts
// založ dopravní metodu s providerem packeta_hd (artisanEval)
// projdi košík → doprava (zvol doručení na adresu) → údaje → odeslat
// očekávej děkovnou stránku a žádný krok s výběrem výdejního místa
```

Druhý test s JS: u výdejního místa jde přepnout na „jen boxy" a výpis se zúží.

- [ ] **Step 2: Spustit, ověřit pád**

- [ ] **Step 3: Nastavení dopravní metody**

Metoda s providerem `packeta_hd` potřebuje **id partnerského dopravce**. Nabídni ho jako select naplněný z feedu dopravců Zásilkovny, stahovaného **na vyžádání při otevření obrazovky a cachovaného** (dopravců je málo a mění se zřídka — denní cron je na to zbytečný). Když feed selže, degraduj na textové pole s id a řekni to na obrazovce; nájemce, který zná id, tím nesmí být zablokovaný.

- [ ] **Step 4: Krok dopravy v pokladně**

Metoda, jejíž driver vrací `requiresPickupPoint() === false`, nezobrazí výběr místa a rovnou pokračuje. Tahle větev už v šabloně existuje (ptá se obecně přes registry) — ověř, že skutečně funguje, ne že jen vypadá, že by měla.

- [ ] **Step 5: Sestavit a spustit**

```bash
npm run build
npx playwright test --config=e2e/playwright.config.ts e2e/tests/<tvůj soubor>.spec.ts
```

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "feat(checkout): offer delivery to the shopper's address"
```

---

### Task 6: Uzavření

- [ ] **Step 1: Spustit sady**

```bash
php artisan test tests/Feature/Modules/Packeta --compact
php artisan test tests/Feature/Modules/Checkout --compact
php artisan test tests/Feature/Modules/Orders --compact
npm run e2e
```

Ve foregroundu, po adresářích.

- [ ] **Step 2: Zapsat as-is**

`docs/as-is/2026-08-11-packeta-home-delivery.md` — mapa změn, plnění spec, testy, **povinná sekce Odchylky od specifikace**, technický dluh, pre-deploy (migrace `pickup_points.type`, `npm run build`).

Aktualizuj `docs/future/zasilkovna-dalsi-dopravci.md`: tři rozpojená místa jsou hotová, zbytek platí dál.

- [ ] **Step 3: Rozhodnutí do CLAUDE.md**

Ve stylu okolních zápisů: proč dva drivery místo jednoho s přepínačem, proč `provider` na top-level snímku a čtení z obou míst místo migrace, proč je objednání u kurýra součástí podání a ne tisku.

- [ ] **Step 4: Uzavřít vlnu**

Spusť `/finish-wave`.
