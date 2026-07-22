# As-is — Vlna 1.8: Stripe subscription billing

- Datum: 2026-07-22
- Verze: 0.17.0
- Branch: `feat/wave-1.8-stripe-subscription`
- Spec: [`docs/superpowers/specs/2026-07-22-vlna-18-stripe-subscription-design.md`](../superpowers/specs/2026-07-22-vlna-18-stripe-subscription-design.md)
- Plán: [`docs/superpowers/plans/2026-07-22-faze-1-vlna-18-stripe-subscription.md`](../superpowers/plans/2026-07-22-faze-1-vlna-18-stripe-subscription.md)
- Testy: **993 passed / 0 failed** (3357 assertions)

## Co vlna dodává

Napojuje reálné inkaso platformního předplatného přes **Stripe Billing**: Stripe řídí
opakovaný fakturační cyklus a dunning, my reagujeme webhooky. Nájemce v trialu
aktivuje předplatné self-service přes hostovaný Stripe Checkout; kartu, zrušení a
historii spravuje přes hostovaný Billing Portal. Náš netenantový platformní ledger
(vlna 1.7) zůstává jediný závazný daňový doklad — vystavuje se na `invoice.paid`,
idempotentně per fakturační období. Uzavírá háček z vlny 1.7 (charge-success-then
-issue-fail): synchronní aktivační cesta (`SubscriptionActivator`) je retirovaná,
aktivaci teď spouští výhradně webhook po skutečném zaplacení.

## Mapa změněných částí

### Seam `SubscriptionGateway` (redesign)
- `app/Core/Billing/Contracts/SubscriptionGateway.php` — nový tvar: `startCheckout(Tenant, Plan): string`, `billingPortalUrl(Tenant): string`. Starý `charge(SubscriptionCharge): ChargeResult` pryč.
- `app/Core/Billing/StripeSubscriptionGateway.php` — reálný driver přes `\Stripe\StripeClient`. Založí/reuse Stripe Customer, otevře Checkout Session (`mode=subscription`, `metadata.tenant_id` na session i subscription), vrátí `billingPortal` session URL.
- `app/Core/Billing/NullSubscriptionGateway.php` — `startCheckout` vrací lokální dev routu (`admin.subscription.dev-complete`), `billingPortalUrl` vrací `admin.subscription`. Default driver v testech a devu.
- **Retired:** `app/Core/Billing/SubscriptionActivator.php`, `app/Core/Billing/Support/ChargeResult.php`, `app/Core/Billing/Exceptions/ChargeFailed.php`, `TenantController::activateSubscription`, `ActivateSubscriptionTest`, `SubscriptionActivatorTest`. **Zůstávají:** `PlatformInvoiceWriter`, `Support/SubscriptionCharge`, `Exceptions/MissingBillingProfile`.

### Webhook zpracování
- `app/Core/Billing/StripeWebhookHandler.php` — netenantový, mapuje `checkout.session.completed` / `invoice.paid` / `invoice.payment_failed` / `customer.subscription.deleted` na doménu. Tenanta dohledá z `stripe_customer_id` (nebo `metadata.tenant_id` na checkoutu). Idempotence: `StripeEvent::create` (unique `event_id`) a samotné zpracování běží **v jedné** `DB::transaction` — duplicitní event ztratí unique insert a celá transakce se vrátí (žádné opakované vedlejší efekty); selhání uprostřed zpracování vrátí i claim, takže Stripe retry není ztracen napořád.
- `app/Core/Billing/Models/StripeEvent.php` — `event_id` (unique), `type`, `processed_at`.
- Přechody přes `Tenant::changeStatus` v `TenantContext::runAs($tenant)` (audit `tenant_id`), stejný vzor jako sweeper 1.7. `invoice.paid` navíc přepisuje `trial_ends_at` na `current_period_end` (paid-through) a volá `PlatformInvoiceWriter::issue()`.

### Webhook endpoint
- `app/Http/Controllers/StripeWebhookController.php` + `routes/platform.php`: `POST /superadmin/stripe/webhook`, `withoutMiddleware(VerifyCsrfToken)`, bez session/auth. Autenticita přes `Stripe-Signature` (`\Stripe\Webhook::constructEvent`). 2xx po zpracování (i neznámý zákazník), 4xx jen na neplatný/chybějící podpis.

### Admin nájemce
- `app/Http/Controllers/Tenant/SubscriptionController.php` (`show`/`checkout`/`portal`/`devComplete`) + `routes/tenant.php`: `/admin/predplatne`, `/admin/predplatne/checkout`, `/admin/predplatne/portal`, `/admin/predplatne/dev-dokonceni`. `checkout` guarduje na kompletní fakturační profil (`billing_name`) → jinak redirect na `/admin/nastaveni/fakturace`. `devComplete` je jen na `null` driveru (`abort_unless` 404 jinak) a guarduje proti nelegální reaktivaci (`TenantStatus::canTransitionTo`).
- `resources/js/Pages/Tenant/Subscription.vue` — stav, plán, cena, paid-through, tlačítka Checkout/Portal.
- `app/Http/Middleware/HandleInertiaRequests.php` — sdílené propy `trialDaysLeft` a `subscriptionActive` (trial banner v `AdminLayout.vue`).
- `app/Http/Middleware/CheckTenantStatus.php` — rozlišuje admin routy od storefrontu (`TenantStatus::allowsAdminRead()` vs `allowsStorefront()`): suspendovaný nájemce dál vidí read-only admin (aktivovat předplatné, stáhnout data), storefront je dole. Vedlejší fix bundlovaný s `devComplete` guardem (dokončuje háček z vlny 0.1).

### Superadmin
- Manuální „Aktivovat předplatné" (`TenantController::activateSubscription`, dialog v `Show.vue`) **retirováno**. `Show.vue` teď zobrazuje read-only blok: stav, `stripe_subscription_id`, placeno do. `app/Core/Platform/TenantOverview.php` přidává `paid_through`/`stripe_customer_id`/`stripe_subscription_id` do snímku (žádná tajemství — jen id).

### Sweeper
- `app/Console/Commands/SweepTenantLifecycle.php` — obě větve (`trial→past_due`, `past_due→suspended`) filtrují `whereNull('stripe_subscription_id')`. Stripe-managed nájemce má lifecycle řízený webhookem, ne `trial_ends_at` sweeperem.

### Data / config
- Migrace: `tenants.stripe_customer_id`/`stripe_subscription_id` (nullable, indexed), `plans.stripe_price_id` (nullable), `stripe_events` (netenantová, allowlist v `SchemaConventionTest`).
- `config/billing.php` — nová `stripe` sekce (`secret`, `webhook_secret`, `portal_config` z `env()`); odstraněn mrtvý `monthly_charge_enabled`.
- `composer.json`/`composer.lock` — `stripe/stripe-php` (dřív balast po šabloně, teď skutečně používaný).

## Plnění spec po sekcích

- **Model Stripe Billing (Checkout + Portal, žádné PCI u nás)** — hotovo, `StripeSubscriptionGateway`.
- **Seam redesign** — hotovo přesně dle návrhu (`startCheckout`/`billingPortalUrl`, retire activator/`ChargeResult`/`ChargeFailed`, `SubscriptionCharge`/`MissingBillingProfile`/`PlatformInvoiceWriter` zůstávají).
- **Mapování stavů (4 eventy)** — hotovo, `StripeWebhookHandler`.
- **Sweeper interplay** — hotovo, guard na obou větvích.
- **Webhook endpoint (podpis, 2xx/4xx, idempotence)** — hotovo. **Odchylka v cestě**, viz níže.
- **Admin UX (banner, `/admin/predplatne`, guard profilu)** — hotovo.
- **Superadmin retire + read-only** — hotovo.
- **Data/migrace/config/allowlist** — hotovo.
- **Jen měsíční interval, tarif fixní** — hotovo (roční/upgrade mimo rozsah, viz spec).

Akceptační kritéria 1–10 ze spec pokryta testy (viz níže); kritérium 9 (Billing
Portal redirect) pokryto na úrovni volání gateway/kontroleru, ne skutečnou Stripe
portal session (vyžaduje test-mode API klíč, viz technický dluh).

## Testy

Nové/upravené sady: `NullSubscriptionGatewayTest` (přepsán pro nový seam),
`StripeSchemaTest` (sloupce + `stripe_events` allowlist), `StripeSubscriptionGatewayTest`
(checkout metadata, customer reuse, portal config, chybějící `stripe_price_id`),
`StripeWebhookHandlerTest` (4 event typy, idempotence duplicitního `event_id`,
atomicita claim+zpracování, no-op na neznámém zákazníkovi/typu), `StripeWebhookRouteTest`
(platform-host izolace, platný/neplatný podpis, 2xx/4xx), `SweepTenantLifecycleTest`
(rozšířen o stripe-managed guard), `TenantSubscriptionViewTest` (superadmin read-only
blok, žádné tajné údaje v propu), `SubscriptionCheckoutTest` (guard na fakturační
profil, redirect na `/admin/nastaveni/fakturace`, `devComplete` guard proti reaktivaci),
`SubscriptionPageTest` (Inertia render, `trialDaysLeft`/`subscriptionActive` propy).
Smazané: `SubscriptionActivatorTest`, `ActivateSubscriptionTest` (retirovaná cesta).

Pokryto: idempotence webhooku (duplicitní `event_id` = no-op, atomicky s claim),
4 mapování stavů včetně no-op větví, podpisová autentizace endpointu (platný/neplatný/
malformovaný), sweeper guard na `stripe_subscription_id`, guard fakturačního profilu
před checkoutem, `devComplete` transition guard (nelze reaktivovat z `pending_deletion`/
`deleted`), izolace platformního ledgeru (existující test z 1.7 drží dál), read-only
superadmin blok (žádný leak Stripe secretu, jen id).

**Deferred (netestováno end-to-end proti reálnému Stripe):**
- Skutečný Checkout redirect a jeho `success_url`/`cancel_url` návrat.
- Skutečná Billing Portal session (vrácené URL, `portal_config`).
- Skutečný webhook payload ze živého Stripe test-módu (testy simulují `\Stripe\Event` objekty konstruované lokálně, ne skutečný podpis ze Stripe serveru).
- Roční interval, upgrade/downgrade tarifu s proraci — mimo rozsah vlny (viz spec „Mimo rozsah").

## Odchylky od specifikace

1. **Webhook endpoint je `POST /superadmin/stripe/webhook`, ne `/platform/stripe/webhook`** jak psal návrh spec. Sedí do existující `routes/platform.php` konvence (`/superadmin/*` prefix pro všechny netenantové platformní routy, `platform.host` middleware) — nová `/platform/*` skupina by byla druhý vzor pro totéž.
2. **Paid-through reuse `trial_ends_at`, ne nový `paid_through_at` sloupec.** Spec nechávala rozhodnutí na plánu. `Tenant::status` + `trial_ends_at` páru dohromady nese „do kdy platí" bez ohledu na to, jestli je to ještě trial nebo už placené období — stejné pole čte trial banner (1.7) i tenant-facing i superadmin subscription screen. Riziko: název pole je matoucí pro čtenáře kódu mimo kontext (`trial_ends_at` u aktivního plateného tenanta). Zmíněno jako otevřený háček ve spec i v CLAUDE.md.
3. **`CheckTenantStatus` middleware rozšířen o admin/storefront rozlišení** (`allowsAdminRead()`) v rámci commitu opravujícího `devComplete` guard — nebylo explicitně v zadání Tasku 6, ale dokončuje otevřený TODO z vlny 0.1 („admin routes get their own read-only gate... once the admin exists") a bylo nutné pro smysluplné chování suspendovaného nájemce v adminu (čte read-only, nemůže nakupovat/prodávat). Zdokumentováno zde, ne jako samostatná vlna.
4. **`billingPortalUrl` a `startCheckout` nejsou ověřeny proti živému Stripe API** — bez test-mode klíčů v tomto prostředí. SDK volání jsou správná dle Stripe PHP API (ověřeno ze znalosti API tvaru, ne behaviorálním testem proti Stripe serveru).

## Technický dluh a pre-deploy checklist

- [ ] **Stripe test-mode klíče** (`STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`) do `.env.local` a **deploy smoke test**: skutečný Checkout, skutečný webhook z sandboxu, skutečný Billing Portal — nic z toho neběželo proti živému Stripe v tomto vývojovém prostředí.
- [ ] **`plans.stripe_price_id` se plní ručně** v Stripe dashboardu a pak do DB (seed/superadmin editace = follow-up; zatím žádné UI na to).
- [ ] **Roční interval, upgrade/downgrade tarifu s proraci** — odloženo, mimo rozsah vlny 1.8 (viz spec).
- [ ] **`paid_through` = `trial_ends_at` reuse** — až přibude potřeba nezávislého trackování (např. zobrazit historii období), zvážit vlastní `paid_through_at` sloupec.
- [ ] **`devComplete` je jen dev-driver cesta** (404 na `stripe` driveru) — ověřit při přechodu prod configu na `BILLING_SUBSCRIPTION_DRIVER=stripe`, že žádný test/seed na ni nespoléhá mimo `null` driver.
- [ ] **Discoverability nezměněna z 1.7:** fakturační profil a předplatné dostupné jen přes banner/nav, ne přes plnou core nav sekci.
- [ ] Právní/účetní: `invoice.paid` webhook vystavuje daňový doklad automaticky — ověřit, že `config('billing.company')` je vyplněný před prod nasazením (stejný pre-deploy bod jako 1.7, teď skutečně spouští peněžní tok).
