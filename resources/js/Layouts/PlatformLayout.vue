<script setup lang="ts">
import { computed } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import SideNav, { type NavItem } from '@/Layouts/Partials/SideNav.vue'
import TopBar from '@/Layouts/Partials/TopBar.vue'
import { useSideNav } from '@/composables/useSideNav'

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

/**
 * No sections here.
 *
 * The platform console has six screens; sections over six items are filing
 * for the sake of filing. The layout is shared with the tenant admin — that
 * is what the owner asked for — but the grouping is not.
 */
const topItems: NavItem[] = [
  { label: 'E-shopy', route: 'platform.tenants.index', icon: 'package', active: 'platform.tenants.*' },
  { label: 'Moduly', route: 'platform.modules.index', icon: 'sliders', active: 'platform.modules.*' },
  { label: 'Tarify', route: 'platform.plans.index', icon: 'tag', active: 'platform.plans.*' },
]

const nav = useSideNav(() => [])
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

    <div class="flex min-h-screen">
      <!-- Dark, deliberately unlike the tenant admin: the two consoles do
           different jobs and mistaking one for the other is how somebody
           suspends the wrong shop. The owner asked to unify the layout, not
           the colour. -->
      <SideNav
        :top="topItems"
        :groups="[]"
        :account="[]"
        :sign-out-route="route('platform.logout')"
        variant="platform"
        :open-groups="nav.openGroups.value"
        :collapsed="nav.collapsed.value"
        :drawer-open="nav.drawerOpen.value"
        @toggle-group="nav.toggleGroup"
        @toggle-collapsed="nav.toggleCollapsed"
        @close-drawer="nav.closeDrawer"
      />

      <div class="flex min-w-0 flex-1 flex-col">
        <TopBar
          title="Správa platformy"
          :user-name="admin?.name ?? null"
          :profile-route="null"
          :sign-out-route="route('platform.logout')"
          variant="platform"
          @open-drawer="nav.openDrawer"
        />

        <p
          v-if="impersonating"
          class="bg-red-800 px-4 py-2 text-center text-sm font-semibold text-white"
        >
          Jednáte jako uživatel #{{ impersonating.user_id }} (impersonace správcem platformy).
        </p>

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

          <main id="platform-content">
            <slot />
          </main>
        </div>
      </div>
    </div>
  </div>
</template>
