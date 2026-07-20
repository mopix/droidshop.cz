<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'

withDefaults(
  defineProps<{
    title?: string
  }>(),
  { title: 'Správa platformy' },
)

type Admin = { name: string; email: string }

const page = usePage()

const admin = computed(() => (page.props.admin as Admin | undefined) ?? null)

const flash = computed(
  () => (page.props.flash as { success?: string; error?: string } | undefined) ?? {},
)

const impersonating = computed(
  () => (page.props.impersonating as { user_id: number; admin_id: number } | null) ?? null,
)

// Ziggy's route() helper is registered as a global template property only,
// so the URL is resolved in the template and handed to this action.
const logout = (url: string) => router.post(url)
</script>

<template>
  <Head :title="title">
    <meta name="robots" content="noindex, nofollow" />
  </Head>

  <div class="min-h-screen bg-gray-100 text-gray-900">
    <a
      href="#platform-content"
      class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-white focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-gray-900 focus:ring-2 focus:ring-gray-900"
    >
      Přeskočit na obsah
    </a>

    <p
      v-if="impersonating"
      class="bg-red-800 px-4 py-2 text-center text-sm font-semibold text-white"
    >
      Jednáte jako uživatel #{{ impersonating.user_id }} (impersonace správcem platformy).
    </p>

    <!-- Dark bar deliberately separates the platform console from tenant admin. -->
    <header class="bg-slate-900 text-slate-100">
      <div
        class="mx-auto flex max-w-7xl flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8"
      >
        <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
          <span class="text-sm font-bold uppercase tracking-widest">
            DroidShop — platforma
          </span>

          <nav aria-label="Hlavní navigace platformy">
            <ul class="flex items-center gap-1">
              <li>
                <Link
                  :href="route('platform.tenants.index')"
                  :aria-current="route().current('platform.tenants.*') ? 'page' : undefined"
                  class="rounded-md px-3 py-2 text-sm font-medium text-slate-200 hover:bg-slate-800 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-slate-900 aria-[current=page]:bg-slate-800 aria-[current=page]:text-white"
                >
                  Tenanti
                </Link>
              </li>
              <li>
                <Link
                  :href="route('platform.modules.index')"
                  :aria-current="route().current('platform.modules.*') ? 'page' : undefined"
                  class="rounded-md px-3 py-2 text-sm font-medium text-slate-200 hover:bg-slate-800 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-slate-900 aria-[current=page]:bg-slate-800 aria-[current=page]:text-white"
                >
                  Moduly
                </Link>
              </li>
            </ul>
          </nav>
        </div>

        <div class="flex items-center gap-3">
          <span v-if="admin" class="text-sm text-slate-300">
            {{ admin.name }}
            <span class="hidden sm:inline">({{ admin.email }})</span>
          </span>

          <button
            type="button"
            class="rounded-md border border-slate-500 px-3 py-1.5 text-sm font-medium text-slate-100 hover:bg-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-slate-900"
            @click="logout(route('platform.logout'))"
          >
            Odhlásit
          </button>
        </div>
      </div>
    </header>

    <div
      v-if="flash.success || flash.error"
      class="mx-auto max-w-7xl px-4 pt-6 sm:px-6 lg:px-8"
    >
      <p
        v-if="flash.success"
        role="status"
        aria-live="polite"
        aria-atomic="true"
        class="rounded-md border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-900"
      >
        {{ flash.success }}
      </p>
      <p
        v-if="flash.error"
        role="alert"
        aria-live="assertive"
        aria-atomic="true"
        class="mt-3 rounded-md border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-900 first:mt-0"
      >
        {{ flash.error }}
      </p>
    </div>

    <header v-if="$slots.header" class="border-b border-gray-200 bg-white">
      <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        <slot name="header" />
      </div>
    </header>

    <main id="platform-content" class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
      <slot />
    </main>
  </div>
</template>
