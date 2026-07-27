# As-is: Zásilkovna (vlna 2.5)

Datum: 2026-07-27 · Verze: **0.23.0** · Větev: `feature/vlna-2.5-zasilkovna` · 1359 testů (4653 assertions)

Spec: [`docs/superpowers/specs/2026-07-27-vlna-25-zasilkovna-design.md`](../superpowers/specs/2026-07-27-vlna-25-zasilkovna-design.md)
Plán: [`docs/superpowers/plans/2026-07-27-vlna-25-zasilkovna.md`](../superpowers/plans/2026-07-27-vlna-25-zasilkovna.md)

## Co vlna přinesla

Nájemce prodává s dopravou na výdejní místo Zásilkovny a celý životní cyklus zásilky odbaví z našeho adminu: zákazník si místo vybere v pokladně (i s vypnutým JavaScriptem), nájemce objednávku podá do Zásilkovny přes API, stáhne štítek a zákazník dostane sledovací odkaz.

## Mapa změn

### Jádro — `app/Core/Shipping/`

| Soubor | Role |
|--------|------|
| `Contracts/Carrier.php` | driver dopravce: `key()`, `requiresPickupPoint()`, `submit()`, `labels()`, `cancel()`, `trackingUrl()` |
| `Contracts/CarrierRegistry.php` | `for(provider): ?Carrier`, `available()` |
| `Contracts/PickupPointCatalog.php` + `PickupPoint.php` | čtení sdíleného katalogu míst |
| `Contracts/ShipmentBook.php` + `ShipmentView.php` | read side zásilek pro cizí moduly |
| `ShipmentResult.php` | návrat z `submit()` (packetId + barcode) |
| `Null*` implementace | guest-safe chování bez nasazeného modulu |
| `Exceptions/CarrierError.php` | jádrová výjimka dopravce |
| `Contracts/ShippingOption.php` | **rozšířen** o `provider()` a `defaultWeightGrams()` |

`app/Core/Orders/`: `OrderView::orderShippingSnapshot()`, `OrderBook::forShippingProvider()`, `Exceptions/PickupPointMissing.php`, `Exceptions/ShippingMethodUnavailable.php`.
`app/Core/Checkout/`: `CartShape::cartPickupPointCode()`, `CartRepository::choosePickupPoint()`.

### Nový modul `Modules/Packeta/`

Manifest bez `requires` (runtime gate přes `ShopModules`), právo `packeta.ship`, nav „Expedice", tarif base (migrace `attach_packeta_to_plans`).

- `Services/PacketaClient.php` — REST/XML přes `Http` fasádu, **žádný SOAP**
- `Services/PacketaCarrier.php` — mapování objednávky na packetAttributes, konverze haléře→koruny
- `Services/EloquentCarrierRegistry.php` — resolve driveru per tenant
- `Services/EloquentPickupPointCatalog.php`, `Services/PickupPointSync.php`, `Console/SyncPickupPointsCommand.php`
- `Services/EloquentShipmentBook.php`, `Services/ShipmentSubmitter.php`
- `Http/Controllers/{DispatchQueueController,ShipmentAdminController}.php`
- `Models/{PickupPoint,Shipment}.php`

### Zásahy do existujících modulů

`shipping` — hodnota `packeta` v enum `provider`, **`settings` nově `encrypted:array`**, credentials v adminu, filtr metod bez běžícího driveru.
`checkout` — `carts.pickup_point_code`, `PickupPointController` + Blade stránka výběru, blok vybraného místa v kroku dopravy.
`orders` — gate na chybějící místo v `place()`, snapshot místa, blok Doprava v admin detailu.
`customers` — výdejní místo a sledovací odkaz v zákaznickém detailu objednávky.
`resources/js/storefront.js` — ostrůvek widgetu (vanilla, načítaný až na kliknutí).

### Migrace (7, v tomto pořadí)

`090000` enum provider → `090100` šifrování settings → `100000` pickup_points → `110000` carts.pickup_point_code → `120000` shipments → `120100` zařazení do tarifů.

## Plnění spec

| Akceptační kritérium | Stav |
|---|---|
| Nákup se Zásilkovnou bez JS od košíku po děkovnou stránku | **splněno** — `PacketaEndToEndTest` |
| Podvržený název/adresa místa se neuloží, čte se katalog | **splněno** |
| Objednávka nevznikne bez platného aktivního místa | **splněno** |
| Dvojí podání = jedna zásilka, jedno volání API | **splněno** |
| Hromadné podání hlásí částečné selhání | **splněno** |
| Štítek jako PDF pro jednu i dávku | **splněno** |
| Dobírka posílá `orders.total`, nedobírková nulu | **splněno** |
| Zákazník vidí místo a tracking, cizí objednávku ne | **splněno** |
| Tenant izolace zásilek, cizí objednávku nelze podat | **splněno** |
| Sync deaktivuje zmizelá místa, neaplikuje prázdný feed | **splněno** |
| Vypnutý modul: blok zmizí, metoda se nenabídne | **splněno** |
| `pickup_points` na allowlistu netenantových tabulek | **splněno** |
| Výpadek Packeta API neblokuje objednávku | **splněno** |
| `packeta.ship` bez `packeta.manage` nevidí API heslo | **odchylka — viz níže** |
| Další dopravce bez zásahu do checkoutu (§16.5) | **nesplněno — viz níže** |

## Odchylky od specifikace

**1. `packeta.manage` neexistuje.** Spec počítal se dvěma právy (`packeta.manage` na credentials, `packeta.ship` na expedici). Credentials Zásilkovny ale sedí na obrazovce dopravy, která je za `shipping.manage`, takže `packeta.manage` nikde nic nehlídalo — udělení toho práva nedávalo uživateli nic. Bylo odstraněno z manifestu, aby nevznikla klamná autorizační plocha, které by někdo uvěřil, až přijde `TENANT_STAFF`. Věcně AK platí dál: API heslo se nikdy nevrací do adminu, jen boolean „je uloženo".

**2. AK §16.5 „přidání dalšího dopravce bez zásahu do checkoutu" není splněné.** Checkout se ptá „potřebuje tahle metoda výdejní místo?" přes `CarrierRegistry` (`CheckoutController`, `OrderPlacer`, Blade prop), ale `PickupPointController::store()` a `widgetApiKey()` drží `PROVIDER_PACKETA` natvrdo, a `PickupPointCatalog::search()` nemá parametr dopravce vůbec. Druhý dopravce s výdejními místy (Balíkovna) tedy dnes checkout rozšířit nejde bez zásahu. Nedotaženo vědomě: převedení obou míst přes `CarrierRegistry` (který vyžaduje `api_password` i `eshop`) by rozbilo šest záměrných testů picker/widgetu, které běží bez plné konfigurace dopravce. Zapsáno do `docs/future/`.

**3. `shipping_methods.settings` je nově šifrované** — revize rozhodnutí z 2026-07-21 („dopravní nastavení nejsou tajná"). Platilo, dokud sloupec nesl jen výdejní adresu a otevírací dobu; `apiPassword` je credential. Migrace jednorázově re-encryptuje existující řádky.

**4. E-mail „objednávka odeslána" v projektu neexistuje**, takže zákazník se o podání zásilky aktivně nedozví — musí se podívat do svého účtu. Projekt má jen generický `order-state-changed` posílaný ručně adminem. Vytvářet nový transakční e-mail bylo mimo rozsah vlny.

**5. `provider` a `weight_grams` jsou v `shipping_snapshot` vnořené pod `pickup_point`.** Pro Zásilkovnu neškodné (vždy vyžaduje výdejní místo), ale dopravce bez výdejního místa by je neměl kam uložit a `ShipmentSubmitter` by na něm vždy skončil na `notConfigured`. Past pro další vlnu, zapsáno do `docs/future/`.

**6. Task 3b nebyl v plánu.** Plán dodal backend credentials Zásilkovny, ale žádný task nedodával formulář v adminu — nájemce by je neměl kde zadat. Doplněno jako task navíc.

## Testy

1359 testů zelených (4653 assertions), plná sada spuštěna ve foregroundu.

Nové testovací třídy: `CarrierContractsTest`, `PacketaProviderTest`, `ShippingCredentialsTest`, `PickupPointCatalogTest`, `PickupPointSyncTest`, `SyncPickupPointsCommandTest`, `PickupPointSelectionTest`, `PickupPointCheckoutTest`, `PickupPointOrderTest`, `PickupPointWidgetTest`, `PacketaCarrierTest`, `ShipmentSubmitterTest`, `ShipmentAdminTest`, `ShipmentTrackingTest`, `DispatchQueueTest`, `OrderShipmentBlockTest`, `PacketaEndToEndTest`.

`tests/Support/FakeCarrierRegistry.php` — test double pro cesty, které nepotřebují reálný driver.

## Technický dluh

**Výkon**
- `OrderBook::forShippingProvider()` načítá všechny nezrušené objednávky se Zásilkovnou bez časového okna a bez stránkování; JSON cesta nemá index. Expediční fronta je denně používaná obrazovka — poroste bez omezení.
- `PickupPointSync` dělá ~4000 SELECT + UPDATE na běh (kandidát na `upsert()`), a neběží v jedné transakci.
- Index nad `pickup_points.search_text` se při `LIKE '%…%'` nepoužije.
- `EloquentShippingOptions::available()` volá registry i pro obchod, který má jen `pickup`/`flat`.

**Architektura**
- `OrderPlacer` compile-time importuje `Modules\Shipping\Models\ShippingMethod` kvůli dvěma konstantám; patří do `app/Core/Shipping/`.
- `PickupPointController::widgetApiKey()` sahá z checkoutu přímo na model modulu `shipping`.

**Testové mezery**
- Idempotence šifrovací migrace ověřena jen ručně (`RefreshDatabase` ji strukturálně nepokryje).
- Souběžné scénáře `wasAlreadyHandedOver()` — jednovláknový PHPUnit je nevyvolá.
- Chybí regresní test XML escapingu odchozích hodnot (kód ověřen jako bezpečný).
- `shipment.resubmittable` prop není přímo asertován.

**Provoz**
- `down()` šifrovací migrace selže na `text→json` ALTER, pokud zůstane nedešifrovatelný řádek (jiný `APP_KEY`).
- Formát štítku je validován jen jako `string|max:20`, ne proti čtyřem hodnotám, které UI nabízí.

## Pre-deploy checklist

- [ ] `PACKETA_FEED_API_KEY` v produkčním `.env` (bez něj sync jede na klíči prvního nakonfigurovaného tenanta)
- [ ] Cron `schedule:run` musí běžet — denní `packeta:sync-points`
- [ ] Ověřit reálná volání Packeta API s testovacím účtem (v testech `Http::fake`)
- [ ] Ověřit, že widget v6 na cizí doméně nevyžaduje souhlas s cookies dřív, než se načte (načítá se až na kliknutí)
- [ ] Zkontrolovat vztah `PACKETA_TIMEOUT` a `submit_stale_after_minutes` — guard vyžaduje práh aspoň 2× timeout a selže hlasitě, pokud ne
