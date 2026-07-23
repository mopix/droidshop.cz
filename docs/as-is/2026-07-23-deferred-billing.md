# As-is — Deferred billing (roční interval + upgrade/downgrade tarifu)

- **Datum:** 2026-07-23
- **Vlna:** 1.9 (post-1.8 billing rozšíření)
- **Branch:** `feat/wave-1.9-deferred-billing`
- **Spec:** `docs/superpowers/specs/2026-07-23-vlna-19-deferred-billing-design.md`
- **Plán:** `docs/superpowers/plans/2026-07-23-vlna-19-deferred-billing.md`

## Co se změnilo (mapa)

| Oblast | Soubor | Změna |
|--------|--------|-------|
| Ceník cen | `database/migrations/..._create_plan_prices_table.php`, `app/Models/PlanPrice.php` | Nová netenantová tabulka `plan_prices` (plan × interval → Stripe price id + částka). Data migrace zkopírovala měsíční cenu. |
| Plan | `app/Models/Plan.php` | `prices()` HasMany, `priceFor(BillingInterval)`. `plans.stripe_price_id` **zrušen**. |
| Interval | `app/Core/Billing/Enums/BillingInterval.php` | Enum Month/Year. |
| Checkout | `SubscriptionGateway::startCheckout(+BillingInterval)`, `StripeSubscriptionGateway`, `NullSubscriptionGateway`, `SubscriptionController::checkout` | Checkout resolvne price přes `plan_prices` pro zvolený interval; controller čte+validuje `interval` (fallback Month). |
| UI | `resources/js/Pages/Tenant/Subscription.vue`, `SubscriptionController::show` | Přepínač měsíc/rok (accessible radio `fieldset`/`legend`), obě ceny prop `prices`. |
| Tenant | `database/migrations/..._add_billing_interval_to_tenants_table.php` | `tenants.billing_interval` (nullable string). |
| Doklad | `database/migrations/..._add_stripe_invoice_id_to_platform_invoices_table.php`, `SubscriptionCharge`, `PlatformInvoiceWriter` | Idempotence **per Stripe invoice id** (unique). `SubscriptionCharge` +`stripeInvoiceId`,+`grossTotal`. Writer bere `grossTotal` místo `plan->price_month`. |
| Webhook `invoice.paid` | `StripeWebhookHandler::onInvoicePaid` | Částka + tarif + interval **z faktury** (`amount_paid`, line `price.id` → `plan_prices`), ne z `tenant->plan`. Guard `amount_paid==0` → žádný doklad. Aktualizuje `plan_id`/`billing_interval`. |
| Rekonciliace | `app/Core/Billing/TenantPlanSwitcher.php` | `switchTo(Tenant, Plan, BillingInterval)`: repoint `plan_id`/`billing_interval` + srovnání modulů (aktivuj nové, deaktivuj old-only). |
| Webhook `subscription.updated` | `StripeWebhookHandler::onSubscriptionUpdated` | Nový handler: `items.data[0].price.id` → `plan_prices` → plan+interval → `TenantPlanSwitcher`. Registrace v `match`. |

## Plnění spec (design doc)

| Požadavek | Stav |
|-----------|------|
| Roční interval (výběr při zakládání) | hotovo (Task 1–3) |
| Upgrade/downgrade base↔premium přes Billing Portal | hotovo (Task 7–8; Portal konfigurace = deploy) |
| Rekonciliace modulů při změně tarifu | hotovo (Task 7) |
| Český doklad na proration/roční, idempotence per invoice id | hotovo (Task 5–6) |
| `plans.stripe_price_id` zrušen | hotovo (Task 2) |
| `tenants.billing_interval` | hotovo (Task 4) |
| Guard `amount_paid==0` | hotovo (Task 6) |

## Testy

Běží (cílené `--filter`, zeleně):
- `PlanPriceTest` — mapování plan×interval, `priceFor`.
- `StripeSubscriptionGatewayTest` — checkout vybere správný Stripe price per interval (reálná aserce na `line_items[0].price`).
- `SubscriptionPageTest` — prop `prices` obou intervalů.
- `PlatformInvoiceWriterTest` — `grossTotal` (ne cena tarifu), idempotence per `stripe_invoice_id`.
- `StripeWebhookHandlerTest` — `invoice.paid` částka z faktury; guard 0; `subscription.updated` přepne plan_id + rekonciliace modulů; unknown price = no-op.
- `TenantPlanSwitcherTest` — upgrade aktivuje, downgrade deaktivuje premium-only modul, base zůstává, `billing_interval`.
- `SchemaConventionTest`, `StripeSchemaTest` — allowlist `plan_prices`, sloupce.

Chybí / neověřeno:
- Reálná Stripe volání (Checkout/Portal/webhook) bez test-mode klíčů — deploy smoke test.
- E2E přepnutí tarifu v Portalu → proration faktura → český doklad (vyžaduje živý Stripe).

## Odchylky od specifikace

1. **Idempotence platformního dokladu: per-období → per Stripe invoice id.** Rozhodnutí 1.7/1.8 klíčovalo `(billed_tenant_id, period_from, period_to)`; proration faktura má překryv období a per-období klíč by ji zahodil (nájemce by nedostal daňový doklad na doúčtovanou částku). Sloupec `platform_invoices.stripe_invoice_id` (unique) je nový klíč; `period_from/to` zůstávají jako data.
2. **`plans.stripe_price_id` zrušen** ve prospěch `plan_prices` (jeden price id nestačí na 2 intervaly).
3. **`invoice.paid` přepisuje `plan_id`/`billing_interval`** z ceny faktury pokaždé (dřív se `plan_id` u `invoice.paid` neměnil). Stripe je autorita o tarifu; žádný test na race s admin-override (viz tech dluh).

## Technický dluh / pre-deploy

- **Stripe Billing Portal konfigurace ruční** (Dashboard): zapnout „switch plans" se 4 cenami (base/premium × month/year), proration = „Prorate changes"; configuration id do `config('billing.stripe.portal_config')` přes `.env.local`.
- **4 Stripe Price objekty** vytvořit v Dashboardu, jejich id zapsat do `plan_prices` (ručně/seed; superadmin edit = follow-up — zděděno z 1.8, kde se Price ids plnily ručně).
- **`customer.subscription.updated`** povolit jako doručovaný event na Stripe webhook endpointu (v repu žádný allowlist, handler filtruje `match`).
- **Downgrade kredit** (`amount_paid==0`) → český doklad nevystavíme; ověřit s účetní, zda kreditní zůstatek nepotřebuje samostatný doklad (§29 ZDPH).
- **`invoice.paid` vs admin-override tarifu** — žádný test, že ručně nastavený plán přežije nesouvisející paid webhook. Řešit, až přibudou admin plan-change flow racující s webhooky.
- **`ModuleRegistry::guardPlan` fragilita** — čte živou relaci `$tenant->plan`; kterýkoli volající, co zmutuje `plan_id` na už-načteném tenantovi, musí `unsetRelation('plan')` (řeší `TenantPlanSwitcher`). Kandidát na tvrdší kontrakt.
- **`billing_interval` reuse `trial_ends_at`** jako paid-through nezměněn (háček 1.8 platí dál).
