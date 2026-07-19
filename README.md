# DroidShop.cz

Multi-tenant SaaS e-shopová platforma (pronájem e-shopů).

- **Produktová specifikace:** [`docs/specs/2026-07-17-eshop-platforma-specifikace.md`](docs/specs/2026-07-17-eshop-platforma-specifikace.md)
- **AI / agent kontext:** [`CLAUDE.md`](CLAUDE.md) · [`AGENTS.md`](AGENTS.md)
- **Dokumentace:** [`docs/README.md`](docs/README.md)
- **Repozitář:** https://github.com/mopix/droidshop.cz

## Lokální vývoj

```bash
composer install
cp .env.example .env   # nebo .env.local dle CLAUDE.md
php artisan key:generate
npm install
php artisan serve      # terminál 1
npm run dev            # terminál 2
# nebo: composer run dev
```

Více: [`docs/SETUP.md`](docs/SETUP.md).

## Stack (aktuálně)

Laravel 13 · Vue 3 · Inertia · Tailwind · PHPUnit · Breeze

Cílový stack (tenancy, moduly, storefront Blade) — viz specifikace a `CLAUDE.md`.
