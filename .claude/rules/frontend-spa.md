# Frontend — Vue SPA (Sanctum)

Aktivní, pokud `docs/PROJECT-PROFILE.md` → **frontend architektura: spa**.

Inspirace: [laravel-vue-starter](https://github.com/gdarko/laravel-vue-starter).

## Architektura

- Catch-all web route → Vue SPA; API pod `/api`.
- **Sanctum** cookie auth; **Fortify** pro auth endpointy.
- **Vue Router** + **Pinia** stores.

## Typické cesty

```
resources/app/
├── views/pages/       # auth/, private/, shared/
├── views/components/  # UI knihovna projektu
├── stores/            # auth, toast, global
├── services/          # API třídy
├── router/
└── helpers/           # api, i18n
```

## Konvence

- API služby v `services/`; PUT/PATCH s FormData často přes POST + `_method`.
- Notifikace uživateli přes **toast store**, ne ad-hoc alerty.
- Router meta: `requiresAuth`, abilities / role dle projektu.
- Ikony a UI dle projektu (např. DaisyUI + Heroicons).
- Barvy: sémantické tokeny (`primary`, `base-content`), ne hardcoded `#fff`.

## Sanctum / CORS

`.env` musí být konzistentní (`APP_URL`, `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN`). Viz `docs/SETUP.md`.

Frontend: `withCredentials: true`, před loginem `GET /sanctum/csrf-cookie`.

## CRUD UI

- Preferuj drawer / modal pattern projektu před zbytečnými novými stránkami.
- Tabulky: řazení, filtry, paginace dle existujících komponent.

## Když něco nefunguje

Zapiš do `docs/superpowers/errors/` (CSRF, 401, CORS jsou časté).
