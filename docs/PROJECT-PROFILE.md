# Profil projektu — DroidShop.cz

> Vyplněno při bootstrapu (2026-07-19). Aktualizuj při změně stacku.

## Identita

| Pole | Hodnota |
|------|---------|
| Název projektu | DroidShop.cz |
| Popis (1–2 věty) | Multi-tenant SaaS platforma pro pronájem e-shopů (Shoptet / Eshop-rychle model). |
| Repozitář / URL | https://github.com/mopix/droidshop.cz |

## Stack

| Pole | Hodnota | Poznámka |
|------|---------|----------|
| Laravel | `13` | `composer.json` |
| PHP | `^8.3` | cíl dle spec: 8.4 |
| Frontend architektura | `inertia` | admin; storefront Blade SSR (cíl) |
| TypeScript | `ne` (zatím) | jsconfig; TS přidat postupně |
| UI knihovna | Tailwind + vlastní / shadcn-vue | |
| Testy | `phpunit` | Pest možné později |
| Autentizace | Laravel Breeze + Sanctum | skeleton |
| Autorizace | Policies (+ role middleware) | tenancy ještě není |
| Multi-tenancy | plánováno | shared DB + `tenant_id` |
| Moduly | plánováno | `nwidart/laravel-modules` + vlastní vrstva |

## Cesty ve frontendu

| Režim | Kořen frontendu | Stránky | Komponenty |
|-------|-----------------|---------|------------|
| Inertia (aktivní) | `resources/js/` | `Pages/` | `Components/` |
| Blade storefront (cíl) | `resources/views/` | — | — |

**Aktivní režim:** inertia (admin)

## Lokální vývoj

| Pole | Hodnota |
|------|---------|
| `APP_URL` | `http://localhost:8000` |
| Vite | `npm run dev` |
| Databáze | dle `.env` (sqlite/mysql) |

## MCP / nástroje

| Nástroj | Zapnuto |
|---------|---------|
| Laravel Boost | ne |
| Playwright / browser MCP | ne (zatím) |
| Caveman plugin | ano (user scope) |

## Odchylky od výchozího balíku `claude-laravel-vue`

- Produktová specifikace v `docs/specs/` (ne jen superpowers)
- WooShop-style: `VERSION`, `CHANGELOG.md`, `.cursor/rules`, `.agents/skills`, a11y skill/agent
- Brand/domain: DroidShop, ne WooShop marketplace
