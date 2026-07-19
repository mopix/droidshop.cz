# Pravidlo: aktualizace docs/as-is/ po milestone

## Kdy platí

Po:

- dokončení fáze z `docs/superpowers/plans/`,
- branch připravené k PR / merge,
- větším refaktoru měnícím chování navenek,
- před produkčním deployem (pokud as-is chybí nebo je zastaralá).

## Co zapsat

### 1. `docs/as-is/STATUS.md`

- Tabulka oblastí (spec / plán / stav / odkaz na detail).
- Otevřené chyby z `docs/superpowers/errors/`.
- Souhrn nejdůležitějších odchylek od spec.

### 2. `docs/as-is/YYYY-MM-DD-<oblast>.md`

- Stručná mapa změněných částí kódu.
- Plnění spec po sekcích.
- Testy — co běží, co chybí.
- **Odchylky od specifikace** (povinná sekce, formát v `docs/DOCUMENTATION-LAYERS.md`).
- Technický dluh a pre-deploy checklist.

## Commit message (doporučení)

```
docs: add <oblast> as-is for <milestone>
```

## Čemu se vyhnout

- Psát as-is před dokončením práce.
- Duplikovat celý spec v as-is.
