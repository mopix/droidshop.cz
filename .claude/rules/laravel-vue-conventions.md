# Laravel + Vue — společné konvence

Vždy zkontroluj `docs/PROJECT-PROFILE.md` před implementací.

## Backend (Laravel)

- Tenké controllery; logika ve `app/Services/` nebo akcích.
- Validace v **Form Request** třídách, ne inline v controlleru.
- API odpovědi přes **API Resources**, pokud projekt už tak dělá.
- Eloquent vztahy s return type hints; eager loading proti N+1.
- `env()` jen v config souborech — v kódu `config()`.
- Nové soubory přes `php artisan make:*` s `--no-interaction`.
- Před commitem PHP: `./vendor/bin/pint` (dirty files).

## Frontend (obecně)

- Vue 3 **Composition API**; preferuj `<script setup>`.
- Reuse existujících komponent před psaním nových.
- User feedback: toast / flash dle konvence projektu (SPA: toast store).
- Po změně UI ověř `npm run dev` nebo `npm run build`.

## Testy

- Dle profilu: Pest nebo PHPUnit.
- `php artisan test --compact` — minimální sada pro dotčenou oblast.
- Každá netriviální změna = nový nebo upravený test.

## Závislosti

- Neměň `composer.json` / `package.json` bez souhlasu uživatele.

## Dokumentace

- Nové feature: spec → schválený plán → kód → as-is.
- Instalace a deploy: `docs/SETUP.md`, `docs/DEPLOY-TO-PROJECT.md`.

## Laravel Boost (pokud je v projektu)

- Před změnami v ekosystému Laravel použij `search-docs`.
- Pro DB strukturu `database-schema`, pro routy `list-routes`.
