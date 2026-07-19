# Fáze 0 / vlna 0.5 — Superadmin: auth jádro — implementační plán

> **Pro agenta:** superpowers:executing-plans / subagent-driven-development. Kroky `- [ ]`.

**Cíl:** Správce platformy se přihlásí na platformní doménu odděleným účtem, projde povinným 2FA, a může se auditovaně vydávat za tenanta — vše mimo dosah běžných uživatelů i tenantů.

**Architektura:** Třetí guard `platform` nad tabulkou `platform_admins`, oddělenou od `users` (spec §15.4 — snížení dopadu úniku). Přihlášení jen na platformním hostu. Impersonace přes podepsaný token s auditem. UI Inertia (superadmin = SPA dle pravidla storefrontu, `noindex`).

**Tech stack:** Laravel 13, PHP 8.3, Inertia/Vue (admin), PHPUnit. **Spec:** §15.4, §6.12 · Navazuje na [FileStorage](../../as-is/2026-07-19-filestorage.md)

**Role/viditelnost:** Vše jen `SUPERADMIN`. Žádná routa není veřejná ani dostupná tenant guardem. Vše `noindex, nofollow`.

---

## Rozsah — návrh dělení

Superadmin má dvě poloviny s velmi různou povahou:

**A) Auth jádro (tato vlna) — bezpečnostně kritické, backend.** Guard, tabulka, přihlášení jen na platformním hostu, rate limit + lockout, povinné 2FA (TOTP + recovery kódy), impersonace s auditem, minimální Inertia obrazovky (login, 2FA, prázdný dashboard).

**B) Management UI (další vlna) — rozsáhlé, UI.** Dashboard s metrikami (MRR, konverze), výpis a detail tenantů, suspend/obnovení s důvodem, kill switch modulů, log auditu, náhled e-shopu.

Tento plán pokrývá **A**. Backend akce z B (suspend/obnovení) už z části existují (`Tenant::changeStatus`), takže B je hlavně obrazovky.

---

## Bezpečnostní jádro (čte se první)

1. **Oddělená tabulka a guard.** `platform_admins` nesdílí nic s `users`. Únik databáze tenantů nedá přístup k platformě a naopak. Guard `platform` s vlastním providerem.
2. **Přihlášení jen na platformním hostu.** Login routa neexistuje na doméně tenanta. Middleware odmítne platformní auth mimo platformní host.
3. **2FA povinné.** Superadmin bez potvrzeného 2FA se nedostane dál než na stránku nastavení 2FA. Žádná výjimka (spec §15.4).
4. **Impersonace je capability, ne přihlášení.** Podepsaný token, 30 min, banner v UI, každá akce v audit logu s `impersonated_by`. Impersonovaný superadmin nikdy neztratí stopu, kdo skutečně jedná.
5. **Rate limit a lockout.** 5 pokusů/min, zámek 15 min po 10 pokusech (spec §15.4).
6. **Argon2id, min. 10 znaků**, kontrola síly hesla. HIBP (kontrola proti únikům) **odložena** — rozhodnutí uživatele 2026-07-19; pro hrstku superadminů není kritická a drží CI bez externího volání. Dopíše se později.

---

## Kroky

### A. Tabulka a model

- [ ] A1. Test `PlatformAdminSchemaTest` — tabulka a sloupce. Červený.
- [ ] A2. Migrace `platform_admins` (id, email UQ, password, name, `two_fa_secret` NULL, `two_fa_confirmed_at` NULL, `two_fa_recovery_codes` NULL, last_login_at, timestamps). **Platformní tabulka** — do whitelistu `SchemaConventionTest`.
- [ ] A3. Model `PlatformAdmin` (extends `Authenticatable`), Argon2id přes cast `hashed`, 2FA pole zašifrovaná (`encrypted`).
- [ ] A4. Factory + seeder pro prvního superadmina (jen dev/příkaz, ne v migraci).
- [ ] A5. Zeleně. Commit `feat: add platform_admins schema and model`.

### B. Guard a přihlášení

- [ ] B1. Test `PlatformAuthTest`: přihlášení platnými údaji na platformním hostu projde; špatné heslo selže; **login na hostu tenanta → 404**; guard `platform` je oddělený od `web` (přihlášený superadmin není přihlášený jako tenant user a naopak). Červený.
- [ ] B2. `config/auth.php` — guard `platform` (driver session, provider `platform_admins`).
- [ ] B3. Controller + Inertia stránka login (jen platformní host). Middleware `platform.host`.
- [ ] B4. Rate limit 5/min + lockout 15 min po 10 pokusech. Test.
- [ ] B5. Zeleně. Commit `feat: add platform guard and host-restricted login`.

### C. Povinné 2FA (TOTP + recovery)

- [ ] C1. Test `PlatformTwoFactorTest`: superadmin bez potvrzeného 2FA je přesměrován na nastavení; po potvrzení kódem projde; platný TOTP pustí dál, neplatný ne; recovery kód funguje jednou a pak je spotřebovaný. Červený.
- [ ] C2. Balíček `pragmarx/google2fa` (ověřit verzi). Generování secretu, QR, potvrzení.
- [ ] C3. Middleware `platform.2fa` — bez potvrzeného 2FA jen na nastavení a logout.
- [ ] C4. Recovery kódy — 8 jednorázových, hashované, regenerovatelné.
- [ ] C5. Zeleně. Commit `feat: require TOTP two-factor for superadmin`.

### D. Impersonace

- [ ] D1. Test `ImpersonationTest`: superadmin zahájí impersonaci tenant ownera → požadavky běží jako ten user v kontextu tenanta; **každá akce v audit logu má `impersonated_by`**; ukončení vrátí superadmina; token vyprší po 30 min; impersonovat lze jen z platformního superadmin session. Červený.
- [ ] D2. `app/Core/Platform/Impersonation.php` — start (podepsaný token, 30 min), stop, current. Uloženo v session + ověření podpisu.
- [ ] D3. Middleware, který při aktivní impersonaci nastaví `impersonated_by` do `AuditLog` kontextu a tenant kontext.
- [ ] D4. Banner data do sdílených Inertia props (`impersonating: {admin, tenant}`).
- [ ] D5. `AuditLog` rozšířit o `impersonated_by` (nullable) — migrace + doplnění služby.
- [ ] D6. Zeleně. Commit `feat: add audited superadmin impersonation`.

### E. Minimální UI a uzavření

- [ ] E1. Inertia stránky: login, 2FA challenge, 2FA setup, prázdný dashboard (jen „přihlášen jako X", odkaz logout). Vše `noindex`.
- [ ] E2. Routy pod platformním hostem, guard `platform` + `platform.2fa`.
- [ ] E3. `pint --test` celý projekt, `npm run build` projde, `php artisan test` zeleně.
- [ ] E4. `docs/as-is/…-superadmin-auth.md` + `STATUS.md`.
- [ ] E5. `VERSION` → `0.6.0` + `CHANGELOG.md`.
- [ ] E6. Merge.

---

## Strategie testů

| Vrstva | Co |
|---|---|
| Feature | Přihlášení, oddělení guardů, host restrikce, rate limit |
| Feature | 2FA povinnost, TOTP, recovery kódy |
| Feature | Impersonace: kontext, audit `impersonated_by`, expirace |
| Unit | Generování/ověření recovery kódů, token impersonace |

## Rizika a mitigace

| Riziko | Dopad | Mitigace |
|---|---|---|
| Superadmin login dostupný na hostu tenanta | **kritický** | `platform.host` middleware, test na 404 |
| Sdílení session mezi guardy (superadmin = tenant) | **kritický** | Oddělený guard `platform`, test na izolaci |
| 2FA obejitelné | **kritický** | `platform.2fa` middleware před vším kromě setup/logout |
| Impersonace bez stopy | vysoký | `impersonated_by` v každém audit zápisu, test |
| Token impersonace nevyprší | vysoký | 30 min TTL, test na expiraci |
| HIBP volání v testech/CI | střední | Pravidlo vypínatelné konfigem, fake v testech |
| Argon2id výkon v testech | nízký | `PLATFORM_HASH_ROUNDS` nízké v `phpunit.xml` |

## Mimo rozsah (další vlna)

- Dashboard s metrikami (MRR, konverze, churn)
- Výpis a detail tenantů, suspend/obnovení UI, kill switch UI
- IP allowlist (spec §15.4, „volitelně")
- E-mail o přihlášení z neznámého zařízení
- Horizon, stavová stránka

## Rozhodnutí (2026-07-19, uživatel)

1. **Rozsah = jen auth jádro (A).** Management UI je další vlna.
2. **HIBP odložena.** Teď jen síla hesla (Argon2id, min. 10 znaků). Kontrola proti únikům později.
