# Strukturovaný workflow — Explore → Plan → Validate → Implement

**Povinné** u netriviálních úkolů (nová feature, větší refaktor, netriviální bugfix).

```
Explore → Plan → Validate → Implement
```

⛔ **V fazích 1–3 nepiš implementační kód** (žádné nové soubory produkční logiky).

## Fáze 1: EXPLORE

1. Potvrď úkol a přečti `docs/PROJECT-PROFILE.md`.
2. Zeptej se, **které soubory / složky** prozkoumat (nebo navrhni seznam).
3. Projdi kód a zapiš:
   - existující vzory,
   - závislosti,
   - rizika.

**Výstup:** krátké „Exploration Summary“ v chatu (nebo poznámka do plánu).

## Fáze 2: PLAN

1. Navrhni plán kroků, soubory create/modify, testy, delegaci agentům.
2. **Ulož plán** do `docs/superpowers/plans/YYYY-MM-DD-<slug>.md` (šablona v README složky).
3. Odkazuj na spec v `docs/superpowers/specs/`.

**Výstup:** odkaz na soubor plánu + shrnutí v chatu.

## Fáze 3: VALIDATE

Čekej na explicitní schválení: „Schváleno“, „Pokračuj“, „Jdi na to“, „Approved“.

- Revize → uprav plán, znovu předlož.
- Ticho ≠ souhlas.

## Fáze 4: IMPLEMENT

1. Drž se schváleného plánu.
2. Deleguj dle `.claude/agents/` (backend / ui / qa).
3. Po krocích reportuj progress.
4. Chyby zapisuj do `docs/superpowers/errors/`.
5. Po milestone → `docs/as-is/` (viz `as-is-on-milestone.md`).

## Výjimky

- Jednořádkové opravy (překlep, obvious fix) — můžeš přeskočit plán po potvrzení uživatele.
- „Přeskoč plánování“ — upozorni na riziko, pak pokračuj.

## Rychlá reference

| Uživatel říká | Akce |
|---------------|------|
| Nová feature / „udělej X“ | Fáze 1 |
| „Stačí explorace, plánuj“ | Fáze 2 |
| „Schváleno“ | Fáze 4 |
| „Uprav plán …“ | Fáze 2 |
