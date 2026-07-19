# Implementační plány

Plán vzniká **po** spec a exploraci, **před** implementací. Ukládá se sem; uživatel musí plán schválit (viz `.claude/rules/structured-workflow.md`).

## Pojmenování

`YYYY-MM-DD-nazev.md` — ideálně stejný slug jako spec.

## Hlavička plánu (povinná)

```markdown
# <Název> — implementační plán

> **Pro agenta:** Použij superpowers:executing-plans nebo subagent-driven-development. Kroky s `- [ ]`.

**Cíl:** Jedna věta.

**Architektura:** 2–3 věty.

**Tech stack:** Dle docs/PROJECT-PROFILE.md

**Spec:** docs/superpowers/specs/YYYY-MM-DD-nazev.md

---
```

## Obsah

- Kroky po 2–5 minutách (test → implementace → test → commit).
- Přesné cesty souborů (create / modify).
- Strategie testů.
- Rizika a mitigace.

## Po dokončení

- Aktualizuj spec (status).
- Zapiš as-is: `docs/as-is/YYYY-MM-DD-nazev.md`.
- Chyby za běhu: `docs/superpowers/errors/`.
