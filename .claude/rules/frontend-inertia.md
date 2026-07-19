# Frontend — Vue + Inertia.js

Aktivní, pokud `docs/PROJECT-PROFILE.md` → **frontend architektura: inertia**.

Inspirace: [claude-vue-starter-kit](https://github.com/laravel-agent-kits/claude-vue-starter-kit).

## Architektura

- Controllery vrací `Inertia::render('Pages/...', $props)`.
- **Fortify** pro auth; sdílená data v `HandleInertiaRequests`.

## Typické cesty

```
resources/js/
├── pages/           # Inertia stránky
├── components/
│   └── ui/          # např. shadcn-vue
├── layouts/
├── composables/
└── types/           # pokud TypeScript
```

## Konvence

- `<script setup lang="ts">` pokud profil říká TypeScript.
- Formuláře: server validace → chyby v `form.errors`.
- Deferred props (Inertia v2) pro těžká data + skeleton v UI.
- Wayfinder / typové routy — pokud projekt používá, aktivuj skill `wayfinder-development`.

## Testy

- Feature testy s `assertInertia()` (Pest).
- Po změně routes: `php artisan wayfinder:generate` (pokud je Wayfinder v projektu).

## Vite

Chyba manifestu → `npm run dev` nebo `npm run build`.

## SSR

Pouze pokud je v projektu zapnuté — props musí být serializovatelné.
