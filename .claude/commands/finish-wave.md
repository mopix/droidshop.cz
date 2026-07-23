---
description: Uzavře dokončenou vlnu — dokumentace + minor bump + commit + merge do main + push
allowed-tools: Read, Write, Edit, Bash, Glob, Grep
---

# /finish-wave — uzavření dokončené vývojové vlny

Spouštěj, až je práce na feature branchi **hotová a testy zelené**. Provede vše, co se u
každé vlny opakuje, bez doptávání. Komunikuj **česky**.

Volitelný argument `$ARGUMENTS` = krátký popis vlny (např. „vlna 1.9 deferred billing").
Pokud chybí, odvoď ho z názvu branche a posledních commitů.

## Předpoklady (ověř, jinak zastav a řekni proč)

1. Nejsi na `main` (CLAUDE.md zakazuje práci na `main`). Zjisti branch: `git rev-parse --abbrev-ref HEAD`.
2. Pracovní strom je čistý nebo obsahuje jen do-dokumentaci, kterou teď dopíšeš (`git status --porcelain`).
3. **Testy zelené.** Spusť plnou sadu ve **foregroundu** (ne v subagentu, ne na pozadí —
   background běh vrací vadné reporty): `php artisan test --compact`. Sada trvá ~3 min.
   Když cokoli selže → zastav, vypiš selhání, nepokračuj v merge.

## Krok 1: Dokumentace (pravidlo as-is-on-milestone)

Zkontroluj, co už je hotové, a doplň chybějící:

- `docs/as-is/YYYY-MM-DD-<oblast>.md` — mapa změn, plnění spec, testy, **povinná sekce
  Odchylky od specifikace**, tech dluh / pre-deploy. (Většinou už existuje z běhu vlny.)
- `docs/as-is/STATUS.md` — nový/aktualizovaný řádek oblasti + datum a verze v hlavičce.
- `CLAUDE.md` — nová rozhodnutí do sekce **Rozhodnutí**, aktualizace shrnutí „Stojí jádro…"
  a případné položky do **Před spuštěním**. Drž CLAUDE.md krátký, detail patří do `docs/`.

## Krok 2: Verze (skill versioning)

Vlna = implementační plán → **minor bump**:

1. Přečti `VERSION`.
2. Zvedni na další minor, patch na 0 (`0.17.x → 0.18.0`). Zapiš do `VERSION`.
3. `git add VERSION` — tím `pre-commit` hook přeskočí svůj patch bump.
4. Přidej d-atovanou sekci do `CHANGELOG.md` (jen milníky = minor/major; formát dle
   existujících sekcí: nadpis `## [X.Y.0] – YYYY-MM-DD`, shrnutí, pod-sekce, deploy/follow-up).

## Krok 3: Commit dokumentace + verze

```bash
git add docs CLAUDE.md CHANGELOG.md VERSION
git commit -m "docs: close <vlna> + bump to <verze>

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

(Pokud jsou docs už zvlášť commitnuté z běhu vlny, commitni jen VERSION+CHANGELOG s
`chore: bump to <verze> — <vlna>`.)

## Krok 4: Merge do main + push

Projekt merguje `--no-ff` (viditelný merge commit vlny), pak push main i branch (branch
zůstane jako záloha, neuklízí se).

```bash
BRANCH=$(git rev-parse --abbrev-ref HEAD)
git checkout main
git merge --no-ff "$BRANCH" -m "Merge <vlna> (v<verze>)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
git push origin main
git push origin "$BRANCH"
git log --oneline -1
```

Nikdy `--force`. Když merge hlásí konflikt → zastav a nahlas ho (neřeš naslepo).

## Krok 5: Aktualizuj paměť

Aktualizuj stavový memory soubor projektu (`stav-…`) i `MEMORY.md` hook: nová vlna hotová
a v `origin/main`, verze, počet testů, co je otevřené dál. Konvertuj relativní data na absolutní.

## Report

Na závěr stručně: verze, merge rozsah (`git log` hash → hash), počet testů, co zůstalo
otevřené (tech dluh / další kandidáti vln).
