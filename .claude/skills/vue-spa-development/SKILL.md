---
name: vue-spa-development
description: Vue 3 SPA — komponenty, Pinia, router, API services, DaisyUI. Aktivuj při práci v resources/app/ pokud profil je spa.
---

# Vue SPA development

## Kdy aktivovat

`PROJECT-PROFILE` → `spa` a cesty typu `resources/app/`.

## Vzory

- Composition API + `<script setup>`.
- API v `services/`, stav v `stores/`.
- Router meta pro auth a oprávnění.
- Toast pro user feedback.

## Komponenty

Před novou komponentou prohledej `views/components/`. Drž props konvence existujících (Button, Table, Modal, …).

## Sanctum

CSRF cookie, `withCredentials`, konzistentní `.env`.

## Reference

`.claude/rules/frontend-spa.md`, [laravel-vue-starter](https://github.com/gdarko/laravel-vue-starter).
