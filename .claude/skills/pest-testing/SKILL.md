---
name: pest-testing
description: Pest testy — feature, unit, Inertia. Aktivuj při psaní testů pokud PROJECT-PROFILE říká pest.
---

# Pest testing

## Kdy aktivovat

Psaní nebo oprava testů; uživatel zmíní TDD, test, coverage.

## Příkazy

```bash
php artisan make:test --pest NazevTestu
php artisan test --compact
php artisan test --compact --filter=cast
```

## Zásady

- Většina testů = feature.
- Factories a stavy factory před ručním setupem modelu.
- Nemazat testy bez souhlasu.

## Inertia

Použij `AssertableInertia` dle existujících testů v projektu.

## Pokud projekt používá PHPUnit

Přepni na PHPUnit konvence — skill neaplikuj slepě.
