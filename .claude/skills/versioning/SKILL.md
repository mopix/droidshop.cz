---
name: versioning
description: Use when committing changes, starting an implementation plan, or cutting a major release in the DroidShop project — governs how the VERSION file and CHANGELOG.md are bumped (semver).
---

# Versioning (DroidShop)

## Overview

The project version is a single semver number in the root `VERSION` file (source of truth). `CHANGELOG.md` records milestones. The version moves automatically on commits and deliberately on milestones.

**Core principle:** patch tracks every commit, minor tracks every implementation plan, major is a human decision.

## Quick Reference

| Bump | Trigger | Example | Who does it |
|---|---|---|---|
| **patch** `+0.0.1` | Every commit | `1.0.0 → 1.0.1` | `pre-commit` hook (automatic) |
| **minor** `+0.1.0` (patch → 0) | The moment a new implementation plan starts being written | `1.0.25 → 1.1.0` | Claude, manually, in that commit |
| **major** `+1.0.0` (minor+patch → 0) | Only when the user explicitly says it warrants a major release | `1.4.3 → 2.0.0` | Claude, only on explicit instruction |

## How patch works (automatic)

`.githooks/pre-commit` increments the patch on every commit and stages `VERSION`. No action needed. It is enabled per clone with:

```
git config core.hooksPath .githooks
```

The hook **skips** itself when `VERSION` is already staged — that is the signal that a manual minor/major bump is happening in this same commit. This is what keeps a deliberate `1.0.7 → 1.1.0` from becoming `1.1.1`.

## How minor works (implementation plans)

When you START creating an implementation plan (e.g. via the writing-plans skill), before committing the plan:

1. Read current `VERSION`.
2. Set it to the next minor with patch reset to `0` (`1.0.25 → 1.1.0`).
3. `git add VERSION` so the hook skips its patch bump.
4. Add a new dated section to `CHANGELOG.md` for the milestone.

## How major works

Only when the user explicitly states the work is a big enough leap. Set `X.0.0`, `git add VERSION`, add a CHANGELOG section. Never bump major on your own judgment.

## CHANGELOG scope

`CHANGELOG.md` gets a new section only at **minor and major** bumps. Per-commit patch detail lives in `git log` — do not add a CHANGELOG line per patch.

## Common Mistakes

- **Editing `VERSION` for a patch.** Don't — the hook owns patch. Only touch `VERSION` for minor/major.
- **Forgetting to `git add VERSION` after a manual minor/major edit.** Then the hook still skips (it greps staged files) — so always stage it; otherwise the manual bump won't be in the commit at all.
- **Bumping major without an explicit user instruction.** Major is never your call.
- **Adding a CHANGELOG entry for every commit.** Only milestones (minor/major) belong there.
