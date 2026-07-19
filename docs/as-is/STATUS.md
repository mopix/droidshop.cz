# As-is status — DroidShop.cz

Poslední aktualizace: **2026-07-19** · Verze: **0.4.0**

## Oblasti

| Oblast | Stav | Spec | Poznámka |
|--------|------|------|----------|
| Laravel skeleton (Breeze + Inertia) | hotovo | — | výchozí app |
| AI / docs workflow | hotovo | bootstrap | `claude-laravel-vue` + WooShop struktura |
| Multi-tenancy — jádro | **hotovo** | §4.2, §4.3, §15.2 | [detail](2026-07-19-tenancy-jadro.md) |
| Izolace dat + CI brána | **hotovo** | §4.2 pojistky 1–3 | pojistka 4 (export) chybí |
| Audit log | **hotovo** | §15.1 | e-mail o změně stavu chybí |
| Kernel služby — Money, Settings, Limits, Sequences, FeatureFlags | **hotovo** | §15.1 | [detail](2026-07-19-kernel-sluzby.md) |
| Kernel služby — FileStorage, MailService, EventBus | odloženo | §15.1 | čeká na provider / prvního volajícího |
| Module system | **hotovo** | kap. 5, §15.5 | [detail](2026-07-19-system-modulu.md) — bez odinstalace |
| Referenční modul `Pages` | **hotovo** | — | statické stránky, Blade SSR |
| Superadmin / `platform_admins` / 2FA | není | §15.4 | |
| Produkty / objednávky / doprava / platby | není | §3.1 | |
| Storefront šablona | není | §3.1 | |
| Tarify / trial / billing | částečně | §3.1 | tabulka `plans` stojí, logika ne |
| Playwright E2E | není | CLAUDE.md | blokováno omezením certifikátu, viz níže |
| Design handoff | prázdné | `docs/design-droidshop/` | |

## Odchylky od produktové specifikace

Detail a odůvodnění: [`2026-07-19-tenancy-jadro.md`](2026-07-19-tenancy-jadro.md) sekce Odchylky.

Nejdůležitější:

1. `SESSION_DOMAIN` je `null` (host-only cookie) — drží session tenanta na jeho doméně.
2. `past_due` nechává storefront běžet — nechceme trestat zákazníky nájemce za jeho nezaplacenou fakturu.
3. `tenants.plan_id` je nullable — onboarding zakládá tenanta před výběrem tarifu.

## Známá omezení, na která se narazí dřív než na cokoliv jiného

- **`curl` na subdoménách potřebuje `-k`** — OpenSSL nebere wildcard `*.droidshop` nad jedinou úrovní. Blokuje kontrolní seznam ve `storefront-rendering.md` i Playwright. Oprava = lokální doména `droidshop.test`.
- **Platformní joby musí implementovat `NotTenantAware`** — jinak je tenant-aware fronta tiše zahodí.
- **Routa Pages je provizorně `/stranka/{slug}`**, ne `/{page-slug}` podle pravidla storefrontu. Vyřeší se s modulem šablony.
- **`LimitsService` nemá žádná počítadla** — kontrakt `LimitCounter` stojí, konkrétní počítadla přijdou s moduly; do té doby `usage()` vrací 0.

## Otevřené chyby

Žádné v `docs/superpowers/errors/`.
