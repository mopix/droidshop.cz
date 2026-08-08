<script setup lang="ts">
import { computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { ChevronDown, PanelLeftClose, PanelLeftOpen, X } from 'lucide-vue-next'
import NavIcon from '@/Components/Ui/NavIcon.vue'

export type NavItem = {
  label: string
  route: string
  icon: string | null
  /** Route pattern for the active state; defaults to an exact match. */
  active?: string
}

export type NavGroup = {
  key: string
  label: string
  items: NavItem[]
}

/**
 * The side navigation.
 *
 * Deliberately controlled: the state lives in the layout, because the
 * hamburger that opens this on mobile sits in the top bar and the two have to
 * agree. A component that owned its own open/closed state would need the top
 * bar to reach into it.
 */
const props = defineProps<{
  /** Entries shown above the sections, without a heading. */
  top: NavItem[]
  groups: NavGroup[]
  /** Profile and the like, pinned to the bottom next to sign-out. */
  account: NavItem[]
  signOutRoute: string
  variant: 'tenant' | 'platform'
  openGroups: string[]
  collapsed: boolean
  drawerOpen: boolean
}>()

const emit = defineEmits<{
  (e: 'toggle-group', key: string): void
  (e: 'toggle-collapsed'): void
  (e: 'close-drawer'): void
}>()

const isActive = (item: NavItem): boolean => route().current(item.active ?? item.route)

const isOpen = (key: string): boolean => props.openGroups.includes(key)

const signOut = (): void => {
  router.post(props.signOutRoute)
}

const shell = computed(() =>
  props.variant === 'platform'
    ? 'bg-gray-900 text-gray-100 border-gray-800'
    : 'bg-white text-gray-900 border-gray-200',
)

const itemClass = computed(() =>
  props.variant === 'platform'
    ? 'text-gray-300 hover:bg-gray-800 hover:text-white aria-[current=page]:bg-gray-700 aria-[current=page]:text-white'
    : 'text-gray-700 hover:bg-gray-100 aria-[current=page]:bg-gray-900 aria-[current=page]:text-white',
)

const headingClass = computed(() =>
  props.variant === 'platform'
    ? 'text-gray-400 hover:text-white'
    : 'text-gray-500 hover:text-gray-900',
)
</script>

<template>
  <!-- Mobile: an overlay drawer. The button that opens it is in the top bar. -->
  <div
    v-if="drawerOpen"
    class="fixed inset-0 z-40 bg-black/50 lg:hidden"
    aria-hidden="true"
    @click="emit('close-drawer')"
  />

  <nav
    :aria-label="variant === 'platform' ? 'Navigace správy platformy' : 'Navigace správy e-shopu'"
    class="fixed inset-y-0 left-0 z-40 flex w-64 shrink-0 flex-col border-r transition-[width,transform] duration-200 lg:visible lg:sticky lg:top-0 lg:h-screen lg:translate-x-0"
    :class="[
      shell,
      collapsed ? 'lg:w-16' : 'lg:w-64',
      // `invisible`, not just the off-screen transform. A menu pushed out of
      // view is still in the tab order and still read by a screen reader, so
      // a keyboard user on a phone would tab through a menu they cannot see.
      // visibility is animatable, so the slide-in survives.
      drawerOpen ? 'visible translate-x-0' : 'invisible -translate-x-full',
    ]"
  >
    <!-- Mobile close button; from lg up the drawer does not exist. -->
    <div class="flex h-14 items-center justify-between px-3 lg:hidden">
      <span class="text-sm font-bold uppercase tracking-widest">Menu</span>
      <button
        type="button"
        class="rounded-md p-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-current"
        :class="variant === 'platform' ? 'hover:bg-gray-800' : 'hover:bg-gray-100'"
        @click="emit('close-drawer')"
      >
        <span class="sr-only">Zavřít menu</span>
        <X class="h-5 w-5" aria-hidden="true" />
      </button>
    </div>

    <div class="flex-1 overflow-y-auto px-2 py-3">
      <ul class="space-y-1">
        <li v-for="item in top" :key="item.route">
          <Link
            :href="route(item.route)"
            :aria-current="isActive(item) ? 'page' : undefined"
            :aria-label="collapsed ? item.label : undefined"
            :title="collapsed ? item.label : undefined"
            class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-current"
            :class="[itemClass, collapsed ? 'lg:justify-center lg:px-0' : '']"
            @click="emit('close-drawer')"
          >
            <NavIcon :name="item.icon" />
            <span :class="collapsed ? 'lg:hidden' : ''">{{ item.label }}</span>
          </Link>
        </li>
      </ul>

      <div v-for="group in groups" :key="group.key" class="mt-4">
        <!--
          A real <button> with aria-expanded and aria-controls rather than a
          div with a click handler: this is the only way into the section with
          a keyboard or a screen reader, and the heading is not a link — it
          has no page of its own.
        -->
        <button
          type="button"
          class="flex w-full items-center justify-between rounded-md px-3 py-1.5 text-xs font-semibold uppercase tracking-wider focus:outline-none focus-visible:ring-2 focus-visible:ring-current"
          :class="[headingClass, collapsed ? 'lg:hidden' : '']"
          :aria-expanded="isOpen(group.key)"
          :aria-controls="`nav-group-${group.key}`"
          @click="emit('toggle-group', group.key)"
        >
          {{ group.label }}
          <ChevronDown
            class="h-4 w-4 transition-transform"
            :class="isOpen(group.key) ? 'rotate-180' : ''"
            aria-hidden="true"
          />
        </button>

        <!--
          Collapsed to a rail there is no room for headings, so every entry
          shows: hiding them behind a section that cannot be opened would put
          half the admin out of reach.
        -->
        <ul
          :id="`nav-group-${group.key}`"
          v-show="collapsed || isOpen(group.key)"
          class="mt-1 space-y-1"
          :class="collapsed ? 'border-t border-current/10 pt-2' : ''"
        >
          <li v-for="item in group.items" :key="item.route">
            <Link
              :href="route(item.route)"
              :aria-current="isActive(item) ? 'page' : undefined"
              :aria-label="collapsed ? `${group.label}: ${item.label}` : undefined"
              :title="collapsed ? item.label : undefined"
              class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-current"
              :class="[itemClass, collapsed ? 'lg:justify-center lg:px-0' : '']"
              @click="emit('close-drawer')"
            >
              <NavIcon :name="item.icon" />
              <span :class="collapsed ? 'lg:hidden' : ''">{{ item.label }}</span>
            </Link>
          </li>
        </ul>
      </div>
    </div>

    <!-- Profile and sign-out. Also in the top bar: the owner asked for both,
         so neither placement has to be discovered. -->
    <div
      class="border-t px-2 py-3"
      :class="variant === 'platform' ? 'border-gray-800' : 'border-gray-200'"
    >
      <ul class="space-y-1">
        <li v-for="item in account" :key="item.route">
          <Link
            :href="route(item.route)"
            :aria-label="collapsed ? item.label : undefined"
            :title="collapsed ? item.label : undefined"
            class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-current"
            :class="[itemClass, collapsed ? 'lg:justify-center lg:px-0' : '']"
            @click="emit('close-drawer')"
          >
            <NavIcon :name="item.icon" />
            <span :class="collapsed ? 'lg:hidden' : ''">{{ item.label }}</span>
          </Link>
        </li>
        <li>
          <button
            type="button"
            class="flex w-full items-center gap-3 rounded-md px-3 py-2 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-current"
            :class="[itemClass, collapsed ? 'lg:justify-center lg:px-0' : '']"
            :aria-label="collapsed ? 'Odhlásit' : undefined"
            :title="collapsed ? 'Odhlásit' : undefined"
            @click="signOut"
          >
            <NavIcon name="log-out" />
            <span :class="collapsed ? 'lg:hidden' : ''">Odhlásit</span>
          </button>
        </li>
      </ul>

      <!-- Only from lg up: below that the menu is a drawer, where collapsing
           to a rail would mean an overlay taking space and showing nothing. -->
      <button
        type="button"
        class="mt-2 hidden w-full items-center gap-3 rounded-md px-3 py-2 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-current lg:flex"
        :class="[itemClass, collapsed ? 'lg:justify-center lg:px-0' : '']"
        :aria-pressed="collapsed"
        @click="emit('toggle-collapsed')"
      >
        <PanelLeftOpen v-if="collapsed" class="h-5 w-5" aria-hidden="true" />
        <PanelLeftClose v-else class="h-5 w-5" aria-hidden="true" />
        <span :class="collapsed ? 'sr-only' : ''">{{ collapsed ? 'Rozšířit menu' : 'Zúžit menu' }}</span>
      </button>
    </div>
  </nav>
</template>
