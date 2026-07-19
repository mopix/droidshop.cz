---
description: Inicializace projektu — vyplní docs/PROJECT-PROFILE.md a zkontroluje strukturu docs/
allowed-tools: Read, Write, Edit, Glob, Grep, AskUserQuestion
---

# /init — inicializace Laravel + Vue projektu pro Claude

Proveď inicializaci tohoto repozitáře. Komunikuj **česky**.

## Krok 1: Kontrola prostředí

1. Ověř, že jsi v **kořeni Laravel projektu** (existuje `artisan`, `composer.json`).
   - Pokud **ne** (jsme v balíku `claude-laravel-vue` bez Laravelu): vysvětli, že `/init` se spouští **po nasazení** do Laravel projektu dle `docs/DEPLOY-TO-PROJECT.md`. Nabídni jen doplnění `docs/PROJECT-PROFILE.md` pro tento šablonový repozitář.
2. Přečti `composer.json`, `package.json`, `docs/PROJECT-PROFILE.md` (pokud existuje).

## Krok 2: Dotazník uživateli

Polož **stručně** tyto otázky (můžeš použít AskUserQuestion nebo jeden blok otázek):

1. **Název projektu** a krátký popis (1–2 věty).
2. **Laravel verze** (12 / 13 / jiné) a **PHP verze**.
3. **Frontend architektura:** `spa` (Vue Router + API + Sanctum) nebo `inertia`.
4. **TypeScript** na frontendu: ano / ne.
5. **UI knihovna:** DaisyUI / shadcn-vue / jiné / žádná.
6. **Testy:** Pest / PHPUnit.
7. **Databáze** lokálně: sqlite / mysql / pgsql.
8. **`APP_URL`** pro lokální vývoj.
9. **Laravel Boost MCP:** ano / ne (pokud ano, ověř `laravel/boost` v composer).
10. Existují **vlastní odchylky** od výchozího balíku?

## Krok 3: Zápis profilu

Aktualizuj `docs/PROJECT-PROFILE.md` — vyplň všechny tabulky, datum init, Sanctum sekci pokud `spa`.

## Krok 4: Struktura dokumentace

Ověř existence složek; chybí-li, vytvoř:

```
docs/superpowers/specs/
docs/superpowers/plans/
docs/superpowers/errors/
docs/as-is/
```

## Krok 5: Úprava CLAUDE.md (volitelné)

Pokud uživatel uvedl konkrétní cesty frontendu odlišné od výchozích, doplň jednu větu do `CLAUDE.md` pod Profil projektu (nebo jen profil).

## Krok 6: Shrnutí

Vypiš:

- Potvrzený stack
- Příkazy pro první běh (`composer install`, `npm install`, …) odkazem na `docs/SETUP.md`
- Doporučení: první spec `docs/superpowers/specs/YYYY-MM-DD-<feature>.md`
- Připomenutí workflow: Explore → Plan → Validate → Implement

**Neimplementuj kód** v rámci `/init` — jen konfigurace a dokumentace.
