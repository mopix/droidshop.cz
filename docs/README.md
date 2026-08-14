# Dokumentace — DroidShop.cz

Rozcestník. Severka: [`../CLAUDE.md`](../CLAUDE.md).

## Produkt

- [`specs/2026-07-17-eshop-platforma-specifikace.md`](specs/2026-07-17-eshop-platforma-specifikace.md) — Level 3 funkční specifikace platformy (zdroj pravdy pro MVP)
- [`PROJECT-PROFILE.md`](PROJECT-PROFILE.md) — stack / cesty / nástroje

## Vrstvy dokumentace

Pravidla: [`DOCUMENTATION-LAYERS.md`](DOCUMENTATION-LAYERS.md).

| Vrstva | Složka | Co tam patří |
|--------|--------|--------------|
| Spec (zadání vlny) | [`superpowers/specs/`](superpowers/specs/) | Co má systém dělat v dané vlně |
| Plán | [`superpowers/plans/`](superpowers/plans/) | Jak sprint zrealizujeme |
| Chyby / incidenty | [`superpowers/errors/`](superpowers/errors/) | Bugy, root cause, prevence |
| As-is (realita) | [`as-is/`](as-is/) | Jak to po implementaci opravdu je |
| **Rozhodnutí** | [`decisions/`](decisions/) | **Proč** je to udělané takhle — a co se stane, když se to udělá jinak |

## Rozhodnutí

- [`decisions/`](decisions/) — 206 architektonických rozhodnutí po oblastech. **Před zásahem do
  oblasti si přečti její soubor** — většina položek popisuje past, do které se už jednou šláplo.
  Do 2026-08-14 tahle sbírka žila v `CLAUDE.md` a stála 125 KB kontextu při každé session.

## As-is

- [`as-is/STATUS.md`](as-is/STATUS.md) — stav oblastí
- [`as-is/2026-08-14-prehled-vln.md`](as-is/2026-08-14-prehled-vln.md) — prozaický přehled vln 0.1–3.9

## Ostatní složky

| Složka | Účel |
|--------|------|
| [`design-droidshop/`](design-droidshop/) | Design handoff (zatím prázdné) |
| [`future/`](future/) | Post-MVP specifikace |
| [`legal/`](legal/) | **Právní dokumenty platformy** — VOP, zásady zpracování údajů, zpracovatelská smlouva (GDPR čl. 28), cookies. Zdroj pravdy pro stránky `/pravni/*` |
| [`SETUP.md`](SETUP.md) | Lokální instalace (z šablony) |
| [`DEPLOY-TO-PROJECT.md`](DEPLOY-TO-PROJECT.md) | Jak se šablona Claude nasazovala |

## Pravidla a workflow

- [`.claude/rules/storefront-rendering.md`](../.claude/rules/storefront-rendering.md) — **storefront = Blade SSR (SEO), závazné**
- [`.claude/rules/structured-workflow.md`](../.claude/rules/structured-workflow.md)
- [`.claude/rules/documentation-layers.md`](../.claude/rules/documentation-layers.md)
- [`.claude/rules/as-is-on-milestone.md`](../.claude/rules/as-is-on-milestone.md)
- [`.claude/skills/versioning/SKILL.md`](../.claude/skills/versioning/SKILL.md)
- [`../README.md`](../README.md#agentské-pluginy-claude-code) — zapnutí/vypnutí marketing pluginu + [`.agents/product-marketing.md`](../.agents/product-marketing.md)
