<script setup lang="ts">
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import DataTable, { type Column } from '@/Components/Ui/DataTable.vue'
import ConfirmDialog from '@/Components/Ui/ConfirmDialog.vue'

type DiscountRow = {
  id: number
  name: string
  code: string | null
  active: boolean
  type: 'percent' | 'fixed' | 'free_shipping'
  value: number
  scope: 'cart' | 'categories' | 'products'
  starts_at: string | null
  ends_at: string | null
  usage_limit: number | null
  used_count: number
}

defineProps<{
  discounts: DiscountRow[]
}>()

const TYPE_LABELS: Record<string, string> = {
  percent: 'Procento',
  fixed: 'Pevná částka',
  free_shipping: 'Doprava zdarma',
}

const SCOPE_LABELS: Record<string, string> = {
  cart: 'Celý košík',
  categories: 'Kategorie',
  products: 'Produkty',
}

const money = (haler: number) =>
  new Intl.NumberFormat('cs-CZ', { style: 'currency', currency: 'CZK' }).format(haler / 100)

const valueLabel = (row: DiscountRow) => {
  if (row.type === 'percent') return `${(row.value / 10).toLocaleString('cs-CZ')} %`
  if (row.type === 'fixed') return money(row.value)

  return '—'
}

const dateLabel = (value: string | null) =>
  value ? new Intl.DateTimeFormat('cs-CZ', { dateStyle: 'medium' }).format(new Date(value)) : null

const validityLabel = (row: DiscountRow) => {
  const from = dateLabel(row.starts_at)
  const to = dateLabel(row.ends_at)

  if (!from && !to) return 'Bez omezení'
  if (from && to) return `${from} – ${to}`
  if (from) return `od ${from}`

  return `do ${to}`
}

const usageLabel = (row: DiscountRow) => `${row.used_count} / ${row.usage_limit ?? '∞'}`

const columns: Column[] = [
  { key: 'name', label: 'Název' },
  { key: 'type', label: 'Typ' },
  { key: 'scope', label: 'Rozsah' },
  { key: 'value', label: 'Hodnota', align: 'right' },
  { key: 'validity', label: 'Platnost' },
  { key: 'usage', label: 'Čerpání', align: 'right' },
  { key: 'status', label: 'Stav' },
  { key: 'actions', label: 'Akce', align: 'right' },
]

const deleting = ref<DiscountRow | null>(null)

const confirmDelete = () => {
  const discount = deleting.value

  if (!discount) return

  router.delete(route('admin.discounts.destroy', discount.id), {
    preserveScroll: true,
    onFinish: () => (deleting.value = null),
  })
}
</script>

<template>
  <AdminLayout title="Slevy">
    <template #header>
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 class="text-xl font-semibold text-gray-900">Slevy</h1>
          <p class="mt-1 text-sm text-gray-600">Slevové kupóny a automatická pravidla vašeho e-shopu.</p>
        </div>
        <Link
          :href="route('admin.discounts.create')"
          class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
        >
          Přidat slevu
        </Link>
      </div>
    </template>

    <DataTable :columns="columns" :rows="discounts" caption="Seznam slev e-shopu">
      <template #empty>Zatím tu není žádná sleva.</template>

      <template #cell-name="{ row }">
        <Link
          :href="route('admin.discounts.edit', (row as DiscountRow).id)"
          class="font-medium text-gray-900 underline hover:no-underline focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
        >
          {{ (row as DiscountRow).name }}
        </Link>
        <span v-if="(row as DiscountRow).code" class="block text-xs text-gray-700">
          Kód: {{ (row as DiscountRow).code }}
        </span>
        <span v-else class="block text-xs text-gray-700">Automatické pravidlo</span>
      </template>

      <template #cell-type="{ row }">
        {{ TYPE_LABELS[(row as DiscountRow).type] ?? (row as DiscountRow).type }}
      </template>

      <template #cell-scope="{ row }">
        {{ SCOPE_LABELS[(row as DiscountRow).scope] ?? (row as DiscountRow).scope }}
      </template>

      <template #cell-value="{ row }">{{ valueLabel(row as DiscountRow) }}</template>

      <template #cell-validity="{ row }">{{ validityLabel(row as DiscountRow) }}</template>

      <template #cell-usage="{ row }">{{ usageLabel(row as DiscountRow) }}</template>

      <template #cell-status="{ row }">
        <span
          class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset"
          :class="
            (row as DiscountRow).active
              ? 'bg-emerald-50 text-emerald-900 ring-emerald-700/40'
              : 'bg-gray-100 text-gray-800 ring-gray-500/40'
          "
        >
          {{ (row as DiscountRow).active ? 'Aktivní' : 'Neaktivní' }}
        </span>
      </template>

      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <Link
            :href="route('admin.discounts.edit', (row as DiscountRow).id)"
            class="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-800 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
          >
            Upravit
          </Link>
          <button
            type="button"
            class="rounded-md px-3 py-1.5 text-sm font-medium text-red-800 hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-800"
            @click="deleting = row as DiscountRow"
          >
            Smazat
          </button>
        </div>
      </template>
    </DataTable>

    <ConfirmDialog
      :show="deleting !== null"
      title="Smazat slevu"
      :message="`Opravdu smazat slevu ${deleting?.name ?? ''}? Akci nelze vzít zpět.`"
      confirm-label="Smazat"
      danger
      @cancel="deleting = null"
      @confirm="confirmDelete"
    />
  </AdminLayout>
</template>
