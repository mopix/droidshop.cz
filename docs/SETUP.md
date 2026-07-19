# Instalace a lokální vývoj — Laravel + Vue

Postup pro **nový** nebo **existující** Laravel projekt po nasazení Claude šablony. Uprav dle `docs/PROJECT-PROFILE.md`.

## Požadavky

| Nástroj | Verze |
|---------|--------|
| PHP | 8.2+ (doporučeno 8.3+) |
| Composer | 2.x |
| Node.js | 18+ (doporučeno 20 LTS) |
| npm | 9+ |
| Databáze | SQLite (výchozí) nebo MySQL/PostgreSQL |

Volitelně: **Claude Code CLI** (`npm install -g @anthropic-ai/claude-code`), **Laravel Boost** (`composer require laravel/boost --dev`).

## 1. Backend (Composer)

```bash
cd /cesta/k/laravel-projektu

composer install

# První setup
cp .env.example .env
php artisan key:generate
```

Uprav `.env` — minimálně:

```env
APP_NAME="Můj projekt"
APP_URL=http://localhost:8000
APP_ENV=local
APP_DEBUG=true

DB_CONNECTION=sqlite
# DB_DATABASE=/absolutni/cesta/database/database.sqlite
```

SQLite soubor (pokud chybí):

```bash
touch database/database.sqlite
```

Migrace a seed (dle projektu):

```bash
php artisan migrate
php artisan migrate --seed   # pokud existuje DatabaseSeeder
```

## 2. Frontend (npm)

```bash
npm install
npm run dev      # vývoj s HMR
# nebo
npm run build    # produkční build
```

Typické skripty v `package.json`:

| Příkaz | Účel |
|--------|------|
| `npm run dev` | Vite dev server |
| `npm run build` | Produkční assets |
| `npm run type-check` | TypeScript (pokud je v projektu) |

## 3. Spuštění vývoje

**Varianta A — dva terminály:**

```bash
# Terminál 1
php artisan serve

# Terminál 2
npm run dev
```

**Varianta B — composer script** (pokud projekt definuje `composer run dev`):

```bash
composer run dev
```

Otevři `APP_URL` (typicky http://localhost:8000).

## 4. Sanctum — SPA režim

Pokud `PROJECT-PROFILE.md` → `frontend architektura: spa`, v `.env` musí sedět:

```env
APP_URL=http://localhost:8000
SANCTUM_STATEFUL_DOMAINS=localhost:8000
SESSION_DOMAIN=localhost
```

Produkce — nahraď doménou aplikace (bez portu, pokud není potřeba).

Axios / frontend musí posílat `withCredentials: true` a CSRF cookie (`/sanctum/csrf-cookie` před loginem).

## 5. Inertia režim

- Po změnách ve `resources/js/` spusť `npm run dev` nebo `npm run build`.
- Chyba *Unable to locate file in Vite manifest* → `npm run build` nebo běžící `npm run dev`.
- SSR (pokud je zapnuté): `php artisan inertia:start-ssr` dle dokumentace projektu.

## 6. Testy a kvalita

```bash
# Pest nebo PHPUnit — dle PROJECT-PROFILE
php artisan test --compact

# Konkrétní test
php artisan test --compact --filter=NazevTestu

# PHP styl
./vendor/bin/pint
```

## 7. Laravel Boost (doporučeno)

```bash
composer require laravel/boost --dev
php artisan boost:install   # pokud příkaz existuje v projektu
```

V Claude Code přidej MCP dle `boost.json` / dokumentace Boost.

## 8. Claude Code v projektu

```bash
cd /cesta/k/laravel-projektu
claude
```

První krok po nasazení šablony: **`/init`** — vyplní `docs/PROJECT-PROFILE.md`.

## Časté problémy

| Problém | Řešení |
|---------|--------|
| Vite manifest | `npm run build` nebo `npm run dev` |
| 419 CSRF / 401 po loginu (SPA) | Sanctum `.env`, csrf cookie, `withCredentials` |
| Práva `storage/`, `bootstrap/cache/` | `chmod -R ug+rwx storage bootstrap/cache` |
| Composer paměť | `COMPOSER_MEMORY_LIMIT=-1 composer install` |
