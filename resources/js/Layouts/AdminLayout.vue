<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'

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
}

type TenantProps = {
  name: string
  nav: NavEntry[]
  permissions: string[]
}

const page = usePage()

const user = computed(() => (page.props.auth as { user?: { name: string } }).user ?? null)

const tenant = computed(() => (page.props.tenant as TenantProps | null) ?? null)

const flash = computed(
  () => (page.props.flash as { success?: string; error?: string } | undefined) ?? {},
)

const impersonating = computed(
  () => (page.props.impersonating as { user_id: number; admin_id: number } | null) ?? null,
)

const billingProfileComplete = computed(() => page.props.billingProfileComplete as boolean)

// Ziggy's route() helper is a global template property only, so URLs are
// resolved in the template and handed to these actions.
const logout = (url: string) => router.post(url)
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

    <!-- role="alert" so a screen reader announces the change of identity the
         moment the banner appears, not only if the user happens to read it. -->
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
      v-if="!billingProfileComplete"
      role="status"
      class="bg-amber-100 px-4 py-3 text-center text-sm text-amber-900"
    >
      Doplňte prosím
      <Link :href="route('admin.billing.edit')" class="font-semibold underline hover:no-underline">
        fakturační údaje
      </Link>
      , jinak nelze vystavit fakturu ani aktivovat předplatné.
    </div>

    <!-- Light bar, deliberately unlike the platform console's dark one: the
         two are different jobs and must never be confused at a glance. -->
    <header class="border-b border-gray-200 bg-white">
      <div
        class="mx-auto flex max-w-7xl flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8"
      >
        <span class="text-sm font-bold uppercase tracking-widest text-gray-700">
          {{ tenant?.name ?? 'E-shop' }}
        </span>

        <div class="flex items-center gap-3">
          <span v-if="user" class="text-sm text-gray-600">{{ user.name }}</span>

          <Link
            :href="route('profile.edit')"
            class="rounded-md px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
          >
            Profil
          </Link>

          <button
            type="button"
            class="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
            @click="logout(route('logout'))"
          >
            Odhlásit
          </button>
        </div>
      </div>
    </header>

    <div class="mx-auto flex max-w-7xl flex-col gap-6 px-4 py-6 sm:px-6 lg:flex-row lg:px-8">
      <!-- The menu is built from the manifests of the modules this shop runs,
           so a deactivated module leaves no dangling link behind. -->
      <nav aria-label="Navigace správy e-shopu" class="lg:w-56 lg:flex-none">
        <ul class="flex flex-wrap gap-1 lg:flex-col">
          <li>
            <Link
              :href="route('dashboard')"
              :aria-current="route().current('dashboard') ? 'page' : undefined"
              class="block rounded-md px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 aria-[current=page]:bg-gray-900 aria-[current=page]:text-white"
            >
              Přehled
            </Link>
          </li>
          <li v-for="entry in tenant?.nav ?? []" :key="entry.route">
            <Link
              :href="route(entry.route)"
              :aria-current="route().current(entry.route) ? 'page' : undefined"
              class="block rounded-md px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 aria-[current=page]:bg-gray-900 aria-[current=page]:text-white"
            >
              {{ entry.label }}
            </Link>
          </li>
        </ul>
      </nav>

      <div class="min-w-0 flex-1">
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
</template>
