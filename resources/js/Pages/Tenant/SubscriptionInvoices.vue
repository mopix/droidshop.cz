<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import SettingsPage from '@/Components/Settings/SettingsPage.vue'

defineProps<{
  invoices: Array<{ id: number; number: string; total: number; issued_at: string }>
}>()

const money = (h: number) => (h / 100).toLocaleString('cs-CZ', { style: 'currency', currency: 'CZK' })
</script>

<template>
  <AdminLayout title="Faktury za předplatné">
<SettingsPage title="Faktury za předplatné" description="Přehled faktur, které vám platforma vystavila za předplatné.">
      <p v-if="invoices.length === 0" class="text-sm text-gray-500">Zatím žádné faktury.</p>

      <ul v-else class="space-y-2">
        <li
          v-for="invoice in invoices"
          :key="invoice.id"
          class="flex items-center justify-between rounded-md border border-gray-200 p-3"
        >
          <span class="text-sm text-gray-900">{{ invoice.number }} — {{ money(invoice.total) }}</span>
          <a
            :href="`/admin/predplatne/faktury/${invoice.id}/pdf`"
            class="text-sm font-medium text-gray-900 underline hover:no-underline"
          >
            Stáhnout PDF
          </a>
        </li>
      </ul>
    </SettingsPage>
  </AdminLayout>
</template>
