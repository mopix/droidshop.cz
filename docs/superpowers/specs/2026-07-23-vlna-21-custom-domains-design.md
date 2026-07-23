# Vlna 2.1 — Vlastní domény nájemců + automatické TLS

- **Datum:** 2026-07-23
- **Fáze:** 2 (první vlna po MVP fázi 1)
- **Navazuje na:** onboarding/tenancy (vlna 1.7), datový model `domains` (vlna 0.1)
- **Zdroj pravdy (produkt):** `docs/specs/2026-07-17-eshop-platforma-specifikace.md` §5.1 (řádky 185–188), §16 (`domains`), rizika §… (řádek 565)
- **Typ:** infra + tenancy rozšíření (netriviální ops na VPS)
- **Implementace:** samostatná session (rozhodnutí vlastníka 2026-07-23)

## Cíl

Nájemce provozuje svůj e-shop na **vlastní doméně** (`example.com` / `shop.example.com`)
s automaticky vydaným a obnovovaným TLS certifikátem, bez příplatku (vlastní doména je
base, spec řádek 644). Platforma ověří vlastnictví domény přes DNS, autorizuje emisi
certu a servíruje storefront na kanonickém hostu; původní subdoména 301 přesměruje na
vlastní doménu.

## Potvrzená rozhodnutí (brainstorming 2026-07-23)

1. **TLS = Caddy on-demand TLS + ask endpoint.** Před Laravel stojí Caddy s `on_demand_tls`.
   Na první TLS handshake na neznámý host se Caddy zeptá našeho ask endpointu, zda je doména
   povolená; pokud ano, Caddy vydá Let's Encrypt cert a řídí jeho renewal. Proxy vlastní celý
   TLS lifecycle, aplikace jen autorizuje. Caddyfile je deploy artefakt, ne app kód.
2. **Ověření vlastnictví = TXT token + CNAME/A routing.** Silný důkaz (spec mandate: ověřit
   vlastnictví před emisí, jinak zneužití).
3. **Kanonický host = subdoména 301 → vlastní doména.** Vlastní doména se stane primární;
   storefront na subdoméně 301 redirectuje na vlastní doménu. Admin zůstává na subdoméně.
4. **Routing přes CNAME na stabilní hostname `edge.droidshop.cz`** (ne holá A na IP) —
   odděluje nájemce od konkrétní IP VPS; A/ALIAS jen pro apex domény (CNAME na apexu je nevalidní).

## Rozsah

### Ve vlně
- Admin obrazovka: přidat/smazat vlastní doménu, zobrazit DNS instrukce a stav.
- DNS ověření vlastnictví (TXT challenge) + routingu (CNAME na `edge`, A pro apex).
- Caddy ask endpoint (autorizace emise certu).
- Gating obsluhy: neověřená custom doména se neservíruje.
- Detekce vydaného certu (probe s retry) → stav `issued`.
- 301 subdoména → primární vlastní doména (storefront).
- Scheduled sweep pending domén + manuální „Ověřit teď".

### Mimo vlnu (YAGNI)
- Víc vlastních domén per tenant (MVP = jedna + subdoména).
- Wildcard cert per tenant, DNS-01 challenge.
- Vlastní e-mailová doména nájemce (SPF/DKIM) — samostatná fáze 2 položka.
- Přenos adminu na vlastní doménu (admin dál na subdoméně).

## Datový model

`domains` (z vlny 0.1) už má: `tenant_id`, `domain` (unique), `type[subdomain|custom]`,
`is_primary`, `ssl_status[none|pending|issued|error]`, `verified_at`, `timestamps`.

### Migrace (přidat sloupce)
| Sloupec | Typ | Účel |
|---------|-----|------|
| `challenge_token` | string nullable | Token pro TXT `_droidshop-challenge.<doména>` |
| `verification_error` | string nullable | Poslední důvod selhání (UI) |
| `last_checked_at` | timestamp nullable | Kdy naposledy probíhal DNS check (backoff) |

- **Limit: jedna `type=custom` doména per tenant** (validace při přidání). Subdoména existuje vždy.

### Stavový model domény (custom)
```
none      → (tenant přidá doménu, vygeneruje se challenge_token)
pending   → čeká na DNS (TXT+routing); sweep/manuální check
verified  → verified_at set (TXT match + routing míří na nás); ssl_status = pending
            (cert ještě nevydán); ask endpoint od teď autorizuje
issued    → probe potvrdil funkční HTTPS (Caddy vydal cert); doména je live
error     → verification_error set; UI ukáže důvod, nájemce opraví DNS a dá „Ověřit teď"
```
`verified_at` (vlastnictví) je oddělené od `ssl_status` (cert). „Verified" = `verified_at != null`.

## Komponenty a kontrakty

### `DnsChecker` (kontrakt, `app/Core/Domains/Contracts/`)
```php
interface DnsChecker
{
    /** TXT hodnoty pro daný host (např. _droidshop-challenge.example.com). */
    public function txt(string $host): array;
    /** CNAME cíl hostu, nebo null. */
    public function cname(string $host): ?string;
    /** A záznamy (IP) hostu. */
    public function a(string $host): array;
}
```
- Produkční `SystemDnsChecker` nad `dns_get_record`. Test `FakeDnsChecker` (deterministický).
- **Nutné:** `dns_get_record` je nedeterministický a v testech nedostupný → veškerá verifikace
  jede za tímto kontraktem.

### `DomainVerifier` (`app/Core/Domains/`)
- `verify(Domain $domain): void` — přes `DnsChecker`: (1) TXT `_droidshop-challenge.<doména>`
  obsahuje `challenge_token`; (2) routing míří na nás — CNAME končí na `edge.droidshop.cz`
  NEBO A obsahuje `config('platform.server_ip')`. Obojí OK → `verified_at = now()`,
  `ssl_status = pending`, `verification_error = null`, **`DomainTenantFinder::forget(host)`**.
  Chyba → `ssl_status = error` + `verification_error`, `last_checked_at = now()`.
- Idempotentní; běží v `runAs($tenant)` (audit).

### `DomainCertProbe` (`app/Core/Domains/`)
- Pro `verified` domény: HTTPS probe na `https://<doména>/up` (health). Úspěch → `ssl_status = issued`.
  **Retry/backoff:** první probe může spustit on-demand emisi a selhat; job se přeplánuje
  (delay), ne jednorázový check. Trvalé selhání po N pokusech → `error`.

### Ask endpoint (Caddy autorizace)
- `GET /internal/tls-check?domain=<host>` — **jen 127.0.0.1** (Caddy běží lokálně), mimo
  tenant middleware, bez CSRF, bez session. Vrátí **200** jen když existuje `Domain` s tímto
  hostem, `verified_at != null` a tenant je aktivní (ne suspended/deleted); jinak **404/403**.
  Výsledek cachovaný (krátké TTL) — abuse/LE-rate-limit ochrana. Bod (b): ask je bezpečnostní
  jádro, nesmí autorizovat neověřenou doménu.

### Sweep command
- `domains:sweep-pending` (denně/po hodinách, `NotTenantAware`) — pro `pending`/`error` custom
  domény zavolá `DomainVerifier` s backoffem dle `last_checked_at`; pro `verified` bez `issued`
  zavolá `DomainCertProbe`. Doména `pending` déle než TTL (config) → `error` „DNS nenastaveno".

## Toky

### A) Přidání domény
1. Admin `/admin/nastaveni/domena`: zadá `example.com`. Validace: formát, není subdoména
   platformy, není už obsazená (unique), tenant nemá jinou custom doménu.
2. Vytvoří se `Domain` (`type=custom`, `ssl_status=none`→`pending`, `challenge_token`).
3. UI ukáže DNS instrukce: TXT `_droidshop-challenge.example.com = <token>` + routing
   (`shop.example.com` CNAME → `edge.droidshop.cz`; apex `example.com` A → `<server_ip>`).

### B) Ověření
1. Sweep nebo „Ověřit teď" → `DomainVerifier::verify`.
2. TXT+routing OK → `verified_at`, `ssl_status=pending`, `forget(host)`. Ask endpoint od teď
   autorizuje → Caddy na první request vydá cert.
3. `DomainCertProbe` (retry) potvrdí HTTPS → `ssl_status=issued`.

### C) Aktivace kanonického hostu
1. Při přechodu na `issued`: custom doména `is_primary=true`, subdoméně `is_primary=false`
   (v jedné transakci; primární je právě jedna).
2. Storefront na subdoméně: pokud tenant má `issued` custom primární doménu, **301** na
   `https://<custom><path>` (přes `RedirectResponder`/middleware; admin cesty výjimka).
   Bod (c): po změně stavu `forget` cache obou hostů.

### D) Smazání domény
1. Potvrzovací dialog (pravidlo mazacích akcí). Smaže řádek, `forget(host)`, případně vrátí
   `is_primary` subdoméně. Caddy cert nechá expirovat (nevoláme Caddy API).

## Obsluha (gating) — změna load-bearing cesty

`DomainTenantFinder::find` dnes resolvuje **libovolný** host, který má řádek v `domains`,
okamžitě. To je pro custom domény díra: neověřená/rozpracovaná doména by servírovala shop.

- **Gating:** custom doména se servíruje jen když `verified_at != null` (bod 4). Neověřená
  custom → 404 (přes HTTP; přes HTTPS Caddy stejně nedostane cert, ask řekne ne).
- Subdomény (`type=subdomain`) beze změny — vždy resolvují.
- Cache `forget(host)` při každé změně stavu domény (verify/issue/delete/suspend) — bod (c).
- Status gating tenanta (suspended/deleted) platí dál automaticky (custom → tenant → existující
  `CheckTenantStatus`).

## Admin UI
- Jádrová obrazovka `/admin/nastaveni/domena` (route skupina `routes/tenant.php`, `['web','tenant.member']`,
  `noindex`). Inertia `Tenant/Domain`.
- Přidat doménu (form), DNS instrukce s tokenem (kopírovatelné), stavový badge
  (čeká na DNS / ověřeno / cert vydán / chyba + důvod), „Ověřit teď" (POST → verify),
  smazat (potvrzovací dialog). WCAG 2.2 AA.
- Prop `billingProfileComplete`-style discoverability: banner/nav položka do nastavení.

## Bezpečnost / izolace
- **Ownership před emisí** (TXT token) — spec mandate proti zneužití on-demand TLS.
- **Ask endpoint** jen localhost, autorizuje jen verified+aktivní, cachovaný — abuse/LE-limit ochrana (bod b).
- `domain` globálně unique (řádek nemůže patřit dvěma tenantům — isolation).
- Verifikace/probe za `DnsChecker`/HTTP kontrakty (verify-before-trust; žádná důvěra requestu).
- Cache invalidace na každé změně stavu (bod c) — zabraňuje servírování neověřené/smazané domény.
- Změny stavu v `runAs($tenant)` (audit).

## Testy
- **`DomainVerifier`** (FakeDnsChecker): TXT+CNAME(edge) match → verified; TXT+A(server_ip) match →
  verified (apex); TXT chybí → error; routing míří jinam → error; verify volá `forget(host)`.
- **Ask endpoint:** 200 pro verified+aktivní; 404 pro neověřenou; 404 pro suspended tenant;
  odmítne non-localhost (nebo dokument, že to řeší firewall/bind).
- **Gating `DomainTenantFinder`:** neověřená custom → n/resolve (404 v ResolveHost); subdoména beze změny.
- **301:** tenant s issued custom → storefront na subdoméně 301 na custom (stejná cesta); admin cesta neredirectuje.
- **Limit:** druhá custom doména per tenant → validační chyba.
- **`DomainCertProbe`:** probe úspěch → issued; opakované selhání → error (retry chování).
- **Sweep command:** pending → verify; verified bez issued → probe; expirovaný pending → error.
- **Smazání:** potvrzení, forget, návrat primární subdoméně.

## Deploy / infra (runbook, mimo app kód)
- **Caddyfile** s `on_demand_tls { ask http://127.0.0.1:<app_port>/internal/tls-check }`,
  LE issuer, reverse_proxy na Laravel. Subdomény `*.droidshop.cz` — **wildcard cert (DNS-01)**,
  ne on-demand (bod e: subdomén moc → LE limity); on-demand jen pro custom domény.
- **DNS:** `edge.droidshop.cz` A → VPS IP (stabilní CNAME cíl pro nájemce). `config('platform.server_ip')`
  a `platform.edge_host` vyplnit.
- **Wildcard `*.droidshop.cz` + TLS** je pre-launch checklist položka (CLAUDE.md); tato vlna
  na ni navazuje, ale on-demand pro custom je nezávislý.

## Odchylky / háčky
- **Holding page vs 404:** neověřená custom doména přes HTTPS = TLS chyba (Caddy nemá cert),
  ne naše 404. Přes HTTP → 404/holding. Nájemce vidí stav v adminu. Přijatelné.
- **Cache TTL:** just-verified doména může naběhnout až po `forget` + prvním requestu; smazaná
  bez `forget` by žila do TTL — proto `forget` povinný na každé změně.
- **`issued` detekce** je best-effort probe; doména může být fakticky live dřív, než probe stihne
  přepnout stav. Stav je informativní pro UI, obsluhu gatuje `verified_at`, ne `issued`.
- **Caddy cert cleanup** při smazání domény neděláme (nevoláme Caddy API) — cert expiruje sám.
