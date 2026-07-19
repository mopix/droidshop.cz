# As-is — jak to v repu opravdu je

Po milestone, PR nebo deployi zapiš realitu implementace. **As-is má přednost** před zastaralým specem u otázky „jak to funguje teď?“.

## Pojmenování

`YYYY-MM-DD-nazev.md` — např. `2026-05-17-export-csv.md`

## STATUS.md (volitelný rozcestník)

[`STATUS.md`](STATUS.md) — tabulka oblastí, metriky, souhrn odchylek.

## Povinná sekce na konci každého as-is

Viz [`../DOCUMENTATION-LAYERS.md`](../DOCUMENTATION-LAYERS.md) — **Odchylky od specifikace**.

## Kdy psát

- Dokončená fáze z plánu
- Branch připravená k merge
- Větší refaktor měnící chování navenek
- Před produkčním deployem (pokud as-is chybí)

Pravidlo pro Claude: [`.claude/rules/as-is-on-milestone.md`](../../.claude/rules/as-is-on-milestone.md).
