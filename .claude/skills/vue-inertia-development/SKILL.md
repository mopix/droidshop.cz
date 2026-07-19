---
name: vue-inertia-development
description: Vue 3 + Inertia — pages, layouts, forms, deferred props. Aktivuj při resources/js/ pokud profil je inertia.
---

# Vue Inertia development

## Kdy aktivovat

`PROJECT-PROFILE` → `inertia`.

## Vzory

- Pages v `resources/js/pages/`.
- Props z controlleru — typuj pokud je TS.
- Chyby validace z Inertia form.
- Deferred props + skeleton (Inertia v2).

## Testy

Feature test + `assertInertia()`.

## Reference

`.claude/rules/frontend-inertia.md`, [claude-vue-starter-kit](https://github.com/laravel-agent-kits/claude-vue-starter-kit).
