<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import SettingsPage from '@/Components/Settings/SettingsPage.vue'

defineProps<{
  modules: { key: string; name: string }[]
}>()
</script>

<template>
  <AdminLayout title="Nastavení modulů">
<SettingsPage title="Nastavení modulů" description="Moduly, které váš e-shop provozuje a které mají co nastavovat.">
      <ul v-if="modules.length" class="divide-y divide-gray-200 rounded-md border border-gray-200">
        <li v-for="module in modules" :key="module.key">
          <Link
            :href="route('admin.settings.modules.edit', module.key)"
            class="flex items-center justify-between px-4 py-3 text-sm font-medium text-gray-900 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
          >
            {{ module.name }}
            <span aria-hidden="true" class="text-gray-400">›</span>
          </Link>
        </li>
      </ul>

      <p v-else class="rounded-md border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-600">
        Žádný z modulů vašeho e-shopu nemá nastavení, které byste mohli měnit.
      </p>
    </SettingsPage>
  </AdminLayout>
</template>
