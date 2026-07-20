<script setup lang="ts">
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import PlatformLayout from '@/Layouts/PlatformLayout.vue'

defineProps<{
  admin: { name: string; email: string }
}>()

const page = usePage()

// Shown once, right after 2FA setup — never retrievable again.
const recoveryCodes = computed(
  () => (page.props.flash as { recoveryCodes?: string[] } | undefined)?.recoveryCodes ?? null,
)
</script>

<template>
  <PlatformLayout title="Přehled platformy">
    <template #header>
      <h1 class="text-xl font-semibold text-gray-900">Přehled platformy</h1>
    </template>

    <section
      v-if="recoveryCodes"
      class="mb-6 rounded-lg border border-amber-400 bg-amber-50 p-4"
      aria-labelledby="recovery-heading"
    >
      <h2 id="recovery-heading" class="text-base font-semibold text-amber-900">
        Obnovovací kódy
      </h2>
      <p class="mt-1 text-sm text-amber-900">
        Uložte si je na bezpečné místo. Zobrazují se jen jednou.
      </p>
      <ul class="mt-3 grid grid-cols-1 gap-1 sm:grid-cols-2">
        <li v-for="code in recoveryCodes" :key="code">
          <code class="font-mono text-sm text-amber-900">{{ code }}</code>
        </li>
      </ul>
    </section>

    <div class="grid gap-4 sm:grid-cols-2">
      <Link
        :href="route('platform.tenants.index')"
        class="block rounded-lg border border-gray-200 bg-white p-5 shadow-sm hover:border-slate-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
      >
        <span class="text-base font-semibold text-gray-900">Tenanti</span>
        <span class="mt-1 block text-sm text-gray-600">
          Seznam e-shopů, stavy a životní cyklus.
        </span>
      </Link>

      <Link
        :href="route('platform.modules.index')"
        class="block rounded-lg border border-gray-200 bg-white p-5 shadow-sm hover:border-slate-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
      >
        <span class="text-base font-semibold text-gray-900">Moduly</span>
        <span class="mt-1 block text-sm text-gray-600">
          Dostupné moduly a jejich aktivace pro tenanty.
        </span>
      </Link>
    </div>
  </PlatformLayout>
</template>
