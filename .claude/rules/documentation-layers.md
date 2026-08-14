# Pravidlo: vrstvy dokumentace

## Povinné umístění

| Typ | Složka | Formát |
|-----|--------|--------|
| Spec (zadání) | `docs/superpowers/specs/` | `YYYY-MM-DD-nazev.md` |
| Plán | `docs/superpowers/plans/` | `YYYY-MM-DD-nazev.md` |
| Chyba / incident | `docs/superpowers/errors/` | `YYYY-MM-DD-error-cislo-nazev.md` |
| As-is | `docs/as-is/` | `YYYY-MM-DD-nazev.md` |
| **Rozhodnutí** | `docs/decisions/` | řádek `- RRRR-MM-DD: **teze.** proč` v souboru oblasti |

Zdroj pravdy o významu vrstev: `docs/DOCUMENTATION-LAYERS.md`.

## Chování agenta

1. **Netriviální feature** — nejdřív spec (nebo rozšíř existující), pak plán, pak implementace po schválení.
2. **Plán** ukládej do `docs/superpowers/plans/`, ne jen do chatu.
3. **Bug s dopadem** nebo opakování — založ soubor v `docs/superpowers/errors/`.
4. **Po milestone** — aktualizuj `docs/as-is/` a sekci Odchylky (viz `as-is-on-milestone.md`).
5. **Architektonické rozhodnutí** — zapiš do `docs/decisions/<oblast>.md`, **nikdy do `CLAUDE.md`**.
   Ten se načítá do kontextu při každé session; do 2026-08-14 na tom vyrostl na 155 KB.
6. **Před zásahem do oblasti** si přečti její soubor v `docs/decisions/`.

## Co neplést

- Spec ≠ plán ≠ as-is ≠ rozhodnutí.
- As-is nekopíruje celý spec — popisuje realitu a odchylky.
- As-is říká **co** je hotové, rozhodnutí říká **proč** je to takhle.

## Šablony

- `docs/superpowers/specs/README.md`
- `docs/superpowers/plans/README.md`
- `docs/superpowers/errors/README.md`
- `docs/as-is/README.md`
