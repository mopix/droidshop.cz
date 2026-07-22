# As-is — Vlna 1.7: Onboarding + platformní billing

- Datum: 2026-07-22
- Verze: 0.16.0
- Branch: `feat/wave-1.7-onboarding-billing`
- Spec: [`docs/superpowers/specs/2026-07-22-vlna-17-onboarding-billing-design.md`](../superpowers/specs/2026-07-22-vlna-17-onboarding-billing-design.md)
- Plán: [`docs/superpowers/plans/2026-07-22-faze-1-vlna-17-onboarding-billing.md`](../superpowers/plans/2026-07-22-faze-1-vlna-17-onboarding-billing.md)
- Testy: **966 passed / 0 failed** (3290 assertions)

## Co vlna dodává

Uzavírá self-service MVP: registrovaný uživatel platformy si průvodcem do 10 minut založí funkční e-shop na subdoméně s 14denním trialem; platforma řídí lifecycle nájemce (trial→past_due→suspended) a umí mu vystavit daňový doklad za předplatné. Reálné inkaso (Stripe) je připraveno kontraktem, implementuje se vlna 1.8.

## Mapa změněných částí

### Jádro — tenancy
- `app/Core/Tenancy/SubdomainName.php` — validace subdomény (RFC label 3–63, rezervované z configu, host = `{slug}.{platform}`).
- `app/Core/Tenancy/TenantProvisioner.php` — transakční založení tenanta (tenant + doména + owner + moduly tarifu + audit v `runAs`). Jediný recept; `DemoShopSeeder` ho volá.
- `app/Core/Tenancy/Exceptions/` — `InvalidSubdomain`, `SubdomainTaken`.

### Onboarding
- `app/Http/Controllers/Onboarding/SubdomainCheckController.php` — `GET /onboarding/subdomena/check` (`no-store, private`, auth).
- `app/Http/Controllers/Onboarding/OnboardingController.php` + `app/Http/Requests/Onboarding/CreateShopRequest.php` — wizard render + provision (server-side revalidace subdomény).
- `app/Http/Controllers/Onboarding/ShopEntryController.php` — signed cross-host auto-login (`onboarding.enter`).
- `app/Http/Controllers/DashboardController.php` + `app/Http/Controllers/Tenant/AdminHomeController.php` — „Moje e-shopy" a jádrová landing routa `admin.home`.
- `resources/js/Pages/Onboarding/Wizard.vue`, `resources/js/Pages/Dashboard.vue`.
- `RegisteredUserController::store` — redirect na `onboarding.create`.

### Trial lifecycle
- `config/billing.php` — `trial_days`, `grace_days`, `company`, `subscription.driver`, `vat_rate`, `invoice_prefix`.
- `app/Console/Commands/SweepTenantLifecycle.php` (`NotTenantAware`) + `routes/console.php` schedule daily.
- `app/Core/Billing/Mail/{TrialExpiredMail,ShopSuspendedMail}.php` + markdown views.

### Platformní ledger (netenantový)
- `database/migrations/*_create_platform_sequences_table.php`, `*_create_platform_invoices_table.php`.
- `app/Core/Billing/PlatformSequenceService.php` — gap-free netenantový čítač.
- `app/Core/Billing/Models/PlatformInvoice.php` — immutable, non-tenant.
- `app/Core/Billing/PlatformInvoiceWriter.php` — číslo + VAT split + snímky + idempotence per období + transakce + PDF (dompdf, `platform_private` disk).
- `app/Core/Billing/Contracts/SubscriptionGateway.php`, `NullSubscriptionGateway.php`, `Support/{SubscriptionCharge,ChargeResult}.php`, `SubscriptionActivator.php`, `Exceptions/{MissingBillingProfile,ChargeFailed}.php`.
- `app/Providers/BillingServiceProvider.php` (driver binding).
- `resources/views/billing/pdf/invoice.blade.php`.

### Fakturační profil nájemce + integrace
- `routes/tenant.php` (nová core admin route skupina) — registrována v `bootstrap/app.php`.
- `app/Http/Controllers/Tenant/{BillingProfileController,SubscriptionInvoiceController}.php` + `app/Http/Requests/Tenant/UpdateBillingProfileRequest.php`.
- `app/Http/Controllers/Platform/PlatformInvoiceDownloadController.php` + `TenantController::activateSubscription` (stavový guard) + route.
- `resources/js/Pages/Tenant/{BillingProfile,SubscriptionInvoices}.vue`.
- `app/Http/Middleware/HandleInertiaRequests.php` — sdílený prop `billingProfileComplete`; banner v `AdminLayout.vue`.
- `tests/Feature/Core/SchemaConventionTest.php` — allowlist `platform_invoices`/`platform_sequences`.

## Plnění spec

- **§3.1 (MVP „do 10 minut")** — onboarding flow hotový end-to-end (register → wizard → subdoména → admin).
- **§6.0 (onboarding wizard, lifecycle tenanta)** — wizard + `TenantProvisioner` + lifecycle scheduler.
- **§9 (tarify, trial, dunning)** — trial + config-driven grace + status automat; reálné inkaso design-for.
- **§16.6 (fakturace)** — platformní daňový doklad za předplatné (odlišný od tenant docs), fakturační identita nájemce.

## Testy

Nové sady: `SubdomainNameTest`, `TenantProvisionerTest`, `DemoShopSeederTest`, `SubdomainCheckTest`, `OnboardingStoreTest`, `OnboardingPageTest`, `ShopEntryTest`, `DashboardTest`, `AdminHomeTest`, `BillingConfigTest`, `SweepTenantLifecycleTest`, `PlatformSequenceServiceTest`, `PlatformInvoiceModelTest`, `PlatformInvoiceWriterTest`, `NullSubscriptionGatewayTest`, `SubscriptionActivatorTest`, `BillingProfileTest`, `ActivateSubscriptionTest`, `PlatformInvoiceDownloadTest`, `PlatformLedgerIsolationTest`, `BillingBannerTest`. Plus úpravy `RegistrationTest`.

Pokryto: transakčnost a rollback provisioningu, subdoména (formát/rezervace/kolize), server-side revalidace, signed cross-host login (5 security invariantů — podpis, TTL, membership 403, regenerate, server-side mint), lifecycle přechody + audit tenant_id + owner e-mail, gap-free číslování, VAT split (obě větve), idempotence per období, immutabilita dokladu, izolace ledgeru (nájemce A nevidí fakturu B, download cizí → 404), stavový guard aktivace.

## Odchylky od specifikace

1. **Platformní číslování přes `PlatformSequenceService`, ne `SequenceService`** (odchylka od návrhu specu sekce D). `SequenceService` je tenant-scoped a bez tenant kontextu vyhodí `MissingTenantContext`; platformní faktura je netenantová, proto vlastní netenantový čítač se stejným atomickým `LAST_INSERT_ID` trikem.
2. **`/admin` je jádrová landing routa `admin.home`**, ne přímý cíl. Spec/plán předpokládal existující `/admin`; ten neexistoval (modulové routy jsou `admin/m/{key}`), takže onboarding končil 404. Přidána core routa směrující na první položku `NavigationBuilder`, fallback `admin.billing.edit`.
3. **Trigger platformní faktury = úspěšný `SubscriptionGateway::charge()`** (null driver v 1.7). Měsíční billing scheduler design-for je defaultně vypnutý (`monthly_charge_enabled=false`) — bez reálné brány by generoval neuhrazené faktury donekonečna.

## Technický dluh a follow-upy

- **`SubscriptionActivator` není plně atomický:** commituje fakturu ve vlastní transakci a teprve pak mění stav mimo ni. U null driveru neškodí (žádné peníze); u reálné brány (1.8) by charge-success-then-issue-fail vzal peníze bez aktivace — vyřešit ve Stripe vlně.
- **`past_due` kotvené na `trial_ends_at`:** až přibude cesta `active→past_due` (zmeškaná platba, 1.8), potřebuje vlastní `past_due_at`, jinak dostane špatné načasování suspendu.
- **Platformní faktura PDF je synchronní best-effort** (`try/catch`→`report`, `pdf_path` může zůstat null). Queued regenerační job = follow-up.
- **Discoverability:** fakturační profil je dostupný jen přes banner, seznam „Faktury za předplatné" není v žádné admin nav (jádrové obrazovky nemají modulový manifest pro `NavigationBuilder`). Přidat core nav sekci.
- **`X-Robots-Tag: noindex`** chybí na platformních PDF odpovědích (docs precedent ho má) — defense-in-depth, PDF jsou za auth.
- **Netestované větve:** superadmin download routa, `ChargeFailed` větev activatoru (null driver nikdy neselže).
- **Právní:** platformní faktura je daňový doklad za předplatné (CZ náležitosti, DPH, DUZP) — kontrola náležitostí a vyplnění `config('billing.company')` před produkcí.

## Pre-deploy checklist (vlna 1.7)

- [ ] Vyplnit `config('billing.company')` (IČO/DIČ/adresa/plátcovství platformy) — teď placeholder.
- [ ] Rozhodnout `BILLING_VAT_RATE` a plátcovství platformy.
- [ ] Nastavit cron `schedule:run` na serveru (pro `billing:sweep-lifecycle`).
- [ ] Wildcard DNS + TLS `*.droidshop.cz` (subdomény nájemců, cross-host login).
- [ ] Ověřit signed cross-host login v reálném prostředí (za proxy — schéma `https`).
- [ ] Právní kontrola náležitostí platformní faktury.
