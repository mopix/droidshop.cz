# As-is: superadmin auth jádro (Fáze 0 / vlna 0.5)

Datum: **2026-07-19** · Verze: **0.6.0** · Větev: `feat/superadmin-auth`

Plán: [`docs/superpowers/plans/2026-07-19-faze-0-vlna-05-superadmin.md`](../superpowers/plans/2026-07-19-faze-0-vlna-05-superadmin.md)
Spec: §15.4, §6.12 · Navazuje na [FileStorage](2026-07-19-filestorage.md)

## Co je hotové

Správce platformy se přihlásí odděleným účtem jen na platformním hostu, projde povinným 2FA, a může se auditovaně vydávat za tenanta. Mimo dosah tenantů i běžných uživatelů.

### Mapa kódu

| Oblast | Soubory |
|---|---|
| Model + tabulka | `app/Models/PlatformAdmin.php`, migrace `platform_admins`, guard `platform` v `config/auth.php` |
| Přihlášení | `app/Http/Controllers/Platform/Auth/LoginController.php`, `Requests/Platform/LoginRequest.php` |
| Host restrikce | `app/Http/Middleware/RequirePlatformHost.php` |
| 2FA | `app/Core/Platform/TwoFactor.php`, `Controllers/Platform/Auth/TwoFactorController.php`, `Middleware/EnsurePlatformTwoFactor.php` |
| Impersonace | `app/Core/Platform/Impersonation.php`, dva controllery (platform + tenant), audit `impersonated_by` |
| UI | `resources/js/Pages/Platform/**` (login, 2FA, dashboard) |
| Zřízení | `app/Console/Commands/CreateSuperadmin.php` (`platform:create-admin`) |

### Bezpečnostní vlastnosti, které kód drží

1. **Oddělená tabulka a guard.** `platform_admins` nesdílí nic s `users`. Superadmin session ≠ tenant-user session (testy na obě strany).
2. **Login jen na platformním hostu.** Na doméně tenanta routy 404 — platforma tam není ani viditelná, ani phishovatelná.
3. **2FA povinné, dvě brány.** Bez enrollmentu jen setup + logout. Po enrollmentu, před challenge, jen challenge. TOTP + jednorázové recovery kódy (hashované, šifrované).
4. **Impersonace = podepsaný handoff mezi hosty.** Platformní a tenantská session jsou různé cookies (host-only), takže impersonace jde přes podepsanou URL na doméně tenanta. 30 min expirace. Cíl musí patřit tenantovi (kontrola na obou stranách). Každý audit zápis má `impersonated_by`.
5. **Rate limit 5/min + lockout** na přihlášení (email+IP).

## Testy

**258 passed (510 assertions)** — z toho 32 nových.

| Sada | Co ověřuje |
|---|---|
| `PlatformAdminModelTest` | schéma, hashování, šifrování 2FA, recovery kódy jednorázové |
| `PlatformAuthTest` | login, oddělení guardů, host 404, rate limit |
| `PlatformTwoFactorTest` | povinnost 2FA, TOTP, recovery, obě brány |
| `ImpersonationTest` | podepsaný handoff, audit, 30min expirace, cizí user zamítnut |

## Odchylky od plánu

| # | Odchylka | Důvod |
|---|---|---|
| 1 | HIBP kontrola úniku hesla **není** | Rozhodnutí uživatele 2026-07-19 — jen síla hesla (min. 10 znaků). HIBP později. |
| 2 | IP allowlist **není** | Spec §15.4 ho má jako „volitelně". Odloženo. |
| 3 | Impersonace uložena v serverové session, ne v samostatném podepsaném tokenu v úložišti | Session je serverová a tamper-proof; handoff **mezi** hosty je podepsaná URL (to je ten „podepsaný token" ze spec). Splňuje záměr. |

## Technický dluh a známá omezení

1. **Management UI chybí** — dashboard je prázdný. Výpis tenantů, metriky (MRR), suspend/obnovení UI, kill switch UI = další vlna. Backend akce z části stojí (`Tenant::changeStatus`).
2. **E-mail o přihlášení z neznámého zařízení** (spec §15.4) není.
3. **Impersonace předpokládá primární doménu tenanta** — bez ní vrátí 422. Vlastní domény jsou fáze 2, takže OK.
4. **Argon2id výkon** — přihlášení je pomalé v testech (proto vysoké časy). V produkci ok; zvážit `PLATFORM_HASH_ROUNDS` pro testy.
5. **Tenant admin area neexistuje**, takže impersonace zatím „přistane" na `/` tenanta. Cíl bude admin dashboard tenanta, až vznikne.

## Pre-deploy checklist (nesplněno)

- [ ] `platform:create-admin` spuštěn pro produkčního superadmina
- [ ] HIBP kontrola hesla
- [ ] IP allowlist (volitelně)
- [ ] E-mail o novém přihlášení
- [ ] Superadmin management UI
