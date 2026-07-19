---
name: fortify-auth
description: Laravel Fortify — login, registrace, reset hesla, 2FA, email verification. Aktivuj při auth funkcích.
---

# Fortify authentication

## Kdy aktivovat

Auth flow, hesla, 2FA, email verification, profil.

## Zásady

- Fortify je headless — UI je ve Vue (SPA nebo Inertia).
- Akce v `app/Actions/Fortify/` dle projektu.
- Policies / gates pro autorizaci nad rolemi.

## SPA

Sanctum + Fortify endpointy; frontend auth store.

## Inertia

Redirect + flash; sdílený `auth.user` v HandleInertiaRequests.

## Dokumentace

S Laravel Boost použij `search-docs` pro Fortify verzi v projektu.
