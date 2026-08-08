<script setup lang="ts">
import { computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { Menu } from 'lucide-vue-next'

/**
 * The bar across the top of the admin.
 *
 * Full width on every screen, including inside a module — the owner asked for
 * that explicitly. `variant` keeps the two admins visually apart: the
 * platform console is dark and the tenant admin is light, and confusing one
 * for the other is how somebody suspends the wrong shop.
 */
const props = defineProps<{
  title: string
  userName: string | null
  profileRoute: string | null
  signOutRoute: string
  variant: 'tenant' | 'platform'
  /** Whether there is a side navigation to open — false on the shop picker. */
  hasDrawer?: boolean
}>()

defineEmits<{ (e: 'open-drawer'): void }>()

const signOut = (): void => {
  router.post(props.signOutRoute)
}

const shell = computed(() =>
  props.variant === 'platform'
    ? 'border-gray-800 bg-gray-900 text-gray-100'
    : 'border-gray-200 bg-white text-gray-900',
)

const action = computed(() =>
  props.variant === 'platform'
    ? 'text-gray-300 hover:bg-gray-800 hover:text-white'
    : 'text-gray-700 hover:bg-gray-100',
)
</script>

<template>
  <header class="border-b" :class="shell">
    <div class="flex items-center gap-3 px-4 py-3 sm:px-6">
      <!-- Below lg the side navigation is an overlay, and this is what opens
           it. aria-expanded is on the button rather than the drawer because
           this is the control a screen reader reaches first. -->
      <button
        v-if="hasDrawer !== false"
        type="button"
        class="-ml-1 rounded-md p-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-current lg:hidden"
        :class="action"
        @click="$emit('open-drawer')"
      >
        <span class="sr-only">Otevřít menu</span>
        <Menu class="h-5 w-5" aria-hidden="true" />
      </button>

      <span class="truncate text-sm font-bold uppercase tracking-widest">{{ title }}</span>

      <div class="ml-auto flex items-center gap-2 sm:gap-3">
        <span v-if="userName" class="hidden text-sm sm:inline" :class="variant === 'platform' ? 'text-gray-400' : 'text-gray-600'">
          {{ userName }}
        </span>

        <Link
          v-if="profileRoute"
          :href="profileRoute"
          class="rounded-md px-3 py-1.5 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-current"
          :class="action"
        >
          Profil
        </Link>

        <button
          type="button"
          class="rounded-md border px-3 py-1.5 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-current"
          :class="[action, variant === 'platform' ? 'border-gray-700' : 'border-gray-300']"
          @click="signOut"
        >
          Odhlásit
        </button>
      </div>
    </div>
  </header>
</template>
