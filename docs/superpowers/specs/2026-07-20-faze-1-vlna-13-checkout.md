# Fáze 1, vlna 1.3 — Zákazníci, doprava, pokladna, objednávky

**Datum:** 2026-07-20
**Status:** draft
**Související plán:** `docs/superpowers/plans/2026-07-20-faze-1-vlna-13-checkout.md` (vznikne po schválení)

## Kontext

Po vlnách 1.1 a 1.2 stojí katalog a veřejný storefront, ale e-shop nedokáže přijmout objednávku. Tato vlna uzavírá MVP cíl „do 10 minut od registrace funkční e-shop, produkty, první objednávka".

Specifikace §16.3–16.6 popisuje pět modulů (`checkout`, `orders`, `shipping`, `payments`, `docs`). Do jedné vlny se nevejdou a dva z nich jsou blokované nerozhodnutými dodavateli. Vlna 1.3 proto bere jen tu část, kterou nic neblokuje — offline platby.

### Dekompozice zbytku

| Vlna | Obsah | Blokované |
|------|-------|-----------|
| **1.3 (tato)** | `customers`, `shipping`, `checkout`, `orders`, `MailService` — offline platby | ničím |
| 1.4 | `payments` — online brána, webhook, `/platba/navrat` | Comgate vs. GoPay |
| 1.5 | `docs` — faktury, PDF, číselné řady | 1.4 |

`MailService` se do 1.3 zatáhl, protože zákaznický účet bez resetu hesla není funkce, ale past — zákazník se zamkne a nájemce nemá jak mu pomoct.

## Cíle

- [ ] Zákazník projde nákup od katalogu po děkovnou stránku **bez zapnutého JavaScriptu**
- [ ] Nájemce si nastaví způsoby dopravy a platby včetně matice doprava × platba
- [ ] Zákazník si volitelně založí účet, resetuje heslo a vidí historii objednávek
- [ ] Nájemce v adminu objednávku vidí, edituje, mění její stavy, stornuje a zakládá ručně
- [ ] Jádro umí odeslat e-mail za tenanta (`MailService`)
- [ ] Souběžné objednávky poslední skladové položky neprodají tentýž kus dvakrát

## Mimo rozsah

- Online platební brána, webhooky, `/platba/navrat` (vlna 1.4)
- Faktury a doklady (vlna 1.5)
- Zásilkovna, výdejní místa, widget — providerské rozhraní se připraví, integrace ne
- Slevové kódy (premium modul `coupons`)
- Varianty produktu v košíku — katalog je zatím nemá
- Našeptávač adres, částečné dobropisy, tracking zásilek
- Marketingové e-maily, suppression list

## Požadavky

### Architektura — moduly a kontrakty

Čtyři samostatné moduly + rozšíření jádra. Rozhodnutí: věrnost pravidlu modularity z `CLAUDE.md`, aby se `payments` a `docs` v dalších vlnách pověsily na hotová rozhraní, ne na cizí modely.

| Modul | `core` | `requires` | `provides` |
|-------|--------|-----------|-----------|
| `customers` | ne | — | `customer-identity` |
| `shipping` | ne | — | `shipping-options`, `payment-options` |
| `checkout` | ne | `products` | `cart` |
| `orders` | ne | — | `order-book` |

Nové kontrakty v jádře, po vzoru `App\Core\Catalog\Contracts\ProductCatalog`:

```
app/Core/Checkout/Contracts/CartRepository.php
app/Core/Shipping/Contracts/ShippingOptions.php
app/Core/Shipping/Contracts/PaymentOptions.php
app/Core/Orders/Contracts/OrderPlacement.php
app/Core/Orders/Contracts/OrderBook.php
app/Core/Customers/Contracts/CustomerIdentity.php
app/Core/Mail/Contracts/MailService.php
```

`checkout` **nedeklaruje `requires` na `shipping`** — stejný důvod jako u modulu `storefront` (rozhodnutí 2026-07-20): deklarovaná závislost by ze `shipping` udělala nevypnutelný modul. Pokladna se ptá za běhu přes `ShopModules`; když `shipping` neběží, nabídne vestavěnou nouzovku „osobní odběr zdarma" a krok dopravy přeskočí.

### Datový model

Všechny tabulky nesou `tenant_id` a model používá `BelongsToTenant`. Peníze přes `MoneyCast`, sazby přes `TaxRates`.

```
customers            tenant_id, email, password, first_name, last_name, phone,
                     email_verified_at, remember_token   UNIQUE(tenant_id, email)
customer_addresses   customer_id, kind(billing|shipping), company, reg_no, vat_no,
                     street, city, zip, country, is_default

shipping_methods     provider(pickup|flat), name, description, price, tax_rate_id,
                     free_from, max_weight_g, position, is_active, settings JSON
payment_methods      provider(cod|bank_transfer), name, description, fee, tax_rate_id,
                     position, is_active, settings JSON
shipping_method_payment_method   pivot = matice doprava × platba

carts                token, customer_id?, shipping_method_id?, payment_method_id?,
                     meta JSON, expires_at, converted_at?   INDEX(tenant_id, token)
cart_items           cart_id, product_id, quantity, unit_price

orders               uuid, number, customer_id?, cart_id?, checkout_token, source,
                     email, phone, billing JSON, shipping JSON,
                     shipping_snapshot JSON, payment_snapshot JSON,
                     items_total, shipping_total, payment_fee, total,
                     vat_summary JSON, fulfillment_status, payment_status,
                     note, placed_at
                     UNIQUE(tenant_id, cart_id, checkout_token)
order_items          order_id, product_id?, name, sku, unit_price, tax_rate,
                     quantity, line_total
order_events         order_id, actor_type, actor_id, type, from, to, note, payload
```

Poznámky k modelu:

- `order_items` drží **snapshot** názvu, kódu, ceny a sazby. `product_id` je nullable, aby smazaný produkt objednávku nerozbil.
- `cart_items.unit_price` je cena viděná při vložení do košíku, **nikoliv autorita**. Autorita je vždy `ProductCatalog::price()` při zobrazení; rozdíl vyrobí banner „cena položky se změnila z X na Y" podle §16.3.
- `carts.expires_at` = 14 dní (§16.3). Převedený košík se nemaže, dostane `converted_at` — drží audit stopu.
- `orders.uuid` je veřejný identifikátor v URL děkovné stránky; `orders.number` je lidské číslo ze `SequenceService`.

### Storefront — tok pokladny

Blade SSR, `noindex`, vyřazeno z page cache **pravidlem routy** (ne cookie):

| URL | Obsah |
|-----|-------|
| `/kosik` | položky, ± množství, odstranit, mezisoučty, lišta „doprava zdarma — zbývá Y Kč", CTA |
| `/pokladna/doprava` | radio dopravy → platby filtrované maticí; změna dopravy = POST + redirect |
| `/pokladna/udaje` | e-mail, telefon, jméno, adresa, firma + IČO, jiná doručovací adresa, poznámka, souhlasy, rekapitulace s DPH rozpisem, tlačítko „Objednat s povinností platby" |
| `/dekujeme/{uuid}` | číslo objednávky, instrukce, QR platba u převodu |

Celý tok **musí projít bez JavaScriptu**. Povolené ostrůvky navíc: mini-košík v hlavičce (`GET /api/kosik/souhrn`, `Cache-Control: private, no-store`), „přidat do košíku" bez reloadu, ARES autofill podle IČO. Žádná cenová logika v JS.

Identita košíku: `carts.token` v host-only cookie, kryptograficky náhodný. Po přihlášení zákazníka se anonymní košík **připojí** k účtu — položky se sloučí, nepřepíšou.

### Odeslání objednávky

Jedna DB transakce, kroky v tomto pořadí:

1. Ověř idempotenci podle `checkout_token` z hidden pole. Existující `(tenant_id, cart_id, checkout_token)` = přeskoč rovnou na redirect na hotovou objednávku.
2. Přepočti košík ze zdroje pravdy (`ProductCatalog::price()`) a porovnej s `cart_items.unit_price`. Rozdíl → rollback, návrat na `/kosik` s bannerem.
3. Ověř sklad u všech položek. Nedostatek → rollback, návrat na `/kosik` s hláškou.
4. `SequenceService::next('orders')` → číslo objednávky.
5. Insert `orders` + `order_items` se všemi snapshoty.
6. `ProductCatalog::decrementStock()` na každou položku. `InsufficientStock` shodí celou transakci.
7. Zapiš `order_events` typu „vytvořeno".
8. Označ košík `converted_at`.

Po commitu, mimo transakci: zařaď job na potvrzovací e-mail, redirect na `/dekujeme/{uuid}`.

Krok 6 patří dovnitř transakce záměrně — sklad musí spadnout stejným rollbackem jako objednávka, jinak dva souběžné checkouty prodají poslední kus dvakrát.

### Modul `customers`

Čtvrtý guard vedle `web` a `platform`:

```php
'customer' => ['driver' => 'session', 'provider' => 'customers'],
```

Oddělený guard, oddělená tabulka, oddělená session. Přihlášený zákazník tenanta nesmí být nikdy tentýž session subjekt jako `TENANT_ADMIN`. `customers.email` je unikátní jen v rámci tenanta — jeden člověk může mít účet u pěti e-shopů platformy a jsou to pět různých identit.

Storefront routy, všechny `noindex`, Blade SSR: `/registrace`, `/prihlaseni`, `/odhlaseni`, `/zapomenute-heslo`, `/obnova-hesla/{token}`, `/ucet`, `/ucet/objednavky`, `/ucet/objednavky/{uuid}`, `/ucet/udaje`.

Breeze se nepoužije — je nadrátovaný na guard `web`.

Admin nájemce: seznam zákazníků, detail s historií objednávek, GDPR výmaz (anonymizace, potvrzovací dialog), export dat zákazníka v JSON.

### Jádro — `MailService`

`app/Core/Mail`, rozhraní `send(Mailable $mail, array $recipients, Tenant $tenant)`, implementace nad Laravel Mail.

- **Odesílatel per tenant**: jméno a reply-to z nastavení tenanta, obálková adresa naše — SPF/DKIM patří naší doméně, cizí obálka padá do spamu.
- Vždy přes frontu, job je tenant-aware.
- Log odeslaných zpráv per tenant → čerpání limitu `emails_month`, který dnes v `LimitsService` chybí a doplní se.
- Driver SMTP z konfigurace. Volba Postmark / Mailgun / SES je pak změna configu, ne kódu — tím tato vlna nečeká na rozhodnutí o poskytovateli.

Šablony v této vlně: ověření e-mailu, reset hesla, potvrzení objednávky (zákazníkovi i nájemci), změna stavu objednávky, storno.

### Modul `shipping`

Admin nastavení: seznam metod dopravy (zapnuto, název, cena, zdarma od, váhový limit, pozice), seznam plateb (dobírka, převod + QR), matice doprava × platba jako zaškrtávací tabulka.

Providery v této vlně: `pickup` (osobní odběr — adresa, otevírací hodiny) a `flat` (paušální cena). Rozhraní je připravené pro dopravce s API, ale žádný se neintegruje.

Řazení metod tlačítky ↑/↓, ne drag&drop — pravidlo z 2026-07-20 (WCAG 2.1.1).

### Modul `orders` — admin

Inertia stránky v `resources/js/Pages/Modules/Orders/` (odchylka z vlny 1.1 platí dál).

Dva **nezávislé** stavy, každý s vlastním vynuceným automatem:

```
fulfillment:  new → accepted → processing → shipped → delivered
              storno z libovolného ne-koncového stavu → cancelled
              přeskok vpřed povolen; zpět jen s admin override a povinnou poznámkou

payment:      unpaid → paid → refunded
              „označit zaplaceno" ručně, poznámka povinná
```

Automat vynucuje service třída, ne UI. Nepovolený přechod = výjimka, ne tichý zápis. Každý přechod zapíše `order_events` (kdo, kdy, z čeho na co, poznámka, systémový vs. ruční).

Obrazovky: seznam s filtry a badge stavů a rychlou změnou stavu; detail (hlavička, dva selecty, položky, adresy, historie, interní poznámky); editace položek a adres do stavu `shipped`; ruční založení objednávky (zdroj `manual`, bez online platby); storno dialog (důvod, vrátit sklad ano/ne, poslat e-mail ano/ne).

Editace položek přepočítá totály a **upraví sklad podle delty** — přidaný kus se ze skladu odepíše stejně jako nakoupený.

### Oprávnění

Nová práva z manifestů modulů (odvozují se přes `TenantPermissions`): `orders.view`, `orders.edit`, `orders.cancel`, `shipping.manage`, `customers.view`, `customers.erase`.

Storefront části (`/kosik`, pokladna, účet zákazníka) žádné z těchto práv nepoužívají — jsou veřejné, respektive za guardem `customer`.

## Akceptační kritéria

1. S vypnutým JavaScriptem lze projít od detailu produktu po děkovnou stránku a objednávka vznikne.
2. Dvojité odeslání formuláře na `/pokladna/udaje` vytvoří **jednu** objednávku.
3. Dva souběžné checkouty na poslední skladový kus: jeden uspěje, druhý dostane hlášku o vyprodání a žádná objednávka mu nevznikne.
4. Změna ceny produktu mezi vložením do košíku a odesláním zobrazí banner s původní i novou cenou a přepočte součet.
5. Podvržená cena nebo cena dopravy v POST datech se ignoruje — částky počítá výhradně server.
6. Tenant A nevidí košíky, objednávky ani zákazníky tenanta B. Uhodnutý `carts.token` z cizího tenanta nevrátí nic.
7. Přihlášený zákazník nemá přístup do `/admin`; `TENANT_ADMIN` není přihlášený zákazník na storefrontu.
8. Zakázaný přechod stavu (např. `delivered` → `new` bez override) vyhodí výjimku a nic nezapíše.
9. Storno s volbou „vrátit sklad" vrátí přesně odebrané množství.
10. Změna dopravy přefiltruje dostupné platby podle matice a přepočte celkovou cenu — serverem.
11. Reset hesla zákazníka doručí e-mail a token je jednorázový a časově omezený.
12. `order_items` přežije smazání produktu — objednávka se dál zobrazí s původními údaji.
13. Lighthouse accessibility ≥ 90 na `/kosik` a obou krocích pokladny.

## Bezpečnost

- CSRF na všech formulářích košíku a pokladny.
- `carts.token` kryptograficky náhodný, nikdy autoinkrement.
- `payment_methods.settings` šifrované, zobrazení maskované.
- Rate limit na `/prihlaseni`, `/registrace` a `/zapomenute-heslo`.
- `order_events.payload` nikdy neloguje heslo ani platební údaje.
- Účet zákazníka smí číst jen vlastní objednávky — kontrola vlastnictví, ne jen znalost UUID.

## Etapy

Po každé etapě zelený stav; vlna jde zastavit kdekoliv.

| # | Etapa | Výstup |
|---|-------|--------|
| 1 | `MailService` v jádře | fronta, per-tenant odesílatel, počítadlo `emails_month` |
| 2 | Modul `customers` | guard, registrace, login, reset hesla, `/ucet`, admin |
| 3 | Modul `shipping` | metody, matice, admin nastavení |
| 4 | Modul `checkout` | `/kosik`, dva kroky pokladny, `/dekujeme`, kontrakt `OrderPlacement` |
| 5 | Modul `orders` | model, automat, admin, e-maily stavů |

Etapa 5 závisí na 4, ale kontrakt `OrderPlacement` vzniká už v etapě 4, takže pořadí nevyžaduje zpětné úpravy.

## Testy

- Izolace tenantů u každé nové tabulky (povinná CI brána).
- Souběh na poslední skladové položce.
- Idempotence dvojitého odeslání.
- Průchod checkoutem přes HTTP formuláře, ne přes API — tím se testuje i varianta bez JS.
- Stavový automat: každý zakázaný přechod.
- Izolace guardů `customer` × `web` × `platform`.
- Cena vždy ze serveru — podvržený POST.

## Technické poznámky

- Route názvy modulů dodrží konvenci `admin.<modul>.*` a `storefront.<modul>.*`.
- Admin routy modulů jdou za `module:{key}` → `tenant.member` (rozhodnutí 2026-07-20), alias `auth` se nepoužívá.
- Blade views, routy, controllery a migrace zůstávají v modulech; Inertia stránky v `resources/js/Pages/Modules/<Modul>/`.
- Storno a GDPR výmaz mají potvrzovací dialog (pravidlo `CLAUDE.md`).
- QR platba u převodu: SPAYD formát, generováno serverem jako obrázek nebo inline SVG.

## Reference

- Produktová spec: §16.3 `checkout`, §16.4 `orders`, §16.5 `shipping` + `payments`, §15.1 kernel služby
- Pravidlo renderingu: `.claude/rules/storefront-rendering.md`
- Předchozí as-is: `docs/as-is/2026-07-20-storefront-katalog.md`
- As-is po dokončení: `docs/as-is/<datum dokončení>-checkout.md`
