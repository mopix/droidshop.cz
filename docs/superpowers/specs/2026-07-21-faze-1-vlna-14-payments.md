# Fáze 1, vlna 1.4 — Online platební brána (`payments`)

**Datum:** 2026-07-21
**Status:** done
**Související plán:** `docs/superpowers/plans/2026-07-21-faze-1-vlna-14-payments.md` (vznikne po schválení)

## Kontext

Vlna 1.3 uzavřela MVP objednávkový tok, ale jen pro offline platby (dobírka, bankovní převod + SPAYD QR). Zákazník, který chce zaplatit kartou online, dnes projde pokladnou, objednávka vznikne jako `unpaid` a tím to končí — žádné přesměrování na bránu, žádné potvrzení platby. Tato vlna doplní online platební bránu podle spec §16.5–16.6.

Návrh vlny 1.3 s bránou vědomě počítal: `PlacedOrder::paymentProvider()`, `PaymentOption::provider()`, konstanta `Order::PAYMENT_FAILED` a šifrované `payment_methods.settings` už v kódu stojí jako připravené háčky. Tato vlna je oživuje, nepřepisuje.

### Rozhodnutí dodavatele a architektura bran

První brána = **Comgate** (rozhodnutí 2026-07-21). Důvody: česká brána, jednodušší HTTP API než GoPay, nativní podpora karet i českých převodů, redirect + server-to-server notifikace.

**Architektura = registry/driver od začátku** (rozhodnutí 2026-07-21). Ne jeden binding jedné brány, ale registr `PaymentGatewayRegistry` klíčovaný providerem — každá brána je driver implementující `PaymentGateway`. Nájemce si zapne libovolnou podmnožinu a **víc bran koexistuje na jednom e-shopu** (metoda s `provider='comgate'` vedle `provider='gopay'`). `payment_methods.provider` už tento klíč nese (dnes `'cod'`/`'bank_transfer'`), registr se na něj jen napojí.

Vlna 1.4 dodá **jediný driver: Comgate**. GoPay a Stripe jsou explicitně **design-for, ne teď** — přidají se v pozdějších vlnách jako nové drivery registru, bez zásahu do jádra ani checkoutu. Poznámka ke Stripe: karty international bez českého převodu/dobírky, vlastní event/webhook model — jako driver sedne, ale ne 1:1 flow Comgate. `stripe/stripe-php` v repu je zbytek šablony určený pro **billing platformy** (předplatné nájemců), nemíchat se storefront platbami tenantů.

### Umístění

Nový **modul `payments`** (ne rozšíření `checkout`/`shipping`). Důvod: věrnost pravidlu modularity — kill switch znamená, že e-shop bez online brány dál funguje na offline platbách. Modul je vypnutelný; jeho vypnutí nesmí shodit pokladnu.

## Cíle

- [ ] Zákazník zaplatí kartou: po odeslání objednávky je přesměrován na bránu, po zaplacení se vrátí na děkovnou stránku s potvrzenou platbou
- [ ] Objednávka změní `payment_status` na `paid` **až po ověření u brány**, nikdy na základě samotného návratu prohlížeče
- [ ] Neúspěšná / zrušená / vypršená platba nechá objednávku ve stavu, ze kterého jde platbu zopakovat
- [ ] Nájemce si v adminu zapne bránu a zadá své Comgate credentials (maskované, měněné opětovným zadáním)
- [ ] Duplicitní notifikace brány (webhook + return současně) objednávku nerozbije ani nezdvojí platbu
- [ ] Vypnutý modul `payments` nechá pokladnu funkční na offline platbách

## Mimo rozsah

- Druhá brána (GoPay a další) — architektura připravena, integrace ne
- Faktury a doklady po zaplacení (vlna 1.5)
- Vracení peněz (refundy) přes API brány — `payment_status` `refunded` se zatím nastavuje ručně v adminu jako dnes
- Opakované platby / předplatné zákazníků tenanta (jiná doména než předplatné nájemců platformy)
- Uložené karty, one-click platby
- Apple Pay / Google Pay tlačítka
- Platby na platformě (předplatné nájemců) — samostatný modul `billing`, jiná vlna
- Částečné platby, zálohy, splátky

## Požadavky

### Architektura — modul a kontrakty

| Modul | `core` | `requires` | `provides` |
|-------|--------|-----------|-----------|
| `payments` | ne | — | `payment-gateway` |

**Bez `requires` na `orders`/`checkout`** — stejný precedent jako `checkout` v 1.3: závislost by z těch modulů udělala nevypnutelné. Runtime gate přes `ShopModules`; když `payments` neběží, checkout vidí null binding a online provider se v pokladně vůbec nenabídne.

Nové kontrakty v jádře (`app/Core/Payments/`):

```
app/Core/Payments/Contracts/PaymentGateway.php    — jeden driver: initiate(PlacedOrder|order-ref): PaymentInitiation; verify(reference): PaymentResult; provider(): string
app/Core/Payments/Contracts/PaymentGatewayRegistry.php — for(provider): ?PaymentGateway; available(): list<string>  (které brány běží a jsou nakonfigurované)
app/Core/Payments/Contracts/PaymentInitiation.php — redirectUrl(): string  (kam poslat prohlížeč)
app/Core/Payments/PaymentResult.php               — value object: status (paid|failed|pending), gatewayRef, částka
app/Core/Payments/NullPaymentGatewayRegistry.php  — guest-safe: modul vypnutý → for() vrací null, available() prázdné → online platba nedostupná
```

Jádrový binding `PaymentGatewayRegistry` → `NullPaymentGatewayRegistry`, modul ho přebije registrem s registrovanými drivery (vlna 1.4: jen `ComgateGateway`). Stejný vzor null bindingu jako `OrderPlacement`, `ShippingOptions`. Checkout i webhook sahají **výhradně přes registr** (`registry->for($providerKey)`), nikdy na konkrétní driver — přidání GoPay/Stripe je pak jen registrace dalšího driveru.

### Backend — tok platby

1. **Pokladna, výběr platby.** Modul `shipping` už vystavuje platební metody. Comgate = platební metoda s `provider = 'comgate'`. Nabídne se jen když modul `payments` běží a nájemce bránu nakonfiguroval.
2. **`place()`.** Objednávka vzniká jako dnes, `payment_status = unpaid`, sklad odepsán v téže transakci. `PlacedOrder::paymentProvider()` vrátí `'comgate'`.
3. **Přesměrování.** `CheckoutController::place()`: když `paymentProvider()` non-null a `registry->for($provider)` vrátí driver, zavolá `initiate($order)` a přesměruje na `redirectUrl()` **místo** na `/dekujeme`. Comgate vrátí redirect na svou platební stránku.
4. **Návrat zákazníka — `GET /platba/navrat`.** Prohlížeč se vrátí z brány. Routa **needůvěřuje query parametrům**: dohledá objednávku (tenant-scoped, leak-guarded jako `ThankYouController`), zvolí driver podle providera objednávky (`registry->for(...)`), zavolá `verify()` (server-to-server dotaz na skutečný stav u brány) a podle výsledku:
   - `paid` → `OrderWorkflow::transitionPayment(paid)` (idempotentně) → redirect `/dekujeme/{uuid}`
   - `failed`/`cancelled` → objednávka zůstane `unpaid`/`failed`, redirect na stránku „platba se nezdařila, zkuste znovu" s možností opakování
   - `pending` → informační stránka „platba se zpracovává"
5. **Webhook — `POST /platba/notifikace`.** Server-to-server notifikace Comgate. Mimo `web`/CSRF (nemá session). Ověří pravost (viz Bezpečnost), pak **znovu dotáže stav u brány** (`verify()`, ne payloadu se nevěří) a provede stejný `transitionPayment` jako návrat. Idempotentní: druhá notifikace (nebo souběh s návratem) je no-op, ne chyba.

### Backend — stavový automat

`OrderWorkflow::PAYMENT_TRANSITIONS` rozšířit:

```
unpaid  → { paid, failed }
failed  → { unpaid }          // retry: zákazník zkusí zaplatit znovu
paid    → { refunded }        // beze změny
refunded → { }
```

`failed → unpaid` umožní opakování platby. **Idempotence:** pokus o `paid → paid` (duplicitní callback) nesmí vyhodit `IllegalTransition` a spadnout na 500 — `transitionPayment` (nebo volající) rozpozná „už jsem v cílovém stavu" a tiše skončí bez zápisu `order_events`. Přechod do stejného stavu = no-op, ne výjimka.

### Backend — konfigurace brány

- Credentials brány (Comgate merchant id / `merchant`, `secret`) žijí v `payment_methods.settings` (`encrypted:array`) u metody s `provider = 'comgate'` — stejný vzor jako bankovní účet QR. V adminu maskované, mění se opětovným zadáním (spec §16.5).
- Test/produkční režim brány (Comgate `test` flag) v settings.
- Modul čte credentials přes `SettingsService` / kontrakt, nikdy natvrdo z `.env` (multi-tenant: každý nájemce má vlastní účet u brány).

### Backend — visící neuhrazené objednávky

Objednávka s `place()` odepíše sklad, ale online platba může selhat/vypršet → objednávka visí `unpaid` se zablokovaným skladem. Řešení (rozhodnutí do plánu):

- Objednávka `unpaid` s online providerem, u které do TTL (návrh: 30–60 min) nedorazí `paid`, se označí `failed` a **sklad se vrátí** (reverzní `incrementStock` v téže transakci jako přechod). Job/scheduler.
- MVP varianta: bez automatického TTL, jen ruční storno v adminu vrací sklad (už existuje z 1.3). TTL job jako fast-follow. **Rozhodnout v plánu.**

### Frontend — storefront (Blade SSR)

- Stránka „platba se nezdařila" — `noindex`, tlačítko „Zaplatit znovu" (znovu `initiate` téže objednávky).
- Stránka „platba se zpracovává" (pending) — `noindex`, meta-refresh nebo instrukce.
- Děkovná stránka rozlišuje stav platby: zaplaceno vs. čeká na platbu (u převodu/dobírky beze změny).
- Přesměrování na bránu funguje **bez JS** (server redirect, ne JS).
- Návratové stránky mají `noindex` (jako košík/pokladna, `storefront-rendering.md`).

### Frontend — admin nájemce (Inertia SPA)

- V nastavení plateb (modul `shipping`) přibude metoda typu Comgate s poli credentials — maskovaná, jako QR účet.
- V detailu objednávky vidí nájemce `payment_status` včetně `failed` a případný gateway reference.

## Akceptační kritéria

1. Zákazník s vybranou Comgate platbou je po odeslání objednávky přesměrován na platební stránku brány (server redirect, funguje s vypnutým JS).
2. Po úspěšném zaplacení má objednávka `payment_status = paid` a zákazník vidí děkovnou stránku s potvrzením platby.
3. `payment_status` se změní na `paid` **výhradně** po `verify()` dotazu na bránu — falešný `GET /platba/navrat?status=paid` bez skutečné platby objednávku nezaplatí (test: podvržený návrat neověřené objednávky nechá `unpaid`).
4. Duplicitní notifikace (webhook + návrat, nebo dvě notifikace) nechá objednávku `paid` a nezapíše dva `order_events` řádky ani nespadne na 500 (test idempotence).
5. Webhook s neplatným/chybějícím podpisem je odmítnut (HTTP 4xx), objednávka nezměněna.
6. Neúspěšná/zrušená platba nechá objednávku ve stavu umožňujícím opakování; zákazník má tlačítko „Zaplatit znovu".
7. Credentials brány jsou v DB šifrované a v adminu maskované; uložení bez nového zadání je nepřepíše.
8. Vypnutý modul `payments`: pokladna funguje na dobírku/převod, Comgate se nenabízí, žádná online platba spadne graciézně (`NullPaymentGatewayRegistry` → `for()` = null, `available()` prázdné).
11. Checkout, návrat i webhook volají bránu výhradně přes `PaymentGatewayRegistry::for($provider)` — nikde není natvrdo `ComgateGateway` (test: přidání fiktivního druhého driveru nevyžaduje změnu checkoutu/webhooku).
9. Tenant A nevidí ani nepoužije Comgate credentials tenanta B (izolace přes `payment_methods.settings` scoped na tenant).
10. `payment_status` graf: `unpaid→paid`, `unpaid→failed`, `failed→unpaid` legální; ostatní odmítnuty `IllegalTransition`; přechod do stejného stavu = no-op.

## Technické poznámky

- Comgate HTTP API: `create` (založ platbu, vrátí redirect URL), `status`/`recurring` (dotaz stavu), background notifikace na `url_paid`/`url_cancelled`/`url_pending`. **Payloadu notifikace se nevěří — stav se vždy re-dotáže přes status API.** Ověřit aktuální verzi API a endpointy před implementací.
- Kontrakty: vzor `App\Core\Catalog\Contracts\ProductCatalog`, `App\Core\Orders\Contracts\OrderPlacement`.
- Routy: `GET /platba/navrat` (web, tenant-scoped), `POST /platba/notifikace` (mimo CSRF, podpis brány). Tenant identita u webhooku z URL/payload reference, **ne z Host hlavičky** (S2S request nemá spolehlivý Host ani session).
- `OrderWorkflow` je module-internal; webhook controller modulu `payments` ho volá přes kontrakt / OrderBook, ne přímo na cizí model.
- Sklad: reverzní odpis přes `ProductCatalog::incrementStock()` (existuje z 1.3 storna).

## Bezpečnost

- **Webhook autenticita:** ověřit podpis/secret Comgate; bez ověření odmítnout. Nikdy neměnit stav objednávky jen z příchozího payloadu.
- **Verify-before-trust:** stav platby vždy re-dotázat u brány (`status` API), nikdy nevěřit query parametrům návratu ani tělu notifikace.
- **Idempotence:** callback může přijít vícekrát a souběžně s návratem — atomický přechod, no-op při už dosaženém stavu.
- **CSRF:** webhook mimo `web` skupinu (nemá session), zabezpečen podpisem místo CSRF tokenu.
- **Tenant izolace:** credentials scoped na tenant; webhook nesmí zapisovat do cizího tenanta — reference objednávky musí ověřit příslušnost k tenantovi z URL.
- **Leak guard:** `/platba/navrat` resolvuje objednávku striktně tenant-scoped podle uuid jako `ThankYouController`.
- **Log:** platební události do `AuditLog` / `order_events`.

## Reference

- Spec platformy: §16.5 (platební metody), §16.6 (online brány)
- Předchozí vlna: `docs/superpowers/specs/2026-07-20-faze-1-vlna-13-checkout.md`
- Rozhodnutí offline plateb: CLAUDE.md 2026-07-21 (matice, QR, encrypted settings)
- As-is (po dokončení): `docs/as-is/2026-07-21-payments.md`
