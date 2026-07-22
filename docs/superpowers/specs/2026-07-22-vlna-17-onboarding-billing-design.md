# Vlna 1.7 — Onboarding + platformní billing (design/spec)

- Datum: 2026-07-22
- Stav: schváleno (brainstorming), čeká na plán
- Navazuje na: produktová spec §3.1 (MVP „do 10 minut"), §6.0 (tenancy & účty, onboarding wizard), §5.2 (lifecycle modulu), §9/§13 (tarify, ceny)
- Rozsah billingu (rozhodnutí): **trial lifecycle + platforma fakturuje nájemci** (PDF, číslování). Reálná platba = design-for.

## Cíl

Uzavřít self-service MVP: registrovaný uživatel platformy si průvodcem založí funkční e-shop na subdoméně, dostane 14denní trial, a platforma umí nájemci vystavit daňový doklad za předplatné. Reálné inkaso (Stripe) je připraveno kontraktem, ale implementuje se až vlna 1.8.

## Co je MIMO tuto vlnu (design-for / future)

- **Reálná platba za předplatné (Stripe)** — vlna 1.8. 1.7 staví seam `SubscriptionGateway`; 1.8 = jen driver + webhook, bez zásahu do onboardingu/scheduleru/ledgeru.
- **Vlastní doména nájemce** — fáze 2. Datový model připraven (`domains.type=custom`, `ssl_status`, `DomainTenantFinder` řeší libovolný host). Chybí ověření vlastnictví (DNS TXT/CNAME), automatická emise TLS, stavové UI, 301/canonical. Netriviální infra (DNS + certifikáty na VPS) → samostatná vlna.
- **ARES autofill** fakturačních údajů — jen hook, plná integrace post-MVP.
- Proration při upgrade/downgrade — spec §9 zjednodušení „od dalšího období"; řeší se se Stripe.

## Klíčová rozhodnutí (z brainstormingu)

1. Billing rozsah = trial lifecycle + platformní faktura, bez brány.
2. Onboarding flow = registrace → wizard → shop; dashboard „Založit e-shop" (1 user vlastní N shopů).
3. Trial = **config** `config/billing.php` (`trial_days=14`, `grace_days=7`), automat `trial→past_due→suspended`.
4. Platformní faktura = **samostatný netenantový ledger** (`app/Core/Billing/`), docs 1.6 se nešahá.
5. Trigger platformní faktury = na úspěšném `SubscriptionGateway::charge()` (null driver v 1.7).
6. Přidává se **tenant admin obrazovka fakturačních údajů** — bez ní nefunguje ani docs snímek dodavatele.

## Architektura po komponentách

### A) Tenant provisioning — `App\Core\Tenancy\TenantProvisioner`

Zabalí dnešní recept z `DemoShopSeeder` do jedné transakce:

- vytvoří `Tenant` (`status=trial`, `trial_ends_at = now()->addDays(config trial_days)`, `plan_id`)
- vytvoří `Domain` (`type=subdomain`, `is_primary=true`) po validaci subdomény
- attach owner do `tenant_users` (`role=owner`, `joined_at`)
- aktivuje výchozí moduly tarifu přes `ModuleRegistry::activate($tenant,$key)` (dependency pull-in tarif už řeší `guardPlan`)
- zapíše `AuditLog` (`tenant.provisioned`)

Validace subdomény (server-side, autorita):
- formát: `^[a-z0-9]([a-z0-9-]{1,61}[a-z0-9])?$` (RFC label, 3–63 znaků, bez leading/trailing pomlčky)
- není v `config('tenancy.reserved_subdomains')`
- není obsazená (`domains.domain` unique) — kolize řeší DB unique + čitelná chyba
- výsledný host = `{slug}.{platform_domain}`

`DemoShopSeeder` se přepíše, aby volal `TenantProvisioner` (jeden zdroj pravdy pro založení tenanta).

**Výchozí moduly** provisioningu: seznam z tarifu, nebo pevná MVP sada (`categories, products, shipping, customers, checkout, orders, payments, docs, storefront`). Rozhodne se v plánu — preferováno „moduly z `plan_modules`", aby tarif řídil rozsah.

### B) Onboarding wizard (Inertia SPA, `noindex`)

- Po `register` redirect na `/onboarding` (místo dnešního `/dashboard`).
- Oblast admin → Inertia (pravidlo `storefront-rendering.md`: SPA jen admin/onboarding, `noindex,nofollow`).
- Kroky (multi-step, jedna stránka s progresivními kroky nebo 3 routy — detail v plánu):
  1. **Název + subdoména** — live availability check `GET /onboarding/subdomena/check?slug=` → JSON `{available: bool, reason?}`, hlavička `Cache-Control: private, no-store`. Server při submitu revaliduje (nikdy nevěří klientu).
  2. **Tarif** — výběr z `plans.where(is_public)`; trial startuje, žádná platba.
  3. **Hotovo** → redirect na tenant admin `https://{slug}.{platform}/admin` (přihlášení owner session — viz pozn. cross-domain níže).
- Bez JS fallback: wizard je admin (SPA povoleno), ale kroky musí projít i klasickým POST (žádná cenová/veřejná logika). Availability check má server-side ekvivalent při submitu.

Platform dashboard (`/dashboard` → přejmenovat účelově na „Moje e-shopy"):
- seznam e-shopů uživatele (`$user->tenants`) s odkazem do adminu
- `[+ Založit e-shop]` spustí stejný wizard

**Pozn. cross-domain session:** owner se registruje na platform hostu (`droidshop.cz`), admin běží na `{slug}.droidshop.cz`. `SESSION_DOMAIN=null` (host-only cookie, odchylka STATUS.md #1) znamená, že session z platform hostu neplatí na subdoméně. Přechod do adminu po wizardu proto potřebuje **signed auto-login URL** na cílový host (vzor jako `impersonation.begin` — `signed` route mintovaná serverem). Detail mechanismu = plán.

### C) Trial lifecycle scheduler

- `config/billing.php`: `trial_days` (14), `grace_days` (7), `company` blok (dodavatel na platformní faktuře).
- Command `billing:sweep-lifecycle` — implementuje `NotTenantAware` (jinak tenant-aware fronta job zahodí, STATUS.md).
  - `trial` && `trial_ends_at < now` → `changeStatus(PastDue, 'trial expired')`; storefront běží dál (spec odchylka §2); e-mail nájemci.
  - `past_due` && `trial_ends_at->addDays(grace_days) < now` → `changeStatus(Suspended, 'grace expired')`; storefront + admin-write stop; e-mail.
- Schedule v `routes/console.php` daily.
- `Tenant::changeStatus()` zapisuje audit, ale **nemá e-mail hook** — scheduler pošle e-mail sám přes `MailService` (`MailKind::Transactional`).
- Idempotence: přechod `from==to` je no-op (už v `changeStatus`). Sweep bere jen tenanty v příslušném stavu (indexováno `['status','trial_ends_at']`).

### D) Platformní faktura — samostatný ledger `app/Core/Billing/`

Netenantový (platforma fakturuje nájemci). NEsdílí modul `docs`.

- Migrace `platform_invoices`:
  - `id`, `number` (unique per rok+typ), `billed_tenant_id` (odběratel, FK `tenants`, `restrictOnDelete`)
  - snapshot odběratele (`customer_name/ico/dic/address` z `tenant.billing_*` v okamžiku vystavení)
  - snapshot dodavatele NENÍ v řádku — bere se z `config('billing.company')` při renderu (naše identita je stabilní; alternativa = snímek, rozhodne plán). **Doporučeno snímkovat** pro doložitelnost historie (stejný princip jako docs snímek dodavatele).
  - částky v haléřích (`unsignedBigInteger`), `vat_rate`, `vat_summary` (JSON), `total`
  - `period_from/period_to` (fakturované období předplatného), `plan_key`, `issued_at`, `taxable_at` (DUZP)
  - `pdf_path` (platform-private disk)
- `PlatformInvoiceWriter`:
  - číslo přes `SequenceService::nextNumber()` řada `platform_invoices:{YYYY}` → formát `PF{YYYY}{NNNN}` přes `App\Core\Documents\DocumentNumber`
  - immutable insert (update jen `pdf_path`), idempotence přes unique + retry (vzor `DocumentWriter`)
  - PDF vlastní blade (`resources/views/billing/pdf/invoice.blade.php`) + `barryvdh/laravel-dompdf`
  - uložení přes `FileStorage` na platform-private disk
- Stažení:
  - superadmin (platform host, `auth:platform`+2FA)
  - nájemce ve svém adminu „Faktury za předplatné" (`tenant.member` owner) — vidí jen faktury svého tenanta (`billed_tenant_id` = current tenant), cizí = 404 bez leaku
- **Trigger:** `SubscriptionGateway` kontrakt (`app/Core/Billing/Contracts/`):
  - `charge(Tenant $t, Plan $p, Period $period): ChargeResult`
  - `NullSubscriptionGateway` — dev auto-success, žádné reálné inkaso
  - na úspěšném `charge()` → `PlatformInvoiceWriter::issue()` + posun stavu tenanta na `active` + prodloužení období
  - v 1.7 charge spouští: (a) superadmin akce „aktivovat předplatné / označit zaplaceno" na kartě tenanta, (b) design-for měsíční billing scheduler pro `active` tenanty (může být za feature flagem / vypnuto v 1.7)
  - **verify-before-trust** připraveno pro Stripe driver (1.8): stav `paid` jen po ověření u brány, ne z návratu

### E) Fakturační údaje nájemce — nová tenant admin obrazovka

- Route `/admin/nastaveni/fakturace` (Inertia, `module:? ` → spíš jádrová admin sekce; právo owner). Pole: `billing_name`, `billing_ico` (16), `billing_dic` (16), `billing_address` (JSON), `vat_payer` (bool).
- Validace: IČO formát (8 číslic + checksum volitelně), DIČ formát `CZ########`, adresa povinná pro plátce.
- Slouží dvěma pánům: dodavatel na fakturách nájemce (docs snapshot) + odběratel na naší platformní faktuře.
- Soft-gate: banner v adminu „Doplňte fakturační údaje" dokud prázdné; `charge()` je bez nich odmítne (nelze vystavit fakturu bez odběratele).
- ARES autofill = ostrůvek, jen připravit hook (post-MVP).

### F) Testy

- `TenantProvisioner`: transakčnost (rollback při chybě), kolize subdomény, rezervované jméno, formát slug, aktivace modulů tarifu.
- Wizard: feature testy (Inertia asserty), availability endpoint (dostupné/obsazené/rezervované), server-side revalidace, redirect do adminu, signed auto-login.
- Scheduler: `trial→past_due` a `past_due→suspended` na čase (`Carbon::setTestNow` / travel), e-maily odeslány, idempotence opakovaného běhu.
- `PlatformInvoiceWriter`: číslování per rok, immutability, snímek odběratele, idempotence.
- `SubscriptionGateway` null driver: charge → faktura + status active; charge bez billing údajů → odmítnuto.
- **Tenant izolace:** platform ledger nesmí protéct do tenant globálního scope; nájemce A nevidí platformní fakturu nájemce B; owner vidí jen faktury svého tenanta.

## Datové změny (souhrn)

- `config/billing.php` (nový): `trial_days`, `grace_days`, `company` (dodavatel), `subscription.driver` (null|stripe).
- Migrace `platform_invoices` (netenantová).
- `sequences` — nová série `platform_invoices:{YYYY}` (žádná migrace, řádek vzniká za běhu).
- Bez změny `tenants`/`domains`/`plans` schématu (sloupce už existují: `trial_ends_at`, `billing_*`, `domains.type/ssl_status`).

## Odchylky a rizika

- **Cross-domain auto-login po wizardu** — kvůli `SESSION_DOMAIN=null` nutná signed URL na cílový host. Zapsat do rozhodnutí (nová třída rizika: platform→tenant přechod session).
- **Platformní faktura = daňový doklad za předplatné** — musí splnit CZ náležitosti (DPH, DUZP). My jsme u předplatného zprostředkovatel/plátce (jiná role než u tenant plateb, kde zprostředkovatel NEjsme). Právní kontrola náležitostí před produkcí.
- **Design-for měsíční billing scheduler** bez reálné platby by generoval neuhrazené faktury donekonečna — v 1.7 defaultně vypnutý / jen manuální charge superadminem. Zapnutí = až se Stripe (1.8).
- LIKE/scale a page-cache dluhy z předchozích vln se nevlny 1.7 netýkají.

## Next wave

- **1.8 Stripe subscription** — `StripeSubscriptionGateway` driver kontraktu `SubscriptionGateway`, webhooky, verify-before-trust, dunning z platby (scheduler ustoupí webhookům jako autoritě), Stripe trial vs náš trial (rozhodne se tam). Platformní ledger (D) zůstává — Stripe je jen inkasní kanál.
