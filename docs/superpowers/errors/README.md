# Chyby a incidenty

Dokumentace bugů, regressí a postmortemů — aby se neopakovaly a AI měla kontext.

## Pojmenování

`YYYY-MM-DD-error-cislo-nazev.md`

Příklady:

- `2026-05-17-error-001-sanctum-csrf.md`
- `2026-05-17-error-002-vite-manifest.md`

## Šablona

```markdown
# Error NNN — <krátký název>

**Datum:** YYYY-MM-DD  
**Závažnost:** low | medium | high | critical  
**Stav:** open | mitigated | resolved  
**Související spec/plán:** odkaz nebo —

## Symptom

Co uživatel / test viděl.

## Příčina

Root cause (až známá).

## Řešení

Co pomohlo — commit, config, kód.

## Prevence

- [ ] Test / lint / dokumentace / pravidlo v .claude/rules/

## Poznámky

Logy, stack trace (zkráceně), odkazy.
```
