# Modul `payments` — online platební brána (vlna 1.4) — implementační plán

> **Pro agenta:** Použij superpowers:subagent-driven-development (doporučeno) nebo superpowers:executing-plans, krok po kroku. Kroky mají `- [ ]`.

**Cíl:** Zákazník zaplatí kartou přes Comgate: po odeslání objednávky redirect na bránu, po ověřeném zaplacení `payment_status = paid` a děkovná stránka. Neúspěch/vypršení nechá objednávku ve stavu k opakování a vrátí sklad. Vypnutý modul nechá pokladnu funkční na offline platbách.

**Architektura:** Nový vypnutelný modul `payments`, bez `requires`. Dva nové jádrové kontrakty v `app/Core/Payments/` — `PaymentGateway` (jeden driver) a `PaymentGatewayRegistry` (`for(provider)`, `available()`) — s guest-safe null bindingem `NullPaymentGatewayRegistry` v jádře, přebitým modulem. Checkout, návrat i webhook sahají **výhradně přes registr**, nikdy na konkrétní driver → GoPay/Stripe = pozdější driver bez zásahu do jádra. Vlna 1.4 registruje jediný driver `ComgateGateway`. Stav platby se mění **jen po `verify()` dotazu na bránu** (verify-before-trust), idempotentně. Neuhrazené online objednávky expiruje odložený queue job (návrat skladu).

**Tech stack:** Laravel 13, PHP 8.3, MySQL 8, PHPUnit, Blade SSR (storefront návratové stránky), Inertia/Vue (admin platební metody v modulu `shipping`), Redis fronty (odložený expirační job), `spatie/laravel-multitenancy`. HTTP klient brány přes Laravel `Http` fasádu (žádný nový composer balíček — Comgate má prosté HTTP API; balíček jen po schválení).

**Spec:** `docs/superpowers/specs/2026-07-21-faze-1-vlna-14-payments.md`

---

## Global Constraints

- Kód a komentáře anglicky; user-facing stringy česky se správnou diakritikou.
- Nikdy needituj `.env`. V kódu `config()`, ne `env()`. Comgate credentials **nejsou** v configu — jsou per-tenant v `payment_methods.settings` (`encrypted:array`).
- **Verify-before-trust je závazné.** `payment_status` se nikdy nemění z query parametrů návratu ani z těla webhooku — vždy re-dotaz `verify()` na status API brány.
- Webhook (`POST /platba/notifikace`) je **mimo `web` skupinu** (nemá session/CSRF), zabezpečen podpisem brány. Tenant identita z reference objednávky v URL/payload, **ne z Host hlavičky**.
- Nová doménová data nesou `tenant_id` (`SchemaConventionTest` to vynucuje); nepřidávat do `PLATFORM_TABLES`. Nový sloupec na `orders` (gateway reference) nese tenant kontext skrz řádek objednávky.
- Admin platebních metod jde přes modul `shipping` (tam žijí `payment_methods`), routy za `['web','module:shipping','tenant.member']`, autorizace `user('web')->can(...)`.
- Storefront návratové/chybové stránky: Blade SSR, funkční bez JS, `noindex`, vyřazené z page cache pravidlem routy (`/platba/*`).
- Před commitem `./vendor/bin/pint` na změněné PHP; při Vue změnách `npm run build`.
- **Testy pouštěj jeden po druhém** — souběh poškodí sdílenou MySQL test DB. Comgate HTTP volání v testech vždy `Http::fake()`, nikdy reálná síť.
- `composer.json`/`package.json` neměnit bez souhlasu.
- Verzování: `versioning` skill, patch bump per commit, minor na konci vlny (`0.13.0`).

## Reference points v existujícím kódu

Přečti před začátkem; plán jejich tvary předpokládá.

| Co | Kde |
|----|-----|
| Null binding + runtime module check (vzor) | `app/Providers/AppServiceProvider.php` (bindy `OrderPlacement`→`NullOrderPlacement`, `PaymentOptions`→`NullPaymentOptions`); modul se ptá `Modules/Storefront/Support/ShopModules.php` (`has()`) |
| Kontrakt + null vzor | `app/Core/Orders/Contracts/OrderPlacement.php`, `app/Core/Orders/NullOrderPlacement.php` (vyhazuje výjimku), `app/Core/Shipping/NullPaymentOptions.php` (prázdno) |
| `PlacedOrder` shape + anonymní impl (4. arg = provider, dnes `null`) | `app/Core/Orders/Contracts/PlacedOrder.php`, `Modules/Orders/Services/OrderPlacer.php:469` (`confirmation()`) |
| Stavový automat plateb (rozšíříš) | `Modules/Orders/Services/OrderWorkflow.php` (`PAYMENT_TRANSITIONS`, `transitionPayment`) |
| Order konstanty + payment_status sloupec | `Modules/Orders/Models/Order.php` (`PAYMENT_UNPAID/PAID/REFUNDED/FAILED`), migrace `2026_07_21_064228_create_orders_tables.php` |
| Návrat/odpis skladu | `app/Core/Catalog/Contracts/ProductCatalog.php` (`incrementStock`, `decrementStock`), `Modules/Products/Services/EloquentProductCatalog.php:175` |
| Platební metoda + provider + encrypted settings | `Modules/Shipping/Models/PaymentMethod.php` (`provider()`, `PROVIDER_BANK_TRANSFER`, `settings`=`encrypted:array`, `$hidden`) |
| Checkout odeslání + redirect na děkovnou | `Modules/Checkout/Http/Controllers/CheckoutController.php` (`place()`), `Modules/Checkout/Support/CartCookie.php` |
| Leak-guarded resolve podle uuid (vzor návratové routy) | `Modules/Checkout/Http/Controllers/ThankYouController.php` |
| OrderBook čtení objednávky (pro webhook/návrat) | `app/Core/Orders/Contracts/OrderBook.php`, `Modules/Orders/Services/EloquentOrderBook.php` |
| Manifest modulu + route registrar | `Modules/Shipping/module.json`, `app/Core/Modules/ModuleRouteRegistrar.php` |
| MailService (info e-mail o platbě, volitelně) | `app/Core/Mail/Contracts/MailService.php`, `MailKind::Transactional` |
| Testovací konvence + `ActivatesModules` + `TenantContext::runAs` | `tests/Feature/Modules/*`, `tests/Concerns/ActivatesModules.php` |

## Rozhodnutí, která tento plán dělá

**1. Registry, ne jeden binding.** `PaymentGatewayRegistry::for($provider)` vrací driver podle klíče `payment_methods.provider`. Checkout/návrat/webhook znají jen registr. Null registry (`for()`=null, `available()`=[]) drží guest-safe chování při vypnutém modulu. Přidání GoPay = registrace driveru, nula změn jinde. (AK 11.)

**2. Verify-before-trust.** `verify(reference)` dělá server-to-server dotaz na Comgate status API. Návrat prohlížeče i webhook volají tutéž `verify()` a tentýž idempotentní `transitionPayment`. Payloadu/query se nevěří. (AK 3, 5.)

**3. Idempotence přechodem-do-stejného-stavu = no-op.** `OrderWorkflow::transitionPayment` (nebo tenký wrapper) rozpozná `from == to` a tiše skončí bez zápisu `order_events`, místo `IllegalTransition`. Duplicitní webhook + souběžný návrat objednávku nerozbije ani nezdvojí událost. Souběh řeší `lockForUpdate` na řádku objednávky uvnitř transakce. (AK 4.)

**4. Graf plateb rozšířen o `failed`.** `unpaid→{paid,failed}`, `failed→{unpaid}` (retry), `paid→{refunded}`, `refunded→{}`. `unpaid→unpaid`/`paid→paid` = no-op (bod 3).

**5. Expirace odloženým jobem, ne cron scheduler.** Při `place()` online-unpaid objednávky se dispatchne `ExpireUnpaidOrder` s delay 30 min (config `payments.reservation_ttl`). Job pod zámkem: pokud objednávka stále `unpaid` a online provider, `transitionPayment(failed)` + `incrementStock` každé položky v téže transakci (návrat skladu). Pokud už `paid`/`failed` → no-op. Využívá jen queue worker (už běží), žádný nový cron. Fallback bez fronty (`sync` driver): job proběhne hned = špatně → proto TTL platí jen na `redis`/`database` queue; na `sync` se objednávka expiruje ručním stornem (existuje z 1.3). Dokumentovat.

**6. Gateway reference na `orders`.** Nový sloupec `orders.payment_reference` (nullable string) drží identifikátor transakce u brány (Comgate `transId`), aby `verify()` věděl, na co se ptát, a admin ho viděl. Migrace přidá sloupec; nese tenant kontext skrz řádek.

**7. `PlacedOrder::paymentProvider()` se konečně plní.** `OrderPlacer::confirmation()` dnes předává `null`; nově předá `provider` zvolené platební metody (`'comgate'` u online). Checkout podle toho rozhodne redirect na bránu vs. děkovnou. Rozšíření existujícího tvaru, ne nový.

**8. Comgate driver přes `Http` fasádu, bez composer balíčku.** Comgate má prosté form-encoded HTTP API (`create`, `status`). Laravel `Http` klient stačí; oficiální SDK nepřináší hodnotu a přidal by závislost. Podpis notifikace ověřit dle Comgate specifikace (secret). Ověřit aktuální API endpointy/pole před implementací (viz spec Tech poznámky).

---

## Etapa 0 — Kontrakty jádra + graf plateb

- [ ] `app/Core/Payments/Contracts/PaymentGateway.php` — `provider(): string`, `initiate(string $orderUuid): PaymentInitiation`, `verify(string $reference): PaymentResult`.
- [ ] `app/Core/Payments/Contracts/PaymentInitiation.php` — `redirectUrl(): string`, `reference(): string` (uložit do `orders.payment_reference`).
- [ ] `app/Core/Payments/PaymentResult.php` — readonly VO: `status` (enum `PaymentStatus`: `Paid`/`Failed`/`Pending`), `reference`, `Money $amount`.
- [ ] `app/Core/Payments/PaymentStatus.php` — enum.
- [ ] `app/Core/Payments/Contracts/PaymentGatewayRegistry.php` — `for(string $provider): ?PaymentGateway`, `available(): array` (klíče běžících nakonfigurovaných bran).
- [ ] `app/Core/Payments/NullPaymentGatewayRegistry.php` — `for()`=null, `available()`=[].
- [ ] Bind v `AppServiceProvider`: `PaymentGatewayRegistry` → `NullPaymentGatewayRegistry`.
- [ ] Rozšířit `OrderWorkflow::PAYMENT_TRANSITIONS` o `failed`; `transitionPayment` (nebo wrapper `settlePayment`) udělá `from==to` no-op.
- [ ] **Test** `tests/Feature/Modules/OrderWorkflowPaymentTest.php`: legální/nelegální přechody, `unpaid→failed→unpaid`, no-op `paid→paid` nezapíše event ani nehodí výjimku. (AK 10, 4.)
- [ ] Commit `feat: add payment gateway contracts and extend payment state machine`.

## Etapa 1 — Modul `payments`, registr, Comgate driver

- [ ] `php artisan module:make Payments` (nebo dle konvence repo); `module.json` — ne core, `requires: []`, `provides: ['payment-gateway']`.
- [ ] `Modules/Payments/Services/EloquentPaymentGatewayRegistry.php` implementuje `PaymentGatewayRegistry`: přes `ShopModules::has('payments')` + běžící nakonfigurované `payment_methods` sestaví mapu `provider → driver`. `for('comgate')` → `ComgateGateway` s credentials tenanta z `payment_methods.settings`.
- [ ] `Modules/Payments/Services/ComgateGateway.php` implementuje `PaymentGateway`: `initiate()` volá Comgate `create` (`Http::asForm()->post(...)`), vrací redirect URL + `transId`; `verify()` volá `status`, mapuje na `PaymentResult`. Testovací režim z `settings['test']`.
- [ ] `Modules/Payments/Support/ComgateSignature.php` — ověření pravosti notifikace (secret).
- [ ] `config/payments.php` — `reservation_ttl` (min), endpointy brány (base URL test/prod), časové limity HTTP. Žádné credentials.
- [ ] Modul přebije binding registru v `ModuleProvider::register()`.
- [ ] `PaymentMethod::PROVIDER_COMGATE = 'comgate'` konstanta.
- [ ] **Test** `tests/Feature/Modules/ComgateGatewayTest.php` s `Http::fake()`: `initiate` vrací redirect + reference; `verify` mapuje paid/failed/pending; podpis notifikace validní/nevalidní. `PaymentGatewayRegistryTest`: `for` vrací driver jen když modul běží a metoda nakonfigurovaná, jinak null; izolace credentials tenant A vs B (AK 9).
- [ ] Commit `feat: add payments module with Comgate driver and gateway registry`.

## Etapa 2 — Redirect z pokladny na bránu

- [ ] `orders.payment_reference` migrace (nullable string) + `Order` fillable/cast.
- [ ] `OrderPlacer::confirmation()` předá `provider` metody místo `null` do `PlacedOrder`.
- [ ] `CheckoutController::place()`: po úspěšném `place()`, když `paymentProvider()` non-null a `registry->for($provider)` vrátí driver → `initiate($uuid)`, ulož `payment_reference`, dispatch `ExpireUnpaidOrder` (delay z configu), `CartCookie::forget` + `redirect()->away($initiation->redirectUrl())`. Jinak stávající redirect na `/dekujeme`. Redirect je server-side (bez JS).
- [ ] Ošetři selhání `initiate()` (brána nedostupná): objednávka existuje jako `unpaid`, redirect na chybovou stránku „platbu se nepodařilo zahájit, zkuste znovu" s odkazem na opakování — sklad neztrácíme, expirační job ho případně vrátí.
- [ ] **Test** `CheckoutRedirectTest`: online metoda → 302 na bránu (fake `initiate`), `payment_reference` uložen, job naplánován; offline metoda → beze změny na děkovnou; selhání `initiate` → chybová stránka, objednávka `unpaid`.
- [ ] Commit `feat: redirect card checkout to payment gateway after placement`.

## Etapa 3 — Návrat, webhook, verify-before-trust

- [ ] Routy modulu `payments` (storefront skupina, bez prefixu): `GET /platba/navrat` → `PaymentReturnController`; `POST /platba/notifikace` → `PaymentWebhookController` **mimo `web`/CSRF** (vlastní middleware skupina bez `VerifyCsrfToken`, jen podpis).
- [ ] `PaymentSettlement` service (module-internal): pod `lockForUpdate` na objednávce zavolá `registry->for(provider)->verify(reference)`, pak idempotentní `transitionPayment`/`settlePayment` dle výsledku. Jedno místo pro návrat i webhook.
- [ ] `PaymentReturnController`: leak-guarded resolve objednávky podle uuid (tenant-scoped, vzor `ThankYouController`), `PaymentSettlement::settle()`, pak redirect: paid → `/dekujeme/{uuid}`, failed → chybová stránka s „Zaplatit znovu", pending → informační stránka.
- [ ] `PaymentWebhookController`: ověř podpis (`ComgateSignature`); neplatný → HTTP 4xx bez změny (AK 5). Platný → dohledej objednávku podle reference (tenant z reference, ne Host), `PaymentSettlement::settle()`, vrať 200. Idempotentní.
- [ ] Tenant kontext webhooku: reference objednávky určuje tenanta; ověř příslušnost, zabraň zápisu do cizího tenanta.
- [ ] **Test** `PaymentReturnTest`: podvržený `?status=paid` na neověřenou objednávku (fake `verify`→pending) nechá `unpaid` (AK 3); ověřený paid → `paid` + redirect děkovná; failed → retry stránka. `PaymentWebhookTest`: validní podpis paid → `paid`, 200; nevalidní podpis → 4xx, beze změny; **duplicitní** notifikace → stále `paid`, jen jeden `order_events` (AK 4); webhook s cizí referencí nezapíše do cizího tenanta (AK 9).
- [ ] Commit `feat: add payment return and webhook with verify-before-trust settlement`.

## Etapa 4 — Expirace neuhrazených objednávek (návrat skladu)

- [ ] `Modules/Payments/Jobs/ExpireUnpaidOrder.php`: pod zámkem — pokud objednávka stále `unpaid` a online provider, `transitionPayment(failed)` + `incrementStock` každé položky ze snímku `order_items`, vše v transakci. Jinak no-op (už paid/failed/stornováno).
- [ ] Guard na queue driver: na `sync` job neplánovat (jinak expiruje hned) — `CheckoutController` dispatchne jen když `config('queue.default') !== 'sync'`. Dokumentovat v as-is jako known-limitation.
- [ ] **Test** `ExpireUnpaidOrderTest`: unpaid online po TTL → `failed` + sklad vrácen; mezitím zaplacená → no-op, sklad nevrácen; stornovaná → no-op.
- [ ] Commit `feat: expire unpaid gateway orders and return their stock`.

## Etapa 5 — Admin credentials + storefront stránky

- [ ] Admin platebních metod (modul `shipping`): metoda typu Comgate s poli `merchant`, `secret`, `test` — maskovaná, měněná opětovným zadáním (vzor QR účtu, `$hidden` + „ponech beze změny"). Form Request validace.
- [ ] Storefront Blade: `payment-failed` (retry tlačítko → nový `initiate` téže objednávky), `payment-pending` (informační, meta-refresh). Obě `noindex`.
- [ ] Děkovná stránka rozliší `paid` vs. čeká na platbu.
- [ ] Detail objednávky v adminu (`Modules/Orders`) zobrazí `failed` stav + `payment_reference`.
- [ ] Přidat `/platba/*` do route-based vyloučení z page cache (potvrdit, kde se pravidlo drží).
- [ ] **Test** admin: uložení Comgate credentials šifruje + maskuje, prázdné pole nepřepíše (AK 7); vypnutý modul `payments` → Comgate se v pokladně nenabízí, offline projde (AK 8).
- [ ] `npm run build` ověř Vue změny.
- [ ] Commit `feat: add Comgate admin config and storefront payment result pages`.

## Etapa 6 — Doind, as-is, uzávěr vlny

- [ ] Projít 11 AK proti testům; doplnit chybějící.
- [ ] `docs/as-is/2026-07-21-payments.md` + aktualizovat `docs/as-is/STATUS.md` (řádek `payments`), sekce Odchylky.
- [ ] Zapsat rozhodnutí do `CLAUDE.md` (registry pattern, verify-before-trust, expirační job na queue ne cron, gateway reference sloupec).
- [ ] Spec status → `done`.
- [ ] Minor bump `0.13.0`, `CHANGELOG.md`.
- [ ] Commit `docs: record payments module as-is and bump to 0.13.0`.

## Rizika a mitigace

| Riziko | Mitigace |
|--------|----------|
| Comgate API pole/endpointy se od paměti liší | Před etapou 1 ověřit aktuální dokumentaci; veškerá HTTP volání za `Http::fake()` v testech |
| Souběh návrat × webhook zdvojí platbu/událost | `lockForUpdate` + `from==to` no-op v `PaymentSettlement` |
| Podvržený návrat označí zaplaceno | Verify-before-trust: stav jen z `verify()`, nikdy z query/payloadu |
| Neuhrazená objednávka blokuje sklad | Expirační job vrací sklad; ruční storno jako fallback (sync queue) |
| Webhook zapíše do cizího tenanta | Tenant z reference objednávky, ověření příslušnosti, ne Host hlavička |
| Credentials brány uneseny | `encrypted:array`, `$hidden`, maskované v adminu, per-tenant |

## Strategie testů

- Feature testy PHPUnit, jeden po druhém, MySQL test DB, `ActivatesModules`, `TenantContext::runAs`.
- Comgate HTTP vždy `Http::fake()` — žádná reálná síť.
- Pokrytí: 11 AK ze spec, každé alespoň jedním testem; navíc izolace tenantů (credentials + webhook) a idempotence.
