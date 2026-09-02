<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import SideNav, { type NavGroup, type NavItem } from '@/Layouts/Partials/SideNav.vue'
import TopBar from '@/Layouts/Partials/TopBar.vue'
import { useSideNav } from '@/composables/useSideNav'

withDefaults(
  defineProps<{
    title?: string
  }>(),
  { title: 'Správa e-shopu' },
)

type NavEntry = {
  module: string
  label: string
  route: string
  icon: string | null
  order: number
  group: string
}

type TenantProps = {
  name: string
  nav: NavEntry[]
  navGroups: { key: string; label: string; items: NavEntry[] }[]
  permissions: string[]
}

const page = usePage()

const user = computed(() => (page.props.auth as { user?: { name: string } }).user ?? null)

const tenant = computed(() => (page.props.tenant as TenantProps | null) ?? null)

const flash = computed(
  () =>
    (page.props.flash as { success?: string; error?: string; status?: string } | undefined) ?? {},
)

const impersonating = computed(
  () => (page.props.impersonating as { user_id: number; admin_id: number } | null) ?? null,
)

const billingProfileComplete = computed(() => page.props.billingProfileComplete as boolean)

const trialDaysLeft = computed(() => page.props.trialDaysLeft as number | null)

const subscriptionActive = computed(() => page.props.subscriptionActive as boolean)

/**
 * The dashboard sits above the sections, with no heading of its own.
 *
 * `admin.home`, not `dashboard`: the latter is "Moje e-shopy", the list of
 * shops this user owns, which lives on the platform host and takes the owner
 * out of the shop they are administering.
 */
const topItems = computed<NavItem[]>(() =>
  tenant.value === null ? [] : [{ label: 'Nástěnka', route: 'admin.home', icon: 'gauge' }],
)

/**
 * Entries the kernel owns.
 *
 * They belong to no module, so NavigationBuilder does not know about them —
 * it builds the menu from manifests, and a screen with no manifest would have
 * to be invented one. They are merged in here instead, into the sections the
 * owner asked for.
 */
const CORE_ENTRIES: Record<string, NavItem[]> = {
  modules: [
    {
      label: 'Nastavení modulů',
      route: 'admin.settings.modules.index',
      icon: 'sliders',
      active: 'admin.settings.modules.*',
    },
  ],
  settings: [
    { label: 'Obchod', route: 'admin.shop.edit', icon: 'store' },
    { label: 'Kontakty', route: 'admin.contacts.edit', icon: 'contact' },
    { label: 'SEO', route: 'admin.seo.edit', icon: 'search' },
    { label: 'Zobrazení', route: 'admin.display.edit', icon: 'eye' },
    { label: 'Doména', route: 'admin.domain.edit', icon: 'globe' },
    { label: 'Vzhled', route: 'admin.appearance.edit', icon: 'palette' },
    { label: 'Export dat', route: 'admin.export.show', icon: 'download' },
    // Reachable until now only from the banner that nags about it — a merchant
    // who filled it in once could not find it again.
    { label: 'Fakturační údaje', route: 'admin.billing.edit', icon: 'credit-card' },
  ],
}

/**
 * The sections, with the kernel's own entries folded in.
 *
 * A section that exists only because of a kernel entry still shows: MODULY
 * holds "Nastavení modulů" even in a shop that runs no optional module, and
 * NASTAVENÍ holds the domain and appearance screens, which every shop has.
 */
const groups = computed<NavGroup[]>(() => {
  // No tenant means the platform host — "Moje e-shopy", where the user picks
  // a shop rather than administering one. The tenant routes are not even
  // registered there, so resolving them would throw, not merely link nowhere.
  if (tenant.value === null) {
    return []
  }

  const fromModules = tenant.value.navGroups ?? []
  const seen = new Set(fromModules.map((g) => g.key))

  const merged: NavGroup[] = fromModules.map((group) => ({
    key: group.key,
    label: group.label,
    items: [...(CORE_ENTRIES[group.key] ?? []), ...group.items],
  }))

  // Sections the kernel contributes to that no module happened to fill.
  const LABELS: Record<string, string> = { modules: 'Moduly', settings: 'Nastavení' }
  const ORDER = ['products', 'orders', 'modules', 'settings']

  for (const key of Object.keys(CORE_ENTRIES)) {
    if (!seen.has(key)) {
      merged.push({ key, label: LABELS[key] ?? key, items: CORE_ENTRIES[key] })
    }
  }

  return merged.sort((a, b) => ORDER.indexOf(a.key) - ORDER.indexOf(b.key))
})

const accountItems: NavItem[] = [{ label: 'Profil', route: 'profile.edit', icon: 'user' }]

/** Whether this page is inside a shop at all. */
const inShop = computed(() => tenant.value !== null)

const nav = useSideNav(() =>
  groups.value.filter((g) => g.items.some((i) => route().current(i.active ?? i.route))).map((g) => g.key),
)

const stopImpersonating = (url: string) => router.post(url)
</script>

<template>
  <Head :title="title">
    <!-- The whole back office stays out of the index (storefront rule, part C). -->
    <meta name="robots" content="noindex, nofollow" />
  </Head>

  <div class="min-h-screen bg-gray-100 text-gray-900">
    <a
      href="#admin-content"
      class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-white focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-gray-900 focus:ring-2 focus:ring-gray-900"
    >
      Přeskočit na obsah
    </a>

    <div class="flex min-h-screen">
      <SideNav
        v-if="inShop"
        :top="topItems"
        :groups="groups"
        :account="accountItems"
        :sign-out-route="route('logout')"
        variant="tenant"
        :open-groups="nav.openGroups.value"
        :collapsed="nav.collapsed.value"
        :drawer-open="nav.drawerOpen.value"
        @toggle-group="nav.toggleGroup"
        @toggle-collapsed="nav.toggleCollapsed"
        @close-drawer="nav.closeDrawer"
      />

      <!-- min-w-0 so a wide table inside scrolls on its own instead of
           stretching the whole layout past the viewport. -->
      <div class="flex min-w-0 flex-1 flex-col">
        <TopBar
          :title="tenant?.name ?? 'E-shop'"
          :user-name="user?.name ?? null"
          :profile-route="route('profile.edit')"
          :has-drawer="inShop"
          :sign-out-route="route('logout')"
          variant="tenant"
          @open-drawer="nav.openDrawer"
        />

        <!-- role="alert" so a screen reader announces the change of identity
             the moment the banner appears, not only if the user reads it. -->
        <div
          v-if="impersonating"
          role="alert"
          class="flex flex-wrap items-center justify-center gap-3 bg-red-800 px-4 py-2 text-center text-sm font-semibold text-white"
        >
          <span>Jste přihlášeni jako cizí uživatel (impersonace správcem platformy).</span>
          <button
            type="button"
            class="rounded-md border border-white px-3 py-2 text-sm font-semibold hover:bg-red-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-red-800"
            @click="stopImpersonating(route('impersonation.end'))"
          >
            Ukončit impersonaci
          </button>
        </div>

        <!-- role="status" (not "alert"): a missing billing profile is not an
             error the user made just now, so it should not interrupt a screen
             reader the way the impersonation banner does. -->
        <div
          v-if="inShop && !billingProfileComplete"
          role="status"
          class="bg-amber-100 px-4 py-3 text-center text-sm text-amber-900"
        >
          Doplňte prosím
          <Link
            :href="route('admin.billing.edit')"
            class="font-semibold underline hover:no-underline"
          >
            fakturační údaje
          </Link>
          , jinak nelze vystavit fakturu ani aktivovat předplatné.
        </div>

        <!-- role="status": informational countdown, not an error. Hidden once
             a subscription is active even if trialDaysLeft is still set, so
             the two banners never talk past each other. -->
        <div
          v-if="inShop && trialDaysLeft !== null && !subscriptionActive"
          role="status"
          class="bg-blue-50 px-4 py-3 text-center text-sm text-blue-900"
        >
          Zkušební období: zbývá {{ trialDaysLeft }} dní.
          <Link :href="route('admin.subscription')" class="font-semibold underline hover:no-underline">
            Aktivovat předplatné
          </Link>
        </div>

        <div class="min-w-0 flex-1 px-4 py-6 sm:px-6">
          <p
            v-if="flash.success"
            role="status"
            aria-live="polite"
            aria-atomic="true"
            class="mb-6 rounded-md border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-900"
          >
            {{ flash.success }}
          </p>
          <p
            v-else-if="flash.status"
            role="status"
            aria-live="polite"
            aria-atomic="true"
            class="mb-6 rounded-md border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-900"
          >
            <!--
              Modules\Packeta\Http\Controllers\ShipmentAdminController flashes
              its batch-submit outcome under 'status' (the Blade-page
              convention, shared with Laravel's own auth controllers), not
              'success' — every page that submits a shipment now gets one
              place that shows it.
            -->
            {{ flash.status }}
          </p>
          <p
            v-if="flash.error"
            role="alert"
            aria-live="assertive"
            aria-atomic="true"
            class="mb-6 rounded-md border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-900"
          >
            {{ flash.error }}
          </p>

          <header v-if="$slots.header" class="mb-6">
            <slot name="header" />
          </header>

          <main id="admin-content">
            <slot />
          </main>
        </div>
      </div>
    </div>
  </div>
</template>
