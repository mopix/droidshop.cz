import type { Page } from '@playwright/test'

/**
 * The three vendors the analytics module can load.
 *
 * Matched on hostname rather than full URL: what matters is whether the
 * browser tried to talk to them at all, not which script it asked for.
 */
export const VENDOR_HOSTS = [
  'googletagmanager.com',
  'google-analytics.com',
  'c.seznam.cz',
  'connect.facebook.net',
  'facebook.com',
] as const

export interface VendorWatch {
  /** Every vendor URL the page attempted, in order. */
  attempts: string[]
  /** Whether anything was attempted for a given hostname. */
  hit(host: string): boolean
  clear(): void
}

/**
 * Records — and blocks — every request to a measurement vendor.
 *
 * Blocking matters as much as recording. In CI nothing may actually leave the
 * machine: the suite would then depend on Google being up, and on being
 * allowed to talk to them at all. What is being tested is the browser's
 * ATTEMPT, which is precisely what ePrivacy cares about — a request carries
 * the visitor's IP whether or not anything comes back.
 */
export async function watchVendors(page: Page): Promise<VendorWatch> {
  const attempts: string[] = []

  for (const host of VENDOR_HOSTS) {
    await page.route(`**://*${host}/**`, async (route) => {
      attempts.push(route.request().url())

      // An empty 200 rather than abort(): a blocked request can make the page
      // log an error and, worse, can make a retry loop hammer the route —
      // which would make the count meaningless.
      await route.fulfill({ status: 200, contentType: 'application/javascript', body: '' })
    })
  }

  return {
    attempts,
    hit: (host: string) => attempts.some((url) => url.includes(host)),
    clear: () => {
      attempts.length = 0
    },
  }
}
