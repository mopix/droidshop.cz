<script setup lang="ts">
import { computed, ref } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import DataTable, { type Column } from '@/Components/Ui/DataTable.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

type QueueRow = {
  uuid: string
  number: string
  email: string
  total: number
  currency: string
  placed_at: string | null
  pickup_point_name: string | null
  shipment_id: number | null
  shipment_status: string | null
}

const props = defineProps<{
  orders: QueueRow[]
}>()

const page = usePage()

const flashStatus = computed(() => (page.props.flash as { status?: string } | undefined)?.status ?? null)
const carrierError = computed(() => (page.props.errors as { carrier?: string } | undefined)?.carrier ?? null)

const money = (haler: number, currency: string) =>
  new Intl.NumberFormat('cs-CZ', { style: 'currency', currency }).format(haler / 100)

const STATUS_LABELS: Record<string, string> = {
  failed: 'Podání selhalo',
  pending: 'Čeká na podání',
}

const statusLabel = (row: QueueRow) => STATUS_LABELS[row.shipment_status ?? ''] ?? 'Nikdy nepodáno'

const badgeClass = (row: QueueRow) =>
  row.shipment_status === 'failed'
    ? 'bg-red-50 text-red-900 ring-red-700/40'
    : 'bg-amber-50 text-amber-900 ring-amber-700/40'

const columns: Column[] = [
  { key: 'select', label: 'Výběr' },
  { key: 'number', label: 'Objednávka' },
  { key: 'pickup_point', label: 'Výdejní místo' },
  { key: 'status', label: 'Stav' },
  { key: 'total', label: 'Celkem', align: 'right' },
  { key: 'placed_at', label: 'Přijata' },
]

// --- selection -------------------------------------------------------------

const selected = ref<string[]>([])

const allUuids = computed(() => props.orders.map((row) => row.uuid))

const allSelected = computed(
  () => allUuids.value.length > 0 && selected.value.length === allUuids.value.length,
)

const isSelected = (uuid: string) => selected.value.includes(uuid)

const toggleRow = (uuid: string) => {
  selected.value = isSelected(uuid)
    ? selected.value.filter((value) => value !== uuid)
    : [...selected.value, uuid]
}

// A dedicated button rather than relying on a header checkbox alone: a
// screen reader or switch-access user must be able to reach "select all" as
// an ordinary, clearly labelled control (WCAG 2.2 AA, 2.1.1).
const toggleSelectAll = () => {
  selected.value = allSelected.value ? [] : [...allUuids.value]
}

// Announced through a live region below rather than only visually, so
// selecting rows is not a silent change for assistive technology.
const selectionMessage = computed(() => {
  const count = selected.value.length

  if (count === 0) return 'Nevybrána žádná objednávka.'
  if (count === 1) return 'Vybrána 1 objednávka.'
  if (count < 5) return `Vybrány ${count} objednávky.`

  return `Vybráno ${count} objednávek.`
})

// --- hand-over (submit) ------------------------------------------------------

const submitForm = useForm<{ order_uuids: string[] }>({ order_uuids: [] })

const submitSelected = () => {
  submitForm.order_uuids = [...selected.value]

  submitForm.post(route('admin.packeta.shipments.submit'), {
    preserveScroll: true,
    onSuccess: () => {
      selected.value = []
    },
  })
}

// --- labels --------------------------------------------------------------
//
// A plain (non-Inertia) form submission, not router.post(): labels() streams
// a PDF response body, which Inertia's XHR-based visit cannot render at all.
// Submitting natively with target="_blank" opens the PDF (or, on a rejected
// batch, the queue page again with the error flashed) in a new tab and
// leaves this page untouched either way.
const csrfToken = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? ''

const LABEL_FORMATS = ['A7 on A4', 'A6 on A4', 'A6', 'A7']

const labelFormat = ref(LABEL_FORMATS[0])

const rowsByUuid = computed(() => new Map(props.orders.map((row) => [row.uuid, row])))

// Only a row whose shipment already exists (an earlier attempt reached the
// carrier's createPacket call, even if it then failed) has a packet id to
// print — a never-attempted row has nothing yet.
const selectedShipmentIds = computed(() =>
  selected.value
    .map((uuid) => rowsByUuid.value.get(uuid)?.shipment_id ?? null)
    .filter((id): id is number => id !== null),
)

const canPrintLabels = computed(() => selectedShipmentIds.value.length > 0)
</script>

<template>
  <AdminLayout title="Expedice">
    <template #header>
      <h1 class="text-xl font-semibold text-gray-900">Expedice — Zásilkovna</h1>
    </template>

    <p v-if="flashStatus" role="status" class="mb-4 rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-900 ring-1 ring-inset ring-emerald-600/30">
      {{ flashStatus }}
    </p>

    <p v-if="carrierError" role="alert" class="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-900 ring-1 ring-inset ring-red-700/40">
      {{ carrierError }}
    </p>

    <p role="status" aria-live="polite" aria-atomic="true" class="sr-only">{{ selectionMessage }}</p>

    <div class="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
      <SecondaryButton type="button" @click="toggleSelectAll">
        {{ allSelected ? 'Zrušit výběr' : 'Vybrat vše' }}
      </SecondaryButton>

      <PrimaryButton
        type="button"
        :disabled="selected.length === 0 || submitForm.processing"
        @click="submitSelected"
      >
        Podat vybrané ({{ selected.length }})
      </PrimaryButton>

      <form
        method="POST"
        :action="route('admin.packeta.shipments.labels')"
        target="_blank"
        class="flex flex-wrap items-end gap-3"
      >
        <input type="hidden" name="_token" :value="csrfToken" />
        <input v-for="id in selectedShipmentIds" :key="id" type="hidden" name="shipment_ids[]" :value="id" />

        <div>
          <label for="label-format" class="block text-sm font-medium text-gray-700">Formát štítku</label>
          <select
            id="label-format"
            v-model="labelFormat"
            name="format"
            class="mt-1 rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
          >
            <option v-for="option in LABEL_FORMATS" :key="option" :value="option">{{ option }}</option>
          </select>
        </div>

        <SecondaryButton type="submit" :disabled="!canPrintLabels">
          Tisk štítků ({{ selectedShipmentIds.length }})
        </SecondaryButton>
      </form>
    </div>

    <p v-if="selected.length > 0 && !canPrintLabels" class="mb-4 text-sm text-gray-700">
      Štítek lze tisknout jen pro už jednou podanou (a znovu opakovanou) zásilku — mezi vybranými zatím žádná taková není.
    </p>

    <DataTable :columns="columns" :rows="orders" row-key="uuid" caption="Objednávky čekající na podání Zásilkovně">
      <template #empty>Fronta je prázdná — všechny objednávky se Zásilkovnou jsou už podané.</template>

      <template #head-select>
        <span class="sr-only">Výběr</span>
      </template>

      <template #cell-select="{ row }">
        <label class="sr-only" :for="`select-${(row as QueueRow).uuid}`">
          Vybrat objednávku {{ (row as QueueRow).number }}
        </label>
        <input
          :id="`select-${(row as QueueRow).uuid}`"
          type="checkbox"
          class="rounded border-gray-300 text-gray-900 focus:ring-gray-900"
          :checked="isSelected((row as QueueRow).uuid)"
          @change="toggleRow((row as QueueRow).uuid)"
        />
      </template>

      <template #cell-number="{ row }">
        <span class="font-medium text-gray-900">{{ (row as QueueRow).number }}</span>
        <span class="block text-xs text-gray-700">{{ (row as QueueRow).email }}</span>
      </template>

      <template #cell-pickup_point="{ row }">
        {{ (row as QueueRow).pickup_point_name ?? '—' }}
      </template>

      <template #cell-status="{ row }">
        <span
          class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset"
          :class="badgeClass(row as QueueRow)"
        >
          {{ statusLabel(row as QueueRow) }}
        </span>
      </template>

      <template #cell-total="{ row }">{{ money((row as QueueRow).total, (row as QueueRow).currency) }}</template>

      <template #cell-placed_at="{ row }">
        <span v-if="(row as QueueRow).placed_at">{{ (row as QueueRow).placed_at }}</span>
        <span v-else class="text-gray-700">—</span>
      </template>
    </DataTable>
  </AdminLayout>
</template>
