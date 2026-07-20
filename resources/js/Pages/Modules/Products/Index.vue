<script setup lang="ts">
import { ref, watch } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import DataTable, { type Column } from '@/Components/Ui/DataTable.vue'
import Pagination, { type PaginationLink, type PaginationMeta } from '@/Components/Ui/Pagination.vue'

type ProductRow = {
  id: number
  slug: string
  name: string
  sku: string | null
  price: number
  status: string
  stock_tracked: boolean
  stock_qty: number
  image: string | null
  categories: string[]
}

const props = defineProps<{
  products: { data: ProductRow[]; links: PaginationLink[]; meta?: PaginationMeta }
  filters: { search?: string; status?: string; category?: number }
  categories: { id: number; name: string; depth: number }[]
}>()

const search = ref(props.filters.search ?? '')
const status = ref(props.filters.status ?? '')

const STATUS_LABELS: Record<string, string> = {
  draft: 'Koncept',
  active: 'Aktivní',
  hidden: 'Skrytý',
}

const columns: Column[] = [
  { key: 'name', label: 'Produkt' },
  { key: 'sku', label: 'Kód' },
  { key: 'price', label: 'Cena s DPH', align: 'right' },
  { key: 'stock', label: 'Sklad', align: 'right' },
  { key: 'status', label: 'Stav' },
]

const price = (haler: number) =>
  new Intl.NumberFormat('cs-CZ', { style: 'currency', currency: 'CZK' }).format(haler / 100)

let timer: ReturnType<typeof setTimeout> | undefined

const applyFilters = () =>
  router.get(
    route('admin.products.index'),
    { search: search.value || undefined, status: status.value || undefined },
    { preserveState: true, replace: true },
  )

// Debounced so typing does not fire a request per keystroke.
watch(search, () => {
  clearTimeout(timer)
  timer = setTimeout(applyFilters, 300)
})

watch(status, applyFilters)

/**
 * Filtering rewrites the table without moving focus, so a screen reader user
 * would otherwise get no signal that anything happened.
 */
const resultMessage = computed(() => {
  const count = props.products.data.length

  if (count === 0) return 'Žádný produkt neodpovídá filtru.'
  if (count === 1) return 'Nalezen 1 produkt.'
  if (count < 5) return `Nalezeny ${count} produkty.`

  return `Nalezeno ${count} produktů.`
})

const createForm = useForm({
  name: '',
  price: 0,
  tax_rate_id: null as number | null,
  status: 'draft',
  stock_policy: 'show_sold_out',
  weight_g: 0,
})
</script>

<template>
  <AdminLayout title="Produkty">
    <template #header>
      <h1 class="text-xl font-semibold text-gray-900">Produkty</h1>
    </template>

    <form
      class="mb-6 flex flex-wrap items-end gap-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm"
      role="search"
      @submit.prevent="applyFilters"
    >
      <div>
        <label for="product-search" class="block text-sm font-medium text-gray-700">
          Hledat podle názvu nebo kódu
        </label>
        <input
          id="product-search"
          v-model="search"
          type="search"
          class="mt-1 rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
        />
      </div>

      <div>
        <label for="product-status" class="block text-sm font-medium text-gray-700">Stav</label>
        <select
          id="product-status"
          v-model="status"
          class="mt-1 rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
        >
          <option value="">Všechny</option>
          <option value="draft">Koncept</option>
          <option value="active">Aktivní</option>
          <option value="hidden">Skrytý</option>
        </select>
      </div>
    </form>

    <details class="mb-6 rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
      <summary class="cursor-pointer text-sm font-semibold text-gray-900">Nový produkt</summary>

      <form
        class="mt-4 grid gap-3 sm:grid-cols-2"
        @submit.prevent="createForm.post(route('admin.products.store'))"
      >
        <div>
          <label for="new-product-name" class="block text-sm font-medium text-gray-700">Název</label>
          <input
            id="new-product-name"
            v-model="createForm.name"
            type="text"
            required
            class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
            :aria-invalid="createForm.errors.name ? 'true' : undefined"
            :aria-describedby="createForm.errors.name ? 'new-product-name-error' : undefined"
          />
          <p v-if="createForm.errors.name" id="new-product-name-error" class="mt-1 text-sm text-red-700">
            {{ createForm.errors.name }}
          </p>
        </div>

        <div class="sm:col-span-2">
          <button
            type="submit"
            :disabled="createForm.processing"
            class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:bg-gray-400 disabled:text-gray-700"
          >
            Vytvořit a otevřít
          </button>
          <p class="mt-1 text-sm text-gray-600">
            Cenu, sklad a další údaje doplníte na kartě produktu.
          </p>
        </div>
      </form>
    </details>

    <p role="status" aria-live="polite" aria-atomic="true" class="sr-only">
      {{ resultMessage }}
    </p>

    <DataTable :columns="columns" :rows="products.data" caption="Seznam produktů e-shopu">
      <template #empty>Zatím tu není žádný produkt.</template>

      <template #cell-name="{ row }">
        <Link
          :href="route('admin.products.show', (row as ProductRow).slug)"
          class="font-medium text-gray-900 underline hover:no-underline focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
        >
          {{ (row as ProductRow).name }}
        </Link>
        <span v-if="(row as ProductRow).categories.length" class="block text-xs text-gray-700">
          {{ (row as ProductRow).categories.join(', ') }}
        </span>
      </template>

      <template #cell-price="{ row }">{{ price((row as ProductRow).price) }}</template>

      <template #cell-stock="{ row }">
        <span v-if="(row as ProductRow).stock_tracked">{{ (row as ProductRow).stock_qty }}</span>
        <span v-else class="text-gray-700">nesleduje se</span>
      </template>

      <template #cell-status="{ row }">{{ STATUS_LABELS[(row as ProductRow).status] }}</template>
    </DataTable>

    <Pagination :links="products.links" :meta="products.meta" />
  </AdminLayout>
</template>
