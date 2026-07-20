<script setup lang="ts">
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import DataTable, { type Column } from '@/Components/Ui/DataTable.vue'
import Pagination, { type PaginationLink, type PaginationMeta } from '@/Components/Ui/Pagination.vue'

type CustomerRow = {
  id: number
  full_name: string
  email: string
  phone: string | null
  email_verified: boolean
  anonymised: boolean
  created_at: string | null
}

const props = defineProps<{
  customers: { data: CustomerRow[]; links: PaginationLink[]; meta?: PaginationMeta }
}>()

const columns: Column[] = [
  { key: 'name', label: 'Zákazník' },
  { key: 'email', label: 'E-mail' },
  { key: 'phone', label: 'Telefon' },
  { key: 'status', label: 'Stav' },
]

/**
 * Filtering elsewhere in the admin rewrites the table without moving focus,
 * so this echoes that pattern even though this listing has no filters yet —
 * a screen reader user still gets a count on first load.
 */
const resultMessage = computed(() => {
  const count = props.customers.data.length

  if (count === 0) return 'Žádný zákazník.'
  if (count === 1) return 'Nalezen 1 zákazník.'
  if (count < 5) return `Nalezeni ${count} zákazníci.`

  return `Nalezeno ${count} zákazníků.`
})
</script>

<template>
  <AdminLayout title="Zákazníci">
    <template #header>
      <h1 class="text-xl font-semibold text-gray-900">Zákazníci</h1>
    </template>

    <p role="status" aria-live="polite" aria-atomic="true" class="sr-only">
      {{ resultMessage }}
    </p>

    <DataTable :columns="columns" :rows="customers.data" caption="Seznam zákazníků e-shopu">
      <template #empty>Zatím tu není žádný zákazník.</template>

      <template #cell-name="{ row }">
        <Link
          :href="route('admin.customers.show', (row as CustomerRow).id)"
          class="font-medium text-gray-900 underline hover:no-underline focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
        >
          {{ (row as CustomerRow).full_name || '(bez jména)' }}
        </Link>
      </template>

      <template #cell-email="{ row }">{{ (row as CustomerRow).email }}</template>

      <template #cell-phone="{ row }">
        <span v-if="(row as CustomerRow).phone">{{ (row as CustomerRow).phone }}</span>
        <span v-else class="text-gray-700">—</span>
      </template>

      <template #cell-status="{ row }">
        <span v-if="(row as CustomerRow).anonymised" class="text-gray-700">Anonymizován</span>
        <span v-else-if="(row as CustomerRow).email_verified">Ověřený e-mail</span>
        <span v-else class="text-gray-700">Neověřený e-mail</span>
      </template>
    </DataTable>

    <Pagination :links="customers.links" :meta="customers.meta" />
  </AdminLayout>
</template>
