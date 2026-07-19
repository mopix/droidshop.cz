# Changelog

Historie verzí projektu DroidShop.cz. Aktuální verze je vždy v souboru [`VERSION`](VERSION).

Formát: [Keep a Changelog](https://keepachangelog.com/), verzování [SemVer](https://semver.org/).
Pravidla: [`.claude/skills/versioning/SKILL.md`](.claude/skills/versioning/SKILL.md).

- **patch** (`+0.0.1`) — každý commit (až bude `pre-commit` hook)
- **minor** (`+0.1.0`) — start nového implementačního plánu
- **major** (`+1.0.0`) — jen na explicitní pokyn

> CHANGELOG vede milníky (minor/major). Detail patchů je v `git log`.

## [0.2.0] – 2026-07-19

**Fáze 0 / vlna 0.1 — tenancy jádro.** Rozpoznání tenanta z Host hlavičky, datová izolace vynucená na modelech, propagace kontextu do jobů, audit log, CI s izolací jako samostatnou branou.

- Datový model jádra dle spec §15.3 (`tenants`, `domains`, `tenant_users`, `plans`, `audit_log`, `jobs_log`)
- Middleware pipeline `ResolveHost` → `CheckTenantStatus` → `SetTenantContext` (spec §15.2)
- `BelongsToTenant` + `TenantScope`; dotaz bez kontextu hodí `MissingTenantContext` místo tichého vrácení dat všech tenantů
- `SchemaConventionTest` shodí build, když doménová tabulka přijde bez `tenant_id`
- Balíčky: `spatie/laravel-multitenancy ^4.1` přidán, `stripe/stripe-php` odstraněn
- Lokální konfigurace přes `.env.local` (načítá `bootstrap/app.php`)
- **As-is:** [`docs/as-is/2026-07-19-tenancy-jadro.md`](docs/as-is/2026-07-19-tenancy-jadro.md)
- **Plán:** [`docs/superpowers/plans/2026-07-19-faze-0-vlna-01-tenancy-jadro.md`](docs/superpowers/plans/2026-07-19-faze-0-vlna-01-tenancy-jadro.md)

## [0.1.0] – 2026-07-19

**Bootstrap.** Laravel skeleton + napojení na GitHub + AI/docs struktura (`claude-laravel-vue` + WooShop vzor) + produktová specifikace v `docs/specs/`.

- **As-is:** [`docs/as-is/2026-07-19-bootstrap.md`](docs/as-is/2026-07-19-bootstrap.md)
