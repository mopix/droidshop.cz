# As-is — Vlastní domény nájemců + automatické TLS (vlna 2.1)

Datum: **2026-07-23** · Branch: `feat/wave-2.1-custom-domains` · Spec: [`docs/superpowers/specs/2026-07-23-vlna-21-custom-domains-design.md`](../superpowers/specs/2026-07-23-vlna-21-custom-domains-design.md) · Plán: [`docs/superpowers/plans/2026-07-23-vlna-21-custom-domains.md`](../superpowers/plans/2026-07-23-vlna-21-custom-domains.md)

## Co vlna přináší

Nájemce provozuje e-shop na **vlastní doméně** s automaticky vydaným TLS certifikátem (Caddy on-demand). Platforma ověří vlastnictví domény přes DNS (TXT challenge + routing), teprve pak autorizuje emisi certifikátu a začne doménu servírovat. Po vydání certu se custom doména stane kanonickou a subdoména 301 přesměruje na ni.

Tok stavů:
```
přidání (admin)  →  pending (DNS instrukce)  →  [verify: TXT+routing]  →  verified (ssl=pending)
   →  [Caddy on-demand vydá cert při 1. HTTPS]  →  [probe /up 200]  →  ssl=issued + custom primární + 301 ze subdomény
```

## Mapa kódu

### Jádro (`app/Core/Domains/`)
- `Contracts/DnsChecker.php` + `SystemDnsChecker.php` — abstrakce DNS (`dns_get_record` TXT/CNAME/A); testovací `tests/Support/FakeDnsChecker.php`.
- `DomainVerifier.php` — **jediná autorita, která nastaví `verified_at`.** Ověří TXT challenge token na `_droidshop-challenge.<doména>` **a** routing (CNAME dot-anchored na `edge_host` NEBO A obsahuje `server_ip`). Úspěch → `ssl_status=pending`, `verified_at`, `forget(host)`, audit `domain.verified`. Běží v `runAs` (audit tenant_id).
- `DomainCertProbe.php` + `Jobs/ProbeDomainCertJob.php` — HTTPS probe `https://<doména>/up`; 200 → `ssl_status=issued` (atomicky s canonical swap v jedné `DB::transaction`); jinak bounded retry přes odložený tenant-aware job (`cert_probe_max_attempts`, backoff `dns_backoff_minutes`); na `sync` driveru se retry neplánuje.
- `CanonicalDomain.php` — `promote()` (custom→`is_primary`, ostatní domény tenanta ztratí primární, jedna transakce, idempotentní no-op při opakování) + `canonicalHostFor(Tenant)`.

### Tenancy (`app/Core/Tenancy/`)
- `DomainTenantFinder.php` — **gating:** neověřená `type=custom` doména se neresolvuje na tenanta (grouped-OR query: subdomény bez podmínky, custom jen `verified_at IS NOT NULL`). `forget(host)` na každé změně stavu je load-bearing (verify/promote/cert/delete).

### HTTP
- `app/Http/Controllers/Internal/TlsCheckController.php` + `routes/internal.php` — Caddy ask endpoint `GET /internal/tls-check?token=…&domain=…`. Autorizuje emisi jen pro verified+`type=Custom` domény tenantů s `allowsStorefront()`. **Shared-secret token** (`hash_equals`, fail-closed na prázdný config) + middleware `AllowLocalOnly` (127.0.0.1/::1) jako obrana do hloubky. Výsledek cachován (bool, `tls_check_ttl`).
- `app/Http/Middleware/RedirectToCanonicalHost.php` — 301 subdoména→custom pro storefront GET/HEAD; vyloučeny `admin`/`soubory`/`onboarding`/`impersonace` a non-GET; custom neredirectuje sám na sebe; platform host projde. Location vždy z DB (ne z requestu), vždy `https`.
- `app/Http/Controllers/Tenant/DomainController.php` + `AddCustomDomainRequest.php` + `resources/js/Pages/Tenant/Domain.vue` — admin obrazovka `/admin/nastaveni/domena` (přidat/ověřit/smazat, DNS instrukce s tokenem, stavový badge, potvrzovací dialog). Limit 1 custom doména/tenant. Audit `domain.added`/`domain.removed`/`domain.cert_recheck`.

### Command
- `app/Console/Commands/SweepPendingDomains.php` — `domains:sweep-pending` (hodinově, `routes/console.php`). Group A: neověřené → verify (DNS chyby auto-retry, expirované >`pending_ttl_hours` → error **jednou**). Group B: verified+`ssl=pending` → probe (cert-error je terminální, jen ruční re-trigger).

### Data + config
- Migrace `2026_07_23_102153_add_verification_to_domains_table.php` — alter na nasazenou `domains`: `challenge_token`, `verification_error`, `last_checked_at` (vše nullable).
- `config/platform.php` — `server_ip`, `edge_host`, `challenge_prefix`, `cert_probe_max_attempts`, `pending_ttl_hours`, `dns_backoff_minutes`, `tls_check_ttl`, `tls_check_token`.

## Plnění spec (§ dle spec vlny 2.1)
- Ověření vlastnictví DNS (TXT + routing) — **hotovo** (`DomainVerifier`).
- Automatická emise TLS přes Caddy on-demand + ask endpoint — **hotovo** (aplikační strana; infra runbook níže).
- Gating neověřených domén v resolveru — **hotovo** (`DomainTenantFinder`).
- Canonical host + 301 subdoména→custom — **hotovo** (`CanonicalDomain` + `RedirectToCanonicalHost`).
- Stavové admin UI + DNS instrukce — **hotovo** (`DomainController` + `Domain.vue`).
- Sweep/retry lifecycle — **hotovo** (`domains:sweep-pending`).

## Testy
1096 testů celkem (3619 assertions), zeleně. Nové sady:
`DomainVerifierTest`, `DnsChecker`/`FakeDnsCheckerTest`, `DomainTenantFinderGatingTest`, `TlsCheckTest`, `DomainCertProbeTest`, `CanonicalDomainTest`, `CanonicalRedirectTest`, `SweepPendingDomainsTest`, `DomainControllerTest`. TDD s deterministickým `FakeDnsChecker` + `Http::fake()`/`Queue::fake()`. Adversariální testy: CNAME suffix-boundary bypass, promote-rollback, timeout-fires-once, missing/wrong tls-check token.

## Odchylky a vědomé kompromisy
1. **`ssl_status` enum beze změny** (None/Pending/Issued/Error) — žádný nový case; „verified ale bez certu" = `verified_at != null` + `ssl=pending`.
2. **`verified_at` je jediná gate downstream**, ne `ssl=issued`. `issued` je best-effort informativní (detekován probe, může 60s lagovat za realitou).
3. **Ownership gate na `verified_at`, cert probe jen informuje** — pořadí probe vs. live provoz nevadí.
4. **DNS za kontraktem `DnsChecker`** — `dns_get_record` nejede v testech ani deterministicky; produkce `SystemDnsChecker`, testy `FakeDnsChecker`.
5. **`sync` driver:** odložený cert-probe retry by běžel hned → na sync se neplánuje; sweep + ruční „Ověřit teď" jsou náhradní cesta. Produkce jede Redis.
6. **Limit 1 custom doména/tenant** vynucen app-level (FormRequest `exists()`), bez DB constraintu — souběžný double-submit s různými řetězci by teoreticky vložil dvě řady (self-inflicted, same-tenant, low impact). Partial unique index na `type=custom` je cross-DB křehký; revidovatelné.
7. **301 subdoména→custom je permanentní** — pokud nájemce custom doménu později smaže, návštěvníci s nacachovaným 301 míří na mrtvý host, dokud jim redirect nevyexpiruje z cache. Inherentní vlastnost kanonizace 301; přijato.

## Mimo vlnu (YAGNI)
Víc custom domén/tenant, wildcard cert per tenant, DNS-01 challenge, vlastní e-mailová doména nájemce, přesun adminu na custom doménu.

## Pre-deploy runbook (infra, mimo app kód)

### Caddy
```
{
  on_demand_tls {
    ask http://127.0.0.1:<app-port>/internal/tls-check?token=<PLATFORM_TLS_CHECK_TOKEN>
  }
}

# Subdomény platformy = wildcard cert (DNS-01), NE on-demand:
*.droidshop.cz { tls { dns <provider> } ... }

# Custom domény = on-demand (jen ověřené projdou ask):
https:// {
  tls { on_demand }
  reverse_proxy 127.0.0.1:<app-port>
}
```
- **Interní endpoint chraň:** Caddyfile musí explicitně **zamítnout veřejný `/internal/*`** (např. `@internal path /internal/*` → `respond 404`), nebo servírovat `routes/internal.php` na dedikovaném loopback-only listeneru. Za reverse proxy je `REMOTE_ADDR` vždy `127.0.0.1`, takže `AllowLocalOnly` sám nestačí — primární obrana je shared-secret token + neexpozice cesty.

### DNS / env
- `edge.droidshop.cz` A → VPS IP (cíl CNAME custom domén nájemců).
- Wildcard `*.droidshop.cz` + TLS (DNS-01) pro subdomény.
- `.env` (produkce): `PLATFORM_SERVER_IP`, `PLATFORM_EDGE_HOST=edge.droidshop.cz`, **`PLATFORM_TLS_CHECK_TOKEN`** (silný náhodný, stejný jako v Caddyfile ask URL).
- Scheduler: `domains:sweep-pending` běží hodinově (potřebuje `schedule:run` cron).

### Nájemce nastaví u své domény
- TXT `_droidshop-challenge.<doména>` = challenge token (z admin obrazovky).
- CNAME `<doména>` → `edge.droidshop.cz` (nebo apex: A → `PLATFORM_SERVER_IP`).
