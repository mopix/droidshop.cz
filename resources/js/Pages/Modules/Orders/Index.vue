<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import DataTable, { type Column } from '@/Components/Ui/DataTable.vue'
import Pagination, { type PaginationLink, type PaginationMeta } from '@/Components/Ui/Pagination.vue'

type OrderRow = {
  uuid: string
  number: string
  email: string
  phone: string | null
  customer_id: number | null
  fulfillment_status: string
  payment_status: string
  items_total: number
  shipping_total: number
  total: number
  currency: string
  placed_at: string | null
}

const props = defineProps<{
  orders: { data: OrderRow[]; links: PaginationLink[]; meta?: PaginationMeta }
  filters: { fulfillment_status?: string; payment_status?: string; q?: string }
}>()

const FULFILLMENT_LABELS: Record<string, string> = {
  new: 'Nová',
  accepted: 'Přijatá',
  processing: 'Zpracovává se',
  shipped: 'Odeslaná',
  delivered: 'Doručená',
  cancelled: 'Zrušená',
}

const PAYMENT_LABELS: Record<string, string> = {
  unpaid: 'Nezaplaceno',
  paid: 'Zaplaceno',
  failed: 'Platba selhala',
  refunded: 'Vráceno',
}

// Only the target states legal from a given current state (mirrors
// Modules\Orders\Services\OrderWorkflow) — a convenience for the UI so a
// click cannot even offer an illegal move. The server enforces the same
// graph again regardless (never trust the client alone).
//
// "cancelled" is deliberately absent: this quick-select no longer offers
// cancellation at all (server-side, ChangeStateRequest no longer accepts it
// either) — storno has its own permission (orders.cancel) and confirm
// dialog on the order detail page.
const FULFILLMENT_NEXT: Record<string, string[]> = {
  new: ['accepted'],
  accepted: ['processing'],
  processing: ['shipped'],
  shipped: ['delivered'],
  delivered: [],
  cancelled: [],
}

const badgeClass: Record<string, string> = {
  new: 'bg-sky-50 text-sky-900 ring-sky-600/40',
  accepted: 'bg-sky-50 text-sky-900 ring-sky-600/40',
  processing: 'bg-amber-50 text-amber-900 ring-amber-700/40',
  shipped: 'bg-amber-50 text-amber-900 ring-amber-700/40',
  delivered: 'bg-emerald-50 text-emerald-900 ring-emerald-700/40',
  cancelled: 'bg-gray-100 text-gray-800 ring-gray-500/40',
  unpaid: 'bg-amber-50 text-amber-900 ring-amber-700/40',
  paid: 'bg-emerald-50 text-emerald-900 ring-emerald-700/40',
  failed: 'bg-red-50 text-red-900 ring-red-700/40',
  refunded: 'bg-gray-100 text-gray-800 ring-gray-500/40',
}

const q = ref(props.filters.q ?? '')
const fulfillmentStatus = ref(props.filters.fulfillment_status ?? '')
const paymentStatus = ref(props.filters.payment_status ?? '')

const columns: Column[] = [
  { key: 'number', label: 'Objednávka' },
  { key: 'fulfillment', label: 'Vyřízení' },
  { key: 'payment', label: 'Platba' },
  { key: 'total', label: 'Celkem', align: 'right' },
  { key: 'placed_at', label: 'Přijata' },
]

const money = (haler: number, currency: string) =>
  new Intl.NumberFormat('cs-CZ', { style: 'currency', currency }).format(haler / 100)

let timer: ReturnType<typeof setTimeout> | undefined

const applyFilters = () =>
  router.get(
    route('admin.orders.index'),
    {
      q: q.value || undefined,
      fulfillment_status: fulfillmentStatus.value || undefined,
      payment_status: paymentStatus.value || undefined,
    },
    { preserveState: true, replace: true },
  )

// Debounced so typing does not fire a request per keystroke.
watch(q, () => {
  clearTimeout(timer)
  timer = setTimeout(applyFilters, 300)
})

watch([fulfillmentStatus, paymentStatus], applyFilters)

/**
 * Filtering rewrites the table without moving focus, so a screen reader user
 * would otherwise get no signal that anything happened.
 */
const resultMessage = computed(() => {
  const count = props.orders.data.length

  if (count === 0) return 'Žádná objednávka neodpovídá filtru.'
  if (count === 1) return 'Nalezena 1 objednávka.'
  if (count < 5) return `Nalezeny ${count} objednávky.`

  return `Nalezeno ${count} objednávek.`
})

const quickChanging = ref<string | null>(null)

const quickChangeFulfillment = (row: OrderRow, event: Event) => {
  const to = (event.target as HTMLSelectElement).value

  if (!to) return

  quickChanging.value = row.uuid

  router.patch(
    route('admin.orders.state.update', row.uuid),
    { machine: 'fulfillment', to },
    {
      preserveScroll: true,
      onFinish: () => {
        quickChanging.value = null
        ;(event.target as HTMLSelectElement).value = ''
      },
    },
  )
}
</script>

<template>
  <AdminLayout title="Objednávky">
    <template #header>
      <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-semibold text-gray-900">Objednávky</h1>
        <Link
          :href="route('admin.orders.create')"
          class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
        >
          Nová objednávka
        </Link>
      </div>
    </template>

    <form
      class="mb-6 flex flex-wrap items-end gap-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm"
      role="search"
      @submit.prevent="applyFilters"
    >
      <div>
        <label for="order-search" class="block text-sm font-medium text-gray-700">
          Hledat podle čísla nebo e-mailu
        </label>
        <input
          id="order-search"
          v-model="q"
          type="search"
          class="mt-1 rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
        />
      </div>

      <div>
        <label for="order-fulfillment" class="block text-sm font-medium text-gray-700">Vyřízení</label>
        <select
          id="order-fulfillment"
          v-model="fulfillmentStatus"
          class="mt-1 rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
        >
          <option value="">Všechny</option>
          <option v-for="(label, value) in FULFILLMENT_LABELS" :key="value" :value="value">{{ label }}</option>
        </select>
      </div>

      <div>
        <label for="order-payment" class="block text-sm font-medium text-gray-700">Platba</label>
        <select
          id="order-payment"
          v-model="paymentStatus"
          class="mt-1 rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
        >
          <option value="">Všechny</option>
          <option v-for="(label, value) in PAYMENT_LABELS" :key="value" :value="value">{{ label }}</option>
        </select>
      </div>
    </form>

    <p role="status" aria-live="polite" aria-atomic="true" class="sr-only">
      {{ resultMessage }}
    </p>

    <DataTable :columns="columns" :rows="orders.data" row-key="uuid" caption="Seznam objednávek e-shopu">
      <template #empty>Zatím tu není žádná objednávka.</template>

      <template #cell-number="{ row }">
        <Link
          :href="route('admin.orders.show', (row as OrderRow).uuid)"
          class="font-medium text-gray-900 underline hover:no-underline focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
        >
          {{ (row as OrderRow).number }}
        </Link>
        <span class="block text-xs text-gray-700">{{ (row as OrderRow).email }}</span>
      </template>

      <template #cell-fulfillment="{ row }">
        <div class="flex flex-col items-start gap-1">
          <span
            class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset"
            :class="badgeClass[(row as OrderRow).fulfillment_status]"
          >
            {{ FULFILLMENT_LABELS[(row as OrderRow).fulfillment_status] ?? (row as OrderRow).fulfillment_status }}
          </span>

          <label class="sr-only" :for="`quick-fulfillment-${(row as OrderRow).uuid}`">
            Rychlá změna stavu vyřízení objednávky {{ (row as OrderRow).number }}
          </label>
          <select
            v-if="FULFILLMENT_NEXT[(row as OrderRow).fulfillment_status]?.length"
            :id="`quick-fulfillment-${(row as OrderRow).uuid}`"
            :disabled="quickChanging === (row as OrderRow).uuid"
            class="rounded-md border-gray-300 text-xs shadow-sm focus:border-gray-900 focus:ring-gray-900"
            @change="quickChangeFulfillment(row as OrderRow, $event)"
          >
            <option value="">Změnit stav…</option>
            <option v-for="target in FULFILLMENT_NEXT[(row as OrderRow).fulfillment_status]" :key="target" :value="target">
              {{ FULFILLMENT_LABELS[target] ?? target }}
            </option>
          </select>
        </div>
      </template>

      <template #cell-payment="{ row }">
        <span
          class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset"
          :class="badgeClass[(row as OrderRow).payment_status]"
        >
          {{ PAYMENT_LABELS[(row as OrderRow).payment_status] ?? (row as OrderRow).payment_status }}
        </span>
      </template>

      <template #cell-total="{ row }">{{ money((row as OrderRow).total, (row as OrderRow).currency) }}</template>

      <template #cell-placed_at="{ row }">
        <span v-if="(row as OrderRow).placed_at">{{ (row as OrderRow).placed_at }}</span>
        <span v-else class="text-gray-700">—</span>
      </template>
    </DataTable>

    <Pagination :links="orders.links" :meta="orders.meta" />
  </AdminLayout>
</template>
