<script setup lang="ts">
import { ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import DataTable, { type Column } from '@/Components/Ui/DataTable.vue'
import Pagination, { type PaginationLink, type PaginationMeta } from '@/Components/Ui/Pagination.vue'

type DocumentRow = {
  number: string
  type: string
  order_number: string | null
  order_uuid: string
  total: number
  currency: string
  issued_at: string | null
  sent_at: string | null
  downloadable: boolean
}

const props = defineProps<{
  documents: { data: DocumentRow[]; links: PaginationLink[]; meta?: PaginationMeta }
}>()

const TYPE_LABELS: Record<string, string> = {
  invoice: 'Faktura',
  proforma: 'Zálohová faktura',
  credit_note: 'Dobropis',
}

const columns: Column[] = [
  { key: 'number', label: 'Číslo dokladu' },
  { key: 'order', label: 'Objednávka' },
  { key: 'issued_at', label: 'Vystaveno' },
  { key: 'total', label: 'Celkem', align: 'right' },
  { key: 'sent', label: 'Odesláno' },
  { key: 'download', label: 'Stažení' },
]

const money = (haler: number, currency: string) =>
  new Intl.NumberFormat('cs-CZ', { style: 'currency', currency }).format(haler / 100)

// Defaults to the current month — the common "export this month's VAT" case.
const today = new Date()
const startOfMonth = new Date(today.getFullYear(), today.getMonth(), 1)
const toIsoDate = (date: Date) => date.toISOString().slice(0, 10)

const vatFrom = ref(toIsoDate(startOfMonth))
const vatTo = ref(toIsoDate(today))
</script>

<template>
  <AdminLayout title="Doklady">
    <template #header>
      <h1 class="text-xl font-semibold text-gray-900">Doklady</h1>
      <p class="mt-1 text-sm text-gray-600">Vystavené faktury k objednávkám. Nový doklad se vystavuje z detailu objednávky.</p>
    </template>

    <form
      :action="route('admin.docs.vat-export')"
      method="get"
      class="mb-6 flex flex-wrap items-end gap-3 rounded border border-gray-200 p-4"
    >
      <div>
        <label for="vat-export-from" class="block text-sm font-medium text-gray-700">Od (DUZP)</label>
        <input
          id="vat-export-from"
          v-model="vatFrom"
          type="date"
          name="from"
          required
          class="mt-1 rounded border-gray-300 text-sm focus:border-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
        />
      </div>
      <div>
        <label for="vat-export-to" class="block text-sm font-medium text-gray-700">Do (DUZP)</label>
        <input
          id="vat-export-to"
          v-model="vatTo"
          type="date"
          name="to"
          required
          class="mt-1 rounded border-gray-300 text-sm focus:border-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
        />
      </div>
      <button
        type="submit"
        class="rounded bg-gray-900 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
      >
        Exportovat DPH (CSV)
      </button>
    </form>

    <DataTable :columns="columns" :rows="props.documents.data" row-key="number" caption="Seznam vystavených dokladů">
      <template #empty>Zatím nebyl vystaven žádný doklad.</template>

      <template #cell-number="{ row }">
        <span class="font-medium text-gray-900">{{ (row as DocumentRow).number }}</span>
        <span class="block text-xs text-gray-700">{{ TYPE_LABELS[(row as DocumentRow).type] ?? (row as DocumentRow).type }}</span>
      </template>

      <template #cell-order="{ row }">
        <Link
          v-if="(row as DocumentRow).order_uuid"
          :href="route('admin.orders.show', (row as DocumentRow).order_uuid)"
          class="underline hover:no-underline focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
        >
          {{ (row as DocumentRow).order_number ?? (row as DocumentRow).order_uuid }}
        </Link>
        <span v-else>—</span>
      </template>

      <template #cell-issued_at="{ row }">
        <span v-if="(row as DocumentRow).issued_at">{{ (row as DocumentRow).issued_at }}</span>
        <span v-else class="text-gray-700">—</span>
      </template>

      <template #cell-total="{ row }">{{ money((row as DocumentRow).total, (row as DocumentRow).currency) }}</template>

      <template #cell-sent="{ row }">
        <span v-if="(row as DocumentRow).sent_at" class="text-emerald-800">Odesláno</span>
        <span v-else class="text-gray-700">Neodesláno</span>
      </template>

      <template #cell-download="{ row }">
        <a
          v-if="(row as DocumentRow).downloadable"
          :href="route('admin.docs.download', { number: (row as DocumentRow).number, type: (row as DocumentRow).type })"
          class="underline hover:no-underline focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
        >
          Stáhnout PDF
        </a>
        <span v-else class="text-gray-700">Připravuje se…</span>
      </template>
    </DataTable>

    <Pagination :links="props.documents.links" :meta="props.documents.meta" />
  </AdminLayout>
</template>
