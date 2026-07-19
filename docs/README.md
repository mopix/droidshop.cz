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

## As-is

- [`as-is/STATUS.md`](as-is/STATUS.md) — stav oblastí (zatím bootstrap)

## Ostatní složky

| Složka | Účel |
|--------|------|
| [`design-droidshop/`](design-droidshop/) | Design handoff (zatím prázdné) |
| [`future/`](future/) | Post-MVP specifikace |
| [`legal/`](legal/) | Právní / setup návody |
| [`SETUP.md`](SETUP.md) | Lokální instalace (z šablony) |
| [`DEPLOY-TO-PROJECT.md`](DEPLOY-TO-PROJECT.md) | Jak se šablona Claude nasazovala |

## Pravidla a workflow

- [`.claude/rules/storefront-rendering.md`](../.claude/rules/storefront-rendering.md) — **storefront = Blade SSR (SEO), závazné**
- [`.claude/rules/structured-workflow.md`](../.claude/rules/structured-workflow.md)
- [`.claude/rules/documentation-layers.md`](../.claude/rules/documentation-layers.md)
- [`.claude/rules/as-is-on-milestone.md`](../.claude/rules/as-is-on-milestone.md)
- [`.claude/skills/versioning/SKILL.md`](../.claude/skills/versioning/SKILL.md)
