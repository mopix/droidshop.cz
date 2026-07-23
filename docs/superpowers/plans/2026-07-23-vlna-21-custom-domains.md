# Vlna 2.1 — Vlastní domény nájemců + automatické TLS — implementační plán

> **Pro agenta:** Použij superpowers:executing-plans nebo subagent-driven-development. Kroky s `- [ ]`. TDD: test → implementace → test → commit.

**Cíl:** Nájemce provozuje e-shop na vlastní doméně s automaticky vydaným TLS (Caddy on-demand); platforma ověří vlastnictví přes DNS, autorizuje emisi certu a servíruje jen ověřené domény; subdoména 301 na primární vlastní doménu.

**Architektura:** Verifikace DNS za kontraktem `DnsChecker` (deterministické testy). `DomainVerifier` ověří TXT token + routing (CNAME→edge / A→server_ip), nastaví `verified_at`. `DomainCertProbe` (retry job) detekuje vydaný cert. Ask endpoint (`/internal/tls-check`, jen localhost) autorizuje Caddy emisi jen pro verified+aktivní domény. Gating v `DomainTenantFinder`: neověřená custom se neresolvuje. Sweep command žene pending/error/verified. 301 subdoména→custom přes storefront middleware. Admin obrazovka v core `routes/tenant.php` (vzor billing profile).

**Tech stack:** Laravel 13 + Inertia/Vue (admin), viz `docs/PROJECT-PROFILE.md`.

**Spec:** `docs/superpowers/specs/2026-07-23-vlna-21-custom-domains-design.md`

---

## Předpoklady z exploru (load-bearing)
- `Domain` model `app/Models/Domain.php`; migrace `2026_07_19_164712_create_domains_table.php` (sloupce: tenant_id, domain unique, type, is_primary, ssl_status, verified_at).
- Enumy `app/Core/Enums/DomainType.php`, `SslStatus.php` (None/Pending/Issued/Error — beze změny, žádný nový case).
- `DomainTenantFinder::find` (`app/Core/Tenancy/DomainTenantFinder.php:23`) — Cache::remember host→tenant_id; `forget()` (:67) existuje, dosud nevolaný.
- Callers finderu: `ResolveHost` middleware (:33) + `RedirectResponder` (:37).
- `CheckTenantStatus` (`app/Http/Middleware/CheckTenantStatus.php`) — status gating platí dál.
- Vzor core admin obrazovky: `BillingProfileController` + `routes/tenant.php:15` + `resources/js/Pages/Tenant/BillingProfile.vue`.
- Vzor sweep commandu: `SweepTenantLifecycle` (NotTenantAware + `runAs`) + scheduler `routes/console.php:11`.
- `RedirectResponder` (`app/Core/Routing/RedirectResponder.php`) — 301/410 z NotFoundHttpException; 301 canonical host řešíme separátně (redirect běží na živé routě, ne na 404).

---

## Kroky

### 1. Config + migrace (základ)
- [ ] `config/platform.php` (nový): `server_ip` (env `PLATFORM_SERVER_IP`), `edge_host` (env `PLATFORM_EDGE_HOST`, default `edge.droidshop.cz`), `challenge_prefix` (default `_droidshop-challenge`), `cert_probe_max_attempts`, `pending_ttl_hours`, `dns_backoff_minutes`. Doplnit `.env.example`.
- [ ] Migrace `..._add_verification_to_domains_table.php`: `challenge_token` (string nullable), `verification_error` (string nullable), `last_checked_at` (timestamp nullable). Alter na nasazenou tabulku.
- [ ] `Domain` model: přidat sloupce do `$fillable`/`$casts` (`last_checked_at` datetime); helper `isCustom()`, `isVerified()` (`verified_at !== null`).
- [ ] Ověř `SchemaConventionTest` (domains je tenant-scoped? finder queruje bez kontextu — potvrdit, že Domain **nemá** `BelongsToTenant` global scope nebo že allowlist sedí). Test: `php artisan test --filter Schema`.
- [ ] Commit: `feat(domains): add verification columns + platform config`

### 2. DnsChecker kontrakt
- [ ] `app/Core/Domains/Contracts/DnsChecker.php`: `txt(string $host): array`, `cname(string $host): ?string`, `a(string $host): array`.
- [ ] `app/Core/Domains/SystemDnsChecker.php` nad `dns_get_record` (TXT/CNAME/A).
- [ ] `tests/.../FakeDnsChecker.php` (deterministický, nastavitelné odpovědi).
- [ ] Bind v service provideru (`AppServiceProvider` nebo nový `DomainsServiceProvider`): default `SystemDnsChecker`.
- [ ] Commit: `feat(domains): DnsChecker contract + system/fake impl`

### 3. DomainVerifier (TDD — jádro bezpečnosti)
- [ ] Test `DomainVerifierTest` (FakeDnsChecker):
  - TXT match + CNAME končí `edge_host` → `verified_at` set, `ssl_status=pending`, `verification_error=null`, `forget(host)` volán.
  - TXT match + A obsahuje `server_ip` (apex) → verified.
  - TXT chybí/nesedí → `ssl_status=error`, `verification_error` set, `verified_at` zůstává null.
  - Routing míří jinam (CNAME jiný / A jiná IP) → error.
  - Idempotence: druhý verify na už verified nezmění stav destruktivně.
- [ ] `app/Core/Domains/DomainVerifier.php`: `verify(Domain $domain): void` — přes `DnsChecker`, `challenge_prefix.<doména>`, běží v `runAs($tenant)` (audit `domain.verified`/`domain.verification_failed`), `last_checked_at=now()`, na úspěch `DomainTenantFinder::forget(host)`.
- [ ] Commit: `feat(domains): DomainVerifier (TXT+routing ownership check)`

### 4. Gating v DomainTenantFinder (TDD — díra)
- [ ] Test: neověřená `type=custom` doména → `find()` vrátí null (nebo se v query nematchne); subdoména beze změny; verified custom → resolvuje.
- [ ] Uprav `DomainTenantFinder` query: custom domény jen `whereNotNull('verified_at')`; subdomény bez podmínky. (Cache klíč beze změny, ale hodnota respektuje gating — proto `forget` na verify povinný.)
- [ ] Test `ResolveHost`/404: neověřená custom přes HTTP → 404.
- [ ] Commit: `fix(domains): gate unverified custom domains in tenant resolution`

### 5. Ask endpoint (TDD — bezpečnostní jádro)
- [ ] Test `TlsCheckTest`: 200 pro verified+aktivní tenant; 404 pro neověřenou; 404 pro suspended/deleted tenant; odmítne non-localhost (nebo dokumentuj firewall/bind); výsledek cachovaný (krátké TTL).
- [ ] Route `GET /internal/tls-check` (nová `routes/internal.php` nebo do `platform.php`) — **mimo** web/tenant middleware, bez CSRF, bez session; middleware/guard na `127.0.0.1`.
- [ ] `app/Http/Controllers/Internal/TlsCheckController.php`: `Domain` s hostem, `verified_at != null`, tenant status aktivní (ne suspended/pendingdeletion/deleted — reuse `TenantStatus::allowsStorefront()`); jinak 404. Cache krátké TTL (`config('platform.tls_check_ttl', 60)`).
- [ ] Commit: `feat(domains): Caddy ask endpoint /internal/tls-check (localhost, verified-only)`

### 6. DomainCertProbe (retry job)
- [ ] Test `DomainCertProbeTest`: probe úspěch (fake HTTP `/up` 200) → `ssl_status=issued`; opakované selhání → po N pokusech `error`; přechod na issued spustí canonical swap (krok 7).
- [ ] `app/Core/Domains/DomainCertProbe.php`: HTTPS probe `https://<doména>/up` přes `Http` fasádu; retry přes odložený dispatch (delay z configu), max attempts. Tenant-aware queue.
- [ ] Commit: `feat(domains): DomainCertProbe (HTTPS probe → issued, retry/backoff)`

### 7. Canonical host swap + 301 (TDD)
- [ ] Test: přechod na `issued` → custom `is_primary=true`, subdoména `is_primary=false` (v jedné transakci, právě jedna primární); `forget` obou hostů.
- [ ] Test 301: tenant s issued custom primární → storefront request na subdoméně 301 na `https://<custom><path>` (stejná cesta, query zachována); **admin cesta neredirectuje**; custom host neredirectuje sám na sebe.
- [ ] Swap logika v `DomainCertProbe` / dedikovaná `CanonicalDomain` služba.
- [ ] 301 přes storefront middleware (běží na živé routě, ne 404): nový `RedirectToCanonicalHost` middleware v `web`/storefront skupině, admin výjimka. Ověř interakci s page cache (redirect nesmí být cachnutý pod klíčem katalogu).
- [ ] Commit: `feat(domains): canonical host swap + 301 subdomain→custom`

### 8. Sweep command
- [ ] Test `SweepPendingDomainsTest`: pending → verify; verified bez issued → probe; expirovaný pending (>ttl) → error „DNS nenastaveno"; backoff dle `last_checked_at` (čerstvě checknutá se přeskočí).
- [ ] `app/Console/Commands/SweepPendingDomains.php` (`domains:sweep-pending`, NotTenantAware), `runAs` per tenant.
- [ ] Scheduler `routes/console.php`: `->hourly()` (nebo dle configu).
- [ ] Commit: `feat(domains): domains:sweep-pending command + schedule`

### 9. Admin UI (vzor BillingProfile)
- [ ] Route `routes/tenant.php`: GET `/admin/nastaveni/domena` (`admin.domain.edit`), POST přidat (`admin.domain.store`), POST `/overit` (`admin.domain.verify`), DELETE (`admin.domain.destroy`).
- [ ] `app/Http/Controllers/Tenant/DomainController.php`: edit (Inertia `Tenant/Domain` — doména, stav, DNS instrukce s tokenem), store, verify (→ `DomainVerifier`), destroy (potvrzení, `forget`, návrat primární subdoméně).
- [ ] FormRequest `app/Http/Requests/Tenant/AddCustomDomainRequest.php`: formát domény, není subdoména platformy, unique, **tenant nemá jinou custom doménu** (limit 1).
- [ ] `resources/js/Pages/Tenant/Domain.vue`: přidat/smazat, kopírovatelné DNS instrukce (TXT + CNAME/A), stavový badge (čeká na DNS / ověřeno / cert vydán / chyba+důvod), „Ověřit teď", mazací dialog. WCAG 2.2 AA → a11y-checker.
- [ ] Discoverability: nav položka / banner do nastavení (vzor `billingProfileComplete`).
- [ ] Commit: `feat(domains): admin custom-domain screen`

### 10. Uzavření
- [ ] Plná sada testů: `php artisan test --compact`.
- [ ] `docs/as-is/2026-07-23-custom-domains.md` (mapa změn, plnění spec, odchylky, deploy runbook: Caddyfile, edge DNS, env).
- [ ] `security_warnings.md`: on-demand TLS abuse, ask endpoint bind, ownership before issuance.
- [ ] CLAUDE.md: rozhodnutí + status řádek vlny 2.1.
- [ ] `/finish-wave` (dokumentace + minor bump + merge + push) — po schválení.

---

## Rizika a mitigace
- **On-demand TLS abuse / LE rate-limit:** ask endpoint autorizuje jen verified+aktivní, cachovaný. Ownership (TXT) povinný před emisí.
- **Cache stale:** `forget(host)` povinný na KAŽDÉ změně stavu (verify/issue/delete/suspend swap). Bez něj neověřená/smazaná doména žije do TTL.
- **Pořadí probe vs live:** `issued` je best-effort informativní; obsluhu gatuje `verified_at`, ne `issued`.
- **`dns_get_record` v testech:** vše za `DnsChecker` kontraktem; FakeDnsChecker deterministický.
- **`sync` driver:** odložený cert-probe/retry job by běžel hned — dokumentovat, případně guard jako u expirace plateb (ruční „Ověřit teď" zůstává cesta).
- **Non-localhost ask:** primárně firewall/bind Caddy↔app; app-side guard na `127.0.0.1` jako obrana do hloubky.

## Mimo vlnu (YAGNI, ze spec)
Víc custom domén/tenant, wildcard cert per tenant, DNS-01, vlastní e-mail doména, přesun adminu na custom doménu.

## Infra (runbook, mimo app kód — do as-is)
Caddyfile `on_demand_tls { ask http://127.0.0.1:<port>/internal/tls-check }`; subdomény `*.droidshop.cz` = wildcard cert (DNS-01), on-demand jen custom. `edge.droidshop.cz` A → VPS IP. Vyplnit `platform.server_ip` + `platform.edge_host`.
