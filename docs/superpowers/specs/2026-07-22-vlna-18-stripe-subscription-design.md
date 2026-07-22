# Vlna 1.8 — Stripe subscription billing (platformní předplatné)

**Datum:** 2026-07-22
**Status:** approved
**Související plán:** `docs/superpowers/plans/2026-07-22-faze-1-vlna-18-stripe-subscription.md` (po writing-plans)

## Kontext

Nájemci (provozovatelé e-shopů) platí **nám** měsíční poplatek za pronájem platformy.
Vlna 1.7 postavila netenantový platformní ledger (`platform_invoices`, gap-free
`PF{YYYY}{NNNN}`, snímek dodavatele/odběratele, PDF) a seam `SubscriptionGateway`
zatím jen s `NullSubscriptionGateway` (dev auto-success). Reálné inkaso bylo
odloženo do 1.8. Tahle vlna napojuje Stripe jako platební bránu předplatného.

**Rozsah zdroje pravdy:** řešíme jen platbu nájemce vůči platformě. Platební brány
na storefrontech nájemců (Comgate/GoPay pro *jejich* koncové zákazníky) jsou hotové
ve vlně 1.4 a s touto vlnou se nemíchají — jiná kniha (`docs` modul), jiný svět.

## Model (schváleno v brainstormingu)

- **Stripe Billing** — Stripe řídí opakovaný fakturací cyklus, dunning a SCA/3DS
  retry. My reagujeme **webhooky**. (Ne PaymentIntents, kde bychom cyklus řídili
  sami.)
- Karta se sbírá přes **Stripe Checkout** (subscription mode, hosted). Správa
  (změna karty, zrušení, historie) přes **Stripe Billing Portal**. Žádné karetní
  údaje u nás — PCI SAQ-A.
- **Náš platformní ledger zůstává závazný daňový doklad**, vystaven na webhooku
  `invoice.paid`. Stripe invoice je jen inkaso, ne český daňový doklad.
- **Jen měsíční interval** (`plans.price_month`), tarif je fixní z onboardingu.
  Roční interval a upgrade/downgrade tarifu s proraci = pozdější vlna.
- `stripe/stripe-php` je už v repu (zbytek po šabloně, vyhrazený přesně pro tohle).

## Cíle

- [ ] Nájemce v trialu aktivuje předplatné self-service přes Stripe Checkout a
      e-shop pokračuje bez přerušení.
- [ ] Na `invoice.paid` se automaticky vystaví náš platformní doklad (idempotentně
      per období), tenant přejde na `Active`, prodlouží se paid-through.
- [ ] Neúspěšná platba přepne tenanta na `past_due` (storefront běží dál); vyčerpaný
      dunning na `suspended`.
- [ ] Nájemce spravuje kartu/zrušení přes Billing Portal.
- [ ] Onboarding, lifecycle sweeper a testy jedou dál beze změny na `null` driveru.
- [ ] Háček 1.7 (charge-success-then-issue-fail) je odstraněn.

## Mimo rozsah

- Roční předplatné (`price_year`), upgrade/downgrade tarifu, proration, kredity.
- Kupóny/slevy, víc měn (jen CZK), víc paralelních předplatných na tenanta.
- Platební brány storefrontu nájemce (Comgate/GoPay) — vlna 1.4, netýká se.
- Vlastní doména nájemce, SSL — fáze 2.

## Požadavky

### Seam redesign (jádro)

Současný synchronní `SubscriptionGateway::charge(SubscriptionCharge): ChargeResult`
+ `SubscriptionActivator` (superadmin pull) modelu Stripe Billing nesedí — inkaso
řídí Stripe, aktivace přijde webhookem. Přepracovat:

- **`SubscriptionGateway`** (kontrakt `app/Core/Billing/Contracts/`) nový tvar:
  - `startCheckout(Tenant $tenant, Plan $plan): string` — vytvoří/reuse Stripe
    Customer + Checkout Session (subscription mode) a vrátí hosted URL k redirectu.
  - `billingPortalUrl(Tenant $tenant): string` — Billing Portal session URL.
- **`StripeSubscriptionGateway`** — implementace přes `\Stripe\StripeClient`.
- **`NullSubscriptionGateway`** — `startCheckout` vrátí lokální dev route, která
  simuluje úspěšný webhook (aby onboarding/testy jely end-to-end bez Stripe);
  `billingPortalUrl` vrátí placeholder. Default driver v testech.
- **Retire:** `SubscriptionActivator`, `SubscriptionCharge`, `ChargeResult`,
  `ChargeFailed` a starý `charge()` — synchronní aktivační cesta zaniká.
  `PlatformInvoiceWriter` (ledger) a `MissingBillingProfile` **zůstávají**.
- **`StripeWebhookHandler`** (netenantový, `app/Core/Billing/`) — mapuje Stripe
  eventy na doménu (viz mapování stavů). Tenanta dohledá z `stripe_customer_id` /
  `stripe_subscription_id` v payloadu.

### Mapování stavů (Stripe event → `TenantStatus`)

| Stripe event | Akce |
|---|---|
| `checkout.session.completed` (mode=subscription) | ulož `stripe_customer_id` + `stripe_subscription_id` na tenanta |
| `invoice.paid` | vystav náš doklad (`PlatformInvoiceWriter`, idempotentní per období) → `Active` → prodluž paid-through na `current_period_end` |
| `invoice.payment_failed` | `past_due` (Stripe retriuje dle své dunning politiky; storefront běží dál, spec odchylka §2) |
| `customer.subscription.deleted` | `suspended` (dunning vyčerpán nebo tenant zrušil) |

Přechody přes `Tenant::changeStatus` v `runAs($tenant)` (audit `tenant_id`), stejný
vzor jako sweeper 1.7. Idempotence: `changeStatus`/vystavení dokladu no-op při
opakovaném stejném eventu.

### Sweeper interplay

- **Čistý trial** (tenant bez `stripe_subscription_id`): stávající `SweepTenantLifecycle`
  1.7 beze změny (trial → past_due → suspended; tihle kartu nezadali).
- **Stripe-managed** (tenant s `stripe_subscription_id`): sweeper je **přeskočí** —
  jejich lifecycle vlastní Stripe. Nový guard v `SweepTenantLifecycle` (filtr
  `whereNull('stripe_subscription_id')`).

### Webhook endpoint

- Route `POST /platform/stripe/webhook`, `withoutMiddleware(VerifyCsrfToken)`, bez
  session/auth — autenticita přes `Stripe-Signature` header + webhook signing secret
  (`\Stripe\Webhook::constructEvent`). Vzor Comgate webhook 1.4.
- Vždy vrací **2xx po zpracování** (i „neznámý tenant/subscription") aby Stripe
  přestal retryovat; **4xx jen** na neověřený podpis / malformovaný payload.
- **Idempotence přes Stripe event id**: lehká tabulka `stripe_events`
  (`event_id` unique) — už zpracovaný event = no-op 2xx. Chrání proti Stripe
  at-least-once doručení.
- Netenantový endpoint (jeden pro platformu), ne per-host. Tenant se řeší z payloadu.

### Admin UX (strana nájemce)

- **Banner v trialu**: „Zbývá X dní trialu — aktivovat předplatné" → tlačítko
  → controller volá `startCheckout` → redirect na Stripe → `success_url`
  (`/admin/predplatne?stav=ok`) / `cancel_url`.
- **„Spravovat předplatné"** (změna karty, zrušení, faktury) → redirect na Billing
  Portal (`billingPortalUrl`).
- Nová core route skupina `/admin/predplatne` pod `['web','tenant.member']` (vzor
  fakturační profil 1.7, `routes/tenant.php`).
- **Podmínka před Checkoutem:** kompletní fakturační profil (`billing_name` atd. z
  1.7) — jinak nejde vystavit náš doklad. Nekompletní → přesměrovat na
  `/admin/nastaveni/fakturace` s hláškou.

### Superadmin

- Manuální „Aktivovat předplatné" akce (`TenantController::activateSubscription`) se
  **retiruje** — aktivace je teď self-service nájemce.
- Superadmin vidí read-only stav předplatného (TenantStatus, `stripe_subscription_id`,
  paid-through) v detailu tenanta.

### Data / migrace

- `tenants`: `stripe_customer_id` (nullable, index), `stripe_subscription_id`
  (nullable, index).
- `plans`: `stripe_price_id` (nullable string) — Price se založí v Stripe dashboardu,
  ID se sem doplní (seed/superadmin později).
- `stripe_events`: `id`, `event_id` (unique), `type`, `processed_at` — idempotence.
  Netenantová, allowlist v `SchemaConventionTest` (jako `platform_invoices`).
- `config/billing.php`: přidat `stripe` sekci (secret key, webhook signing secret,
  billing portal config id) přes `env()`. Odstranit mrtvý `monthly_charge_enabled`
  (design-for sweeper charge — Stripe teď inkaso řídí).

## Akceptační kritéria

1. Nájemce v trialu klikne „Aktivovat předplatné", projde Stripe Checkout, vrátí se
   do adminu a e-shop běží dál jako `Active`.
2. Webhook `invoice.paid` vystaví právě jeden náš platformní doklad per období i při
   duplicitním doručení eventu (idempotence přes `stripe_events` + writer klíč).
3. Webhook `invoice.payment_failed` přepne tenanta na `past_due`, storefront zůstává
   dostupný.
4. Webhook `customer.subscription.deleted` přepne tenanta na `suspended`.
5. Webhook s neplatným `Stripe-Signature` vrátí 4xx a nic nezmění.
6. Lifecycle sweeper nechá Stripe-managed tenanty (s `stripe_subscription_id`) beze
   změny; čistě-trial tenanty řeší dál.
7. Checkout se nespustí bez kompletního fakturačního profilu.
8. Onboarding a všechny stávající testy jedou beze změny na `null` driveru.
9. „Spravovat předplatné" přesměruje na funkční Billing Portal session.
10. Tenant A nevidí předplatné/doklady tenanta B; platformní ledger zůstává
    netenantový (stávající test drží).

## Technické poznámky

- SDK: `stripe/stripe-php`, `\Stripe\StripeClient` (secret z configu, ne `.env`
  přímo v kódu). Test klíče přes `.env.local`.
- Webhook verify: `\Stripe\Webhook::constructEvent($payload, $sigHeader, $secret)`.
- Paid-through: Stripe `invoice.lines`/subscription `current_period_end` → uložit na
  tenanta (reuse `trial_ends_at` jako paid-through, nebo nový `paid_through_at` —
  rozhodne plán; háček 1.7 zmiňuje potřebu vlastního sloupce).
- Idempotence stavů: `Tenant::changeStatus` nevaliduje přechody sám → handler musí
  ošetřit no-op při stejném cílovém stavu.
- Kontrakty a výjimky v `app/Core/Billing/` (netenantový namespace jádra).

## Reference

- Seam 1.7: `app/Core/Billing/` (`SubscriptionGateway`, `PlatformInvoiceWriter`,
  `PlatformInvoice`, `PlatformSequenceService`).
- Comgate webhook vzor: vlna 1.4 (`/platba/notifikace`, `verifyNotification`).
- CLAUDE.md rozhodnutí 2026-07-22 (platformní billing, `SubscriptionGateway` seam,
  háček activatoru).
- As-is (po dokončení): `docs/as-is/2026-07-22-onboarding-billing.md` → aktualizovat
  nebo nový `docs/as-is/YYYY-MM-DD-stripe-subscription.md`.
