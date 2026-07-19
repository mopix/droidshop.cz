# Vrstvy dokumentace — DroidShop.cz

Oddělení **zadání**, **plánu**, **chyb** a **as-is reality**.

## Čtyři vrstvy + status

| Vrstva | Role | Složka | Formát souboru |
|--------|------|--------|----------------|
| **Spec** | Co má systém dělat; závazné zadání vlny | `docs/superpowers/specs/` | `YYYY-MM-DD-nazev.md` |
| **Plán** | Jak tuto vlnu / sprint zrealizujeme | `docs/superpowers/plans/` | `YYYY-MM-DD-nazev.md` |
| **Chyby** | Incidenty, bugy, analýza, workaroundy | `docs/superpowers/errors/` | `YYYY-MM-DD-error-cislo-nazev.md` |
| **As-is** | Jak to po implementaci opravdu je | `docs/as-is/` | `YYYY-MM-DD-nazev.md` |

Volitelně: **`docs/as-is/STATUS.md`** — rozcestník stavu oblastí.

**Produktová Level 3 specifikace** (celá platforma) žije v [`docs/specs/`](specs/) — není to „vlna", ale dlouhodobý zdroj pravdy. Vlny v `superpowers/specs/` z ní vycházejí.

## Pořadí čtení

1. [`CLAUDE.md`](../CLAUDE.md) — severka
2. [`PROJECT-PROFILE.md`](PROJECT-PROFILE.md) — stack
3. Produktová spec v `docs/specs/` (pokud jde o produktové chování)
4. **Spec** vlny → **plán** → **as-is** (jak to **je**)
5. **Errors** při debugování

## Vztah spec ↔ plán

- Jeden spec může mít jeden nebo více plánů.
- Doporučené pojmenování: stejný datum + slug.

## As-is a odchylky od spec

Na konci každého `docs/as-is/YYYY-MM-DD-nazev.md`:

```markdown
## Odchylky od specifikace

- **<stručný popis>** — odkaz na spec
  **Důvod:** <proč>.
  **Datum:** YYYY-MM-DD.
```

## Co nepatří do spec vlny

- Globální produktová spec → `docs/specs/`
- Nápady mimo MVP → `docs/future/`
- Konvence kódu → `.claude/rules/`
- Setup návody → `docs/legal/`, `docs/SETUP.md`
