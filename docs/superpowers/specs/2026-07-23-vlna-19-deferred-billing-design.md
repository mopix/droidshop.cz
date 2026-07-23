# Vlna 1.9 — Deferred billing: roční interval + upgrade/downgrade tarifu

- **Datum:** 2026-07-23
- **Fáze:** post-1.8 (deferred billing z roadmapy, bod „a")
- **Navazuje na:** vlna 1.8 (Stripe subscription billing) — `docs/as-is/2026-07-22-stripe-subscription.md`
- **Zdroj pravdy (produkt):** `docs/specs/2026-07-17-eshop-platforma-specifikace.md`
- **Typ:** rozšíření platformního předplatného (netenantový ledger)

## Cíl

Nájemce může platit předplatné **ročně** (nejen měsíčně) a **měnit tarif** base↔premium
za běhu předplatného, s korektní proraci a českým daňovým dokladem na každou skutečně
zaplacenou částku. Změny řídí **Stripe Billing Portal** (hostované UI), my jen reagujeme
webhooky. Žádné karetní údaje u nás (PCI SAQ-A jako 1.8).

## Rozhodnutí (potvrzená brainstormingem)

1. **Mechanismus = Stripe Billing Portal.** Portal nativně umí přepínání cen i intervalů
   s proraci. Nestavíme vlastní upgrade UI ani nevoláme `subscriptions->update` sami —
   minimum kódu, Stripe UI, my observujeme `customer.subscription.updated`.
2. **Ceny = tabulka `plan_prices`** (ne dva sloupce, ne config mapa). `(plan_id, interval,
   stripe_price_id, price_amount)` — flexibilní pro budoucí intervaly, editovatelné, testovatelné.
3. **Moduly při změně tarifu = plná rekonciliace.** Upgrade aktivuje moduly nového tarifu,
   downgrade deaktivuje moduly, které nový tarif nemá. Konzistentní s `TenantProvisioner`.
4. **Idempotence dokladu = Stripe invoice id** (ne per-období). Každá zaplacená Stripe faktura
   (měsíc/rok/proration) = právě jeden český doklad, částka a období přímo z faktury. Vědomý
   odklon od per-období klíče z 1.7/1.8 (viz Odchylky).

## Rozsah

### Ve vlně
- Roční interval předplatného (výběr při zakládání přes Checkout).
- Upgrade/downgrade base↔premium přes Billing Portal, s proraci.
- Rekonciliace modulů tenanta při změně tarifu.
- Český daňový doklad na proration i roční fakturu (idempotence per Stripe invoice id).

### Mimo vlnu (YAGNI, vědomě odloženo)
- Kvartální / víceleté intervaly.
- Slevové kupóny / promo kódy.
- Downgrade s grace-do-konce-období (děláme okamžitou rekonciliaci modulů).
- Roční→měsíční a naopak jako samostatný „upgrade path" krok — pokrývá totéž Portal switch.

## Datový model

### Nová tabulka `plan_prices`
| Sloupec | Typ | Pozn. |
|---------|-----|-------|
| `id` | PK | |
| `plan_id` | FK plans | |
| `interval` | enum(`month`,`year`) | |
| `stripe_price_id` | string | Stripe Price id |
| `price_amount` | unsigned bigint | haléře, **gross** |
| `currency` | char(3) | `CZK` |
| timestamps | | |

- Unique `(plan_id, interval)`.
- **Netenantová** tabulka (jako `plans`) — allowlist v `SchemaConventionTest`.

### Migrace `plans`
- Data migrace: každý existující `plans.stripe_price_id` → řádek `plan_prices` s
  `interval=month`, `price_amount = plans.price_month`, `currency=CZK`.
- Poté **zrušit** `plans.stripe_price_id`; kód přepsat na lookup přes `plan_prices`.
- `plans.price_month` zůstává (zobrazení, měsíční cena); roční `price_amount` žije v `plan_prices`.

### Migrace `tenants`
- Nový sloupec `billing_interval` enum(`month`,`year`) nullable — trackován z aktivní
  subscription pro zobrazení a fallback období. Default null (= dosud neznámý/měsíční).

### Migrace `platform_invoices`
- Nový sloupec `stripe_invoice_id` string nullable + **unique index**.
- Idempotenční lookup se přepíná z `(billed_tenant_id, period_from, period_to)` na
  `stripe_invoice_id`. Stávající řádky (null) unique neblokuje (MySQL připouští víc null).

## Kontrakty a služby

### `SubscriptionGateway` (signatura)
```php
public function startCheckout(Tenant $tenant, Plan $plan, BillingInterval $interval): string;
public function billingPortalUrl(Tenant $tenant): string; // beze změny
```
- Nový enum `App\Core\Billing\Enums\BillingInterval` (`Month`, `Year`).
- `StripeSubscriptionGateway::startCheckout` resolvne price přes `plan_prices` pro
  `(plan, interval)`; chybějící cena → `RuntimeException` (dnešní vzor pro chybějící price id).
- `NullSubscriptionGateway::startCheckout` — signatura +interval (dev no-op).

### `SubscriptionCharge` (rozšíření)
```php
final class SubscriptionCharge {
    public function __construct(
        public readonly Tenant $tenant,
        public readonly Plan $plan,
        public readonly Carbon $periodFrom,
        public readonly Carbon $periodTo,
        public readonly string $stripeInvoiceId,
        public readonly int $grossTotal,      // haléře, ze Stripe faktury
    ) {}
}
```

### `PlatformInvoiceWriter`
- `$total` bere `$charge->grossTotal` (ne `plan->price_month`).
- `existingInvoice` klíčuje na `stripe_invoice_id`.
- Insert ukládá `stripe_invoice_id`. VAT split beze změny (náš `vat_payer`).
- Idempotence: pre-lookup podle `stripe_invoice_id` + unique index jako concurrency backstop.

### Rekonciliace modulů
- Služba (nová nebo výřez z `TenantProvisioner`): pro nový plan aktivuje jeho sadu modulů,
  deaktivuje moduly, které měl **starý** plan a nový nemá. Přes stávající `tenant_modules` /
  module activator mechaniku. Běží v `runAs($tenant)` (audit tenant_id).

## Toky

### A) Zakládání s výběrem intervalu
1. `Tenant/Subscription.vue` — přepínač měsíc/rok, obě ceny z `plan_prices` (nový prop).
2. `SubscriptionController::checkout` čte `interval` z requestu (validace enum), volá
   `gateway->startCheckout($tenant, $plan, $interval)`.
3. Redirect na Stripe Checkout (`Inertia::location`). Zbytek jako 1.8.

### B) Změna tarifu/intervalu (Portal)
1. Nájemce v Portalu přepne cenu → Stripe aplikuje proraci → `customer.subscription.updated`
   (+ případně `invoice.paid` na proration fakturu).
2. `StripeWebhookHandler::onSubscriptionUpdated`:
   - nové price id z `subscription.items.data[0].price.id` → `plan_prices` → plan+interval;
   - když se `plan_id` změnil: `tenant.plan_id` = nový + rekonciliace modulů;
   - vždy `tenant.billing_interval` = nový interval.
3. `onInvoicePaid` (proration faktura) vystaví český doklad na `amount_paid` (viz C).
4. Registrace `customer.subscription.updated` v `match` webhook handleru.

### C) `onInvoicePaid` (přepis)
- plan+interval **z faktury**: price id z `invoice.lines.data[0].price.id` → `plan_prices`
  → plan (fallback `tenant->plan`).
- `grossTotal = invoice.amount_paid` (Stripe haléře pro CZK).
- období z `invoice.lines.data[0].period`.
- `SubscriptionCharge(..., stripeInvoiceId: invoice.id, grossTotal)`.
- **Guard `amount_paid == 0`** (downgrade kredit, aplikuje se příště) → doklad nevystavíme.
- Po vystavení: `Active` (pokud není), `trial_ends_at = period end`, sync `tenant.plan_id` +
  `billing_interval` z faktury (idempotentní vůči subscription.updated).

## Konfigurace Stripe (mimo kód, dokumentace)
- Billing Portal (`billing.stripe.portal_config`): povolit `subscription_update` s našimi
  4 cenami (base/premium × month/year), `proration_behavior=create_prorations`, `always_invoice`.
- Product/Price seed: 4 Price objekty ve Stripe, jejich id do `plan_prices` (ručně/seed —
  automatizace superadmin edit = follow-up, jako 1.8).

## Bezpečnost / izolace
- `plan_prices` netenantová (platformní katalog) — jako `plans`. Allowlist v schema testu.
- Webhook autentizace beze změny: podpis (`\Stripe\Webhook::constructEvent`), bez CSRF/session.
- Idempotence webhooku beze změny (claim `stripe_events.event_id` v jedné transakci).
- `subscription.updated` mění moduly/plan — běží v `runAs($tenant)`, audit log.
- Verify-before-trust drží: částka i plan **z ověřené Stripe faktury**, ne z requestu.

## Testy (Pest/PHPUnit dle profilu)
- **plan_prices:** mapování `stripe_price_id` → plan + interval; chybějící cena → výjimka.
- **checkout:** interval=month/year vybere správné price id; neplatný interval → 422.
- **onInvoicePaid:** roční částka; proration částka (≠ cena tarifu); idempotence per
  `stripe_invoice_id` (duplicitní event → jeden doklad); `amount_paid==0` → žádný doklad;
  plan+interval derivované z faktury, ne z `tenant->plan`.
- **onSubscriptionUpdated:** upgrade base→premium nastaví `plan_id` + aktivuje premium moduly;
  downgrade premium→base deaktivuje premium-only moduly; `billing_interval` update.
- **writer:** bere `grossTotal`, ne `plan->price_month`; ukládá `stripe_invoice_id`.
- **schema:** `plan_prices` v netenantovém allowlistu; `platform_invoices.stripe_invoice_id` unique.

## Odchylky od dřívějších rozhodnutí
- **Idempotence platformního dokladu: per-období → per Stripe invoice id.** Rozhodnutí 1.7/1.8
  klíčovalo `(billed_tenant_id, period_from, period_to)`. Proration faktura má překryv období
  → per-období klíč by ji zahodil a nájemce by nedostal daňový doklad na doúčtovanou částku.
  Stripe je teď autoritativní na počet a částku faktur, takže per-invoice id je robustnější
  a jednodušší. Sloupec `stripe_invoice_id` nese nový klíč, `period_from/to` zůstávají jako data.
- **`plans.stripe_price_id` zrušen** ve prospěch `plan_prices`. Jeden price id nestačí pro
  2 intervaly.

## Tech dluh / carries
- Roční→měsíční switch přes Portal vytvoří proraci; ověřit chování `amount_paid` a období
  na reálném Stripe (deploy smoke test — bez test-mode klíčů neověřitelné, jako 1.8).
- Downgrade kredit (`amount_paid==0`) — doklad nevystavíme; ověřit s účetní, že kreditní
  zůstatek nepotřebuje samostatný český doklad (pre-deploy, §29 ZDPH).
- Portal konfigurace ruční ve Stripe dashboardu (portal_config id do configu).
- Superadmin edit `plan_prices` (Stripe Price ids) = follow-up (zděděno z 1.8: ids ručně).
- `billing_interval` reuse názvu — jasný, ale sleduje jen zobrazení; paid-through dál v
  `trial_ends_at` (háček 1.8 nezměněn).
