# Vlna 2.5 — Zásilkovna (Packeta) — design

Datum: 2026-07-27 · Fáze 2 · Navazuje na: modul `shipping` (`shipping_methods`, `EloquentShippingOptions`, matice doprava×platba), `checkout` (`CheckoutController`, `CartPricer`, `carts`), `orders` (`orders.shipping_snapshot`, admin detail), `payments` (precedent registry/driver, vlna 1.4), `docs` (precedent `DocumentBook::forOrder()`, vlna 1.5).

**Status:** approved

## Cíl

Nájemce prodává s dopravou na **výdejní místo Zásilkovny** a celý životní cyklus zásilky odbaví z našeho adminu: zákazník si místo vybere v pokladně, nájemce objednávku **podá do Zásilkovny přes API**, stáhne **štítek** a zákazník dostane **sledovací odkaz**. Bez toho nájemce přepisuje adresy ručně do klienta Zásilkovny.

Výběr místa **musí fungovat s vypnutým JavaScriptem** (`.claude/rules/storefront-rendering.md`, spec §16.3 AK „celý checkout funkční bez JS"). Oficiální widget je nadstavba nad plnohodnotnou server-rendered cestou, ne jediná cesta.

Přidání dalšího dopravce (PPL, DPD, Balíkovna) pak nesmí vyžadovat zásah do checkoutu — AK spec §16.5.

## Mimo rozsah (→ `docs/future/`)

- Adresné doručení kurýrem (Packeta Home Delivery) — jen výdejní místa
- Automatický polling stavu zásilky (`packetStatus`) — sledovací odkaz stačí
- Další dopravci (PPL, DPD, Balíkovna) — driver je pro ně připraven
- Zpáteční zásilky a reklamace
- Vlastní mapa výdejních míst bez widgetu
- Hmotnost per varianta (odloženo už z vlny 2.4; varianty dědí hmotnost produktu)

## Role

| Role | Co smí |
|------|--------|
| `TENANT_ADMIN` s `packeta.manage` | credentials (`apiPassword`, `apiKey`, `eshop`), nastavení metody |
| `TENANT_ADMIN` / `TENANT_STAFF` s `packeta.ship` | podání zásilky, štítky, zrušení zásilky, expediční fronta |
| `SUPERADMIN` | **nic navíc** — zásilky podává nájemce svým účtem, platforma do nich nevidí |
| `CUSTOMER` | vidí výdejní místo a sledovací odkaz u **vlastní** objednávky |
| anonym / storefront | stránka výběru místa (`noindex`), čte jen katalog |

Dvě práva místo jednoho: skladník má podávat zásilky, ale nemá vidět API heslo. `TENANT_STAFF` je post-MVP, právo tam čeká.

Write-freeze na `suspended`/`past_due` platí přes `CheckTenantStatus` beze změny.

## Rozhodnutí z brainstormingu (závazná)

| Otázka | Rozhodnutí |
|--------|-----------|
| Rozsah | **Výběr místa + podání přes API + štítek + tracking**, ne jen uložení místa |
| Výběr místa | **Lokální katalog míst** + server-rendered výběr; widget jako nadstavba |
| Účet u Zásilkovny | **Každý nájemce svůj** — my nejsme odesílatel, nefakturujeme přepravné |
| Spouštěč podání | **Ruční tlačítko + hromadné podání**, žádný automat na změnu stavu |
| Kam patří kód | **Nový modul `packeta`** + kernel kontrakt `Carrier` s registry |
| Protokol | **REST/XML přes `Http` fasádu**, ne SOAP (žádná `ext-soap`) |
| Polling stavu zásilky | **NE** → `docs/future/` |

## Architektura

Nový modul `Modules/Packeta` (klíč `packeta`), vedle `shipping`. **Bez manifestového `requires`** — stejně jako `checkout` (rozhodnutí 2026-07-21): závislost se ptá za běhu přes `ShopModules`. Deklarovaná závislost by ze `shipping` udělala nevypnutelný modul.

Přesná kopie vzoru `payments`/`shipping` z vlny 1.4: `shipping` drží konfiguraci metod, samostatný modul drží driver a cizí API.

### Kernel kontrakty (`app/Core/Shipping/`)

| Kontrakt | Odpovědnost | Null binding |
|----------|-------------|--------------|
| `CarrierRegistry` | `for(string $provider): ?Carrier` | `NullCarrierRegistry` → vždy `null` |
| `Carrier` | `key()`, `requiresPickupPoint()`, `submit(OrderView): ShipmentResult`, `labels(array $shipmentIds, string $format): string` (id **našich** zásilek, driver si dohledá `packet_id`), `cancel(string $packetId)`, `trackingUrl(string $barcode)` | — |
| `PickupPointCatalog` | `search(string $query, int $limit): Collection`, `find(string $carrier, string $code): ?PickupPoint` | `NullPickupPointCatalog` → prázdno / `null` |
| `ShipmentBook` | `forOrder(int $orderId): ?ShipmentView` — read-only, pro detail objednávky | `NullShipmentBook` → `null` |
| `CarrierError` | jádrová výjimka (precedent `GatewayError`, 1.4) | — |

`ShipmentResult` a `ShipmentView` jsou read-only shapes v jádře — modul `orders` nikdy nesahá na model `Shipment` (stejný vzor jako `CatalogProduct`, `OrderView`, `CartShape`).

### Rozšíření existujícího kontraktu

`ShippingOption` dostane **`provider(): string`**. Checkout dnes vidí jen id/název/cenu a nemá jak poznat, že metoda potřebuje výdejní místo. Zasáhne dvě implementace (`ShippingMethod`, null shape), žádný callsite mimo ně.

`EloquentShippingOptions::available()` navíc **vyfiltruje metody, jejichž dopravce neběží** — `provider` mimo vestavěné (`pickup`, `flat`) se nabídne jen tehdy, když `CarrierRegistry::for($provider)` vrátí driver. Vypnutý modul `packeta` tak nesmí nechat v pokladně metodu, kterou nikdo neodbaví; nájemcova konfigurace v `shipping_methods` přitom zůstane nedotčená a po zapnutí modulu se metoda vrátí.

### Dělba práce mezi moduly

- **`shipping`** — beze změny odpovědnosti: řádky metod, ceny, `free_from`, `max_weight_g`, matice doprava×platba. Přibude hodnota `packeta` v enum `provider` a šifrování `settings`.
- **`packeta`** — HTTP klient, `PacketaCarrier` driver, sync katalogu míst, tabulka `shipments`, expediční fronta, štítky.
- **`checkout`** — ptá se `CarrierRegistry`, jestli vybraná metoda chce místo, a nepustí objednávku bez něj.
- **`orders`** — vykreslí read-only blok Doprava z propu, který dodá `ShipmentBook::forOrder()`. Přesně jak `DocumentBook::forOrder()` dodává doklady do téhož detailu (vlna 1.5).

### Šifrování `shipping_methods.settings`

Rozhodnutí 2026-07-21 („dopravní nastavení nejsou tajná, výdejní adresa se tiskne na storefrontu") **přestává platit**, jakmile do `settings` vstoupí `apiPassword`. Cast se mění na `encrypted:array`, admin maskuje a používá keep-on-update podle vzoru `payment_methods`. Migrace jednorázově re-encryptne existující řádky.

## Datový model

### `pickup_points` — netenantová sdílená tabulka

Katalog je pro všechny nájemce identický; jeden sync pro celou platformu. Netenantová jako `plans`/`plan_prices`/`platform_invoices` → **allowlist v `SchemaConventionTest`**.

| Sloupec | Typ | Poznámka |
|---------|-----|----------|
| `id` | PK | |
| `carrier` | string(20) | `packeta`; druhý feed nepotřebuje migraci |
| `code` | string(40) | Packeta `pointId` |
| `name` | string | |
| `street`, `city`, `zip` | string | |
| `country` | char(2) | `CZ` |
| `search_text` | string, index | normalizovaný bez diakritiky pro `LIKE` |
| `opening_hours` | json nullable | |
| `latitude`, `longitude` | decimal nullable | pro widget |
| `is_active` | bool | místo zmizelé z feedu se **deaktivuje, nemaže** |
| `synced_at` | timestamp | |

Unique `(carrier, code)`. Index `(carrier, country, zip)`.

Vyhledávání přes normalizovaný `search_text` + `LIKE` — precedent `products.search_text` (rozhodnutí 2026-07-20: fulltext neumí české skloňování ani diakritiku a nejede na SQLite v testech). Katalog ČR má řádově tisíce řádků, takže absence indexu na `LIKE '%…%'` je zde bez dopadu.

### `carts.pickup_point_code` — nový nullable sloupec

Kód, ne FK: katalog je netenantový a řádky se syncem mění; Packeta kód je stabilní identifikátor. Spec §16.3 počítá s `carts.meta`, ta v projektu neexistuje.

`chooseShipping` kód **vynuluje**, když zákazník přepne na metodu, která místo nevyžaduje.

### `shipments` — tenant-scoped, modul `packeta`

| Sloupec | Typ | Poznámka |
|---------|-----|----------|
| `id` | PK | |
| `tenant_id` | FK | `BelongsToTenant` |
| `order_id` | unsigned bigint | **bez FK** — cizí modul, precedent `carts.shipping_method_id` |
| `carrier` | string(20) | |
| `packet_id` | string nullable | id od Packety |
| `barcode` | string nullable | sledovací číslo |
| `status` | enum | `pending`, `submitted`, `failed`, `cancelled` |
| `cod_amount` + `currency` | MoneyCast | kolik šlo na dobírku (audit) |
| `weight_grams` | unsigned int | |
| `error` | text nullable | poslední chyba |
| `submitted_at`, `label_printed_at` | timestamp nullable | |

**Unique `(tenant_id, order_id)`** — jedna zásilka na objednávku. Dvojklik ani souběžný požadavek nesmí vyrobit dvě zásilky a dvakrát naúčtovat přepravu; idempotence pre-check + `UniqueConstraintViolationException` catch (vzor `order_idem_unique`, `platform_invoices.stripe_invoice_id`).

**`tracking_url` sloupec nebude** — skládá ho driver z `barcode` (`https://tracking.packeta.com/cs/?id={barcode}`). Míň uloženého stavu, který může zestárnout.

### Snapshot v objednávce

`orders.shipping_snapshot` (JSON, existuje) dostane klíč `pickup_point` = `{code, name, street, city, zip}`. **Žádná migrace** a snapshot přežije deaktivaci místa v katalogu.

## Packeta API

Ověřeno proti [oficiálnímu PrestaShop modulu Zásilkovny](https://github.com/Zasilkovna/prestashop/blob/v2.1/packetery/packetery.api.php) a [docs.packeta.com](https://docs.packeta.com/docs/api-reference/api-methods).

| Co | Volání |
|----|--------|
| Feed výdejních míst | `GET https://www.zasilkovna.cz/api/v4/{apiKey}/branch.json` |
| Vytvoření zásilky | `createPacket(apiPassword, packetAttributes)` — jedna zásilka na request |
| Štítek | `packetLabelPdf(apiPassword, packetId, format, offset)` |
| Štítky hromadně | `packetsLabelsPdf(apiPassword, packetIds, format, offset)` |
| Zrušení | `cancelPacket(apiPassword, packetId)` |

Autentizace je **dvojí**: `apiPassword` pro API volání, `apiKey` pro widget a feed — jsou to různé hodnoty, obě zadá nájemce.

Povinné atributy: `number` (číslo objednávky), `name`, `surname`, `email`, `phone`, `addressId` (kód místa), `value`, `weight`, `eshop` (označení odesílatele z klientské sekce — bez něj `createPacket` selže), `cod` u dobírky.

**REST/XML přes `Http` fasádu, ne SOAP** — žádná `ext-soap`, precedent Comgate driver (rozhodnutí 2026-07-21: brána bez composer balíčku).

## Storefront — výběr místa

### Bez JS (primární, plnohodnotná cesta)

1. `/pokladna/doprava` — u metody Zásilkovna odkaz „Vybrat výdejní místo"
2. `GET /pokladna/vydejni-misto?q=Brno` — Blade stránka, hledání podle města/PSČ/názvu nad naší tabulkou, výsledky jako radio s adresou a otevírací dobou; `noindex`
3. POST uloží kód do košíku → redirect zpět na krok dopravy, kde je místo vypsané + „Změnit"

### S JS (nadstavba)

Vanilla ostrůvek v `resources/js/storefront.js` (**žádné Alpine** — projekt ho nemá, rozhodnutí 2026-07-26). Oficiální widget v6 se načítá **až na kliknutí „Vybrat na mapě"** — dokud zákazník mapu nechce, checkout nedělá žádný request na cizí doménu (výkon, cookies/ePrivacy, CSP). Widget vrátí kód, ostrůvek ho vloží do skrytého inputu a odešle **týž formulář** jako bez-JS cesta. Zpracování na serveru je jedno a totéž.

### Server-authoritative resoluce

Klient posílá **jen kód**. Název, ulici a adresu server vždy dotáhne z katalogu; **nikdy je nebere z POST dat**, i když je widget v odpovědi vrací — stejná politika jako `variant_id` ve vlně 2.4 a `image_path` ve 2.3. Neznámý nebo neaktivní kód = validační chyba „vyberte místo znovu".

### Gate na odeslání objednávky

Kontrolu „metoda vyžaduje místo a místo chybí" dělá **server v `place()`**, ne jen UI — jinak by POST na `/pokladna/udaje` prošel bez místa a vznikla nepodatelná objednávka. AK spec §16.5.

## Podání, štítky, tracking

**`ShipmentSubmitter`**: claim řádku ve stavu `pending` → **commit** → teprve pak HTTP volání → update na `submitted` + `packet_id` + `barcode`, nebo `failed` + `error`.

Cizí HTTP **nikdy uvnitř transakce** — tech dluh vlny 1.8 byl přesně tohle (PDF render ve webhook transakci). Následek: pád mezi commitem a odpovědí nechá `pending` řádek, takže **retry přebírá i `pending`**, nejen `failed`.

**Hromadné podání**: `createPacket` umí jednu zásilku na request → smyčka. Selhání jedné nezruší zbytek; výsledek je report „podáno 8 z 10, 2 chyby" s důvody. Štítky naopak hromadně jedním `packetsLabelsPdf`.

**Štítky**: PDF se streamuje do prohlížeče, **neukládá se na disk** — je jednorázový, `FileStorage` nemá co držet. Volba formátu v dialogu (`A7 on A4`, `A6 on A4`, `A7 105×148`); `label_printed_at` je jen razítko pro přehled.

**Dobírka**: atribut `cod` = celková částka objednávky, jen když je platba `cod`, jinak nula. Částka z `orders.total`, nikdy z klienta.

**Zrušení zásilky**: tlačítko volá `cancelPacket`. Bez něj nájemce platí za zásilku, kterou stornoval.

**Tracking**: odkaz v adminu, v zákazníkově detailu objednávky a v e-mailu „odesláno".

## Admin

### Sync katalogu

Command `packeta:sync-points` (`NotTenantAware` — tabulka je netenantová), denně ve scheduleru. Upsert v dávkách, místa chybějící ve feedu se deaktivují.

Klíč z `config('packeta.feed_api_key')`; **fallback** = `apiKey` prvního tenanta, který má Zásilkovnu nastavenou, aby katalog fungoval i bez platformního účtu u Packety. Běh bez jakéhokoli klíče skončí chybou, ne tichým no-opem.

### Obrazovky

- **`/admin/m/packeta/expedice`** — expediční fronta: objednávky se Zásilkovnou čekající na podání, checkboxy, „Podat vybrané", „Tisk štítků". Vlastní obrazovka v modulu `packeta`, **nulový zásah do seznamu objednávek** — a zároveň to je reálný denní workflow (zabal → podej dávku → tiskni).
- **Detail objednávky** (modul `orders`) — read-only blok Doprava: místo, stav zásilky, čárový kód, sledovací odkaz, tlačítka Podat / Štítek / Zrušit. Prop z `ShipmentBook::forOrder()`; když modul `packeta` neběží, prop je `null` a blok zmizí.
- **Nastavení metody** (existující admin dopravy v `shipping`, karta pro `provider=packeta`): `apiPassword` (šifrovaně, maskovaně, keep-on-update), `apiKey`, `eshop`, výchozí hmotnost zásilky.

### Tarif

Modul `packeta` patří do **base** tarifu. Doprava je základní funkce e-shopu; za paywall by patřila, jen kdyby stála nás.

## Chybové stavy

**Hlavní zásada: výpadek Zásilkovny nesmí zastavit nákup.** Checkout čte místa z naší tabulky, ne z jejich API, takže nedostupná Packeta nezablokuje objednávku, jen odloží podání.

| Situace | Chování |
|---------|---------|
| API nedostupné při podání | `shipments.status = failed` + text chyby, tlačítko „Zkusit znovu"; objednávka beze změny |
| Neplatné credentials | konkrétní hláška v adminu (ne 500), zásilka `failed` |
| Místo deaktivované mezi výběrem a odesláním | validační chyba v `place()` — objednávka bez platného místa nevznikne |
| Sync selže nebo vrátí podezřele málo míst | **katalog se nepřepíše**, běží dál starý; jinak by jedna špatná odpověď vymazala výdejní místa všem nájemcům |
| Widget zablokovaný (adblock, CSP, offline) | naše stránka výběru funguje dál — je to primární cesta, ne fallback |
| Storno objednávky, která už má podanou zásilku | storno proběhne a admin **upozorní**, že zásilka je podaná, s tlačítkem „Zrušit zásilku". Automatické `cancelPacket` uvnitř storna ne — cizí HTTP volání by mohlo shodit storno, které musí projít vždy |

## Akceptační kritéria

- [ ] Zákazník s **vypnutým JS** projde nákup se Zásilkovnou od košíku po děkovnou stránku včetně výběru výdejního místa
- [ ] POST s podvrženým názvem nebo adresou místa uloží hodnoty **z katalogu**, ne z requestu
- [ ] Objednávka s metodou vyžadující místo **nevznikne** bez platného aktivního místa
- [ ] Dvojí podání téže objednávky vytvoří **jednu** zásilku a provede **jedno** API volání
- [ ] Hromadné podání 10 objednávek, kde 2 selžou, podá zbylých 8 a nahlásí 2 chyby s důvody
- [ ] Štítek se stáhne jako PDF pro jednu i pro dávku zásilek
- [ ] Dobírková objednávka pošle `cod` = `orders.total`; nedobírková nulu
- [ ] Zákazník vidí u své objednávky výdejní místo a sledovací odkaz; **cizí objednávku ne**
- [ ] Zásilky tenanta A jsou pro tenanta B neviditelné; podání cizí objednávky nelze
- [ ] `packeta.ship` bez `packeta.manage` **nevidí API heslo**
- [ ] Sync deaktivuje zmizelá místa a **neaplikuje prázdný feed**
- [ ] Vypnutý modul `packeta`: blok Doprava v detailu objednávky zmizí, checkout nenabídne metodu, nic nespadne
- [ ] `SchemaConventionTest` prochází s `pickup_points` na allowlistu netenantových tabulek
- [ ] Výpadek Packeta API neblokuje dokončení objednávky

## Rozsah implementace

Šest etap:

1. Kernel kontrakty + registry + `provider` enum + šifrování `shipping_methods.settings`
2. Katalog míst (`pickup_points`) + sync command
3. Checkout — výběr místa bez JS + gate na `place()`
4. JS ostrůvek s widgetem
5. Podání + štítky + tracking + zrušení
6. Expediční fronta + blok v detailu objednávky

Velikostně mezi vlnou 2.3 a 2.4.

## Pre-deploy

- [ ] Platformní `PACKETA_FEED_API_KEY` (jinak sync jede na klíči prvního nakonfigurovaného tenanta)
- [ ] Cron `schedule:run` musí běžet (denní `packeta:sync-points`) — už podmínka z vlny 2.1
- [ ] Reálná Packeta volání ověřit s testovacím účtem — v testech je `Http::fake`
- [ ] Ověřit, že widget v6 na cizí doméně nevyžaduje souhlas s cookies dřív, než se načte (načítá se až na kliknutí, ale zkontrolovat)
