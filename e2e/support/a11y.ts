import AxeBuilder from '@axe-core/playwright'
import { expect } from '@playwright/test'
import type { Page } from '@playwright/test'

/**
 * WCAG 2.2 AA is a legal obligation here (EAA). Scoped to the tag set the
 * project has actually adopted — axe's full default rule set also includes
 * best-practice rules nobody has signed up to enforce, and an axe upgrade
 * adding one of those would turn every page in the suite red over a rule
 * this project never agreed to gate on.
 */
export const STANDARD = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']

/**
 * Only critical and serious block the build.
 *
 * A suite that goes red over something nobody is going to fix gets skipped,
 * and a skipped suite misses the finding that mattered (wave 3.4). Lower
 * severities are printed so they stay visible without holding anything up.
 *
 * Shared by accessibility.spec.ts and rich-text-editor.spec.ts — a second,
 * independently maintained copy of this function is how the two drifted
 * once already (the rich-text-editor copy dropped `.withTags(STANDARD)`,
 * silently widening what it gated on).
 */
export async function auditPage(page: Page, label: string): Promise<void> {
  const results = await new AxeBuilder({ page }).withTags(STANDARD).analyze()

  const blocking = results.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious')
  const minor = results.violations.filter((v) => v.impact !== 'critical' && v.impact !== 'serious')

  if (minor.length > 0) {
    console.log(`[a11y] ${label}: ${minor.length} minor issue(s): ${minor.map((v) => v.id).join(', ')}`)
  }

  expect(
    blocking.map((v) => `${v.id} (${v.impact}) — ${v.nodes.length}× — ${v.help}`),
    `${label} has blocking accessibility violations`,
  ).toEqual([])
}
