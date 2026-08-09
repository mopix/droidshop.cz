<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import DataTable, { type Column } from '@/Components/Ui/DataTable.vue'
import ConfirmDialog from '@/Components/Ui/ConfirmDialog.vue'
import { Pencil, Trash2 } from 'lucide-vue-next'
import Pagination, { type PaginationLink, type PaginationMeta } from '@/Components/Ui/Pagination.vue'

type ProductRow = {
  id: number
  slug: string
  name: string
  sku: string | null
  ean: string | null
  price: number
  purchase_price: number | null
  purchase_net_price: number | null
  sale_price: number | null
  net_price: number | null
  tax_rate: number | null
  status: string
  stock_tracked: boolean
  stock_qty: number
  image: string | null
  short_description: string | null
  categories: { id: number; name: string }[]
}

const props = defineProps<{
  products: { data: ProductRow[]; links: PaginationLink[]; meta?: PaginationMeta }
  filters: { search?: string; status?: string; category?: number }
  categories: { id: number; name: string; depth: number }[]
  canSeeCosts: boolean
  vatApplies: boolean
}>()

const search = ref(props.filters.search ?? '')
const status = ref(props.filters.status ?? '')

const STATUS_LABELS: Record<string, string> = {
  draft: 'Koncept',
  active: 'Aktivní',
  hidden: 'Skrytý',
}

/**
 * The price columns, in the order the figures are read (wave 3.10).
 *
 * The purchase price is a permission, not a preference: the server does not
 * send it to somebody without `products.costs`, and the column is dropped so
 * the listing does not become a back door to the margin.
 *
 * The VAT columns follow the same rule as everywhere else since wave 3.7 — a
 * shop that is not registered is shown a single final price and nothing about
 * tax.
 */
const columns = computed<Column[]>(() => [
  { key: 'image', label: '' },
  { key: 'name', label: 'Produkt' },
  { key: 'sku', label: 'Kód' },
  { key: 'ean', label: 'EAN' },
  ...(props.canSeeCosts
    ? [{
        key: 'purchase_price',
        label: props.vatApplies ? 'Nákupní cena (bez/s DPH)' : 'Nákupní cena',
        align: 'right',
      } as Column]
    : []),
  { key: 'sale_price', label: 'Akční cena', align: 'right' },
  {
    key: 'price',
    label: props.vatApplies ? 'Koncová cena (bez/s DPH)' : 'Koncová cena',
    align: 'right',
  },
  { key: 'stock', label: 'Sklad', align: 'right' },
  { key: 'product_categories', label: 'Kategorie' },
  { key: 'status', label: 'Stav' },
  { key: 'actions', label: 'Akce', align: 'right' },
])

/**
 * A tint per status, subtle enough to scan by and never the only sign of it —
 * the Stav column says the same thing in words, which is what a tint on its
 * own cannot do (WCAG 1.4.1).
 */
const ROW_TINTS: Record<string, string> = {
  draft: 'bg-blue-50/60',
  active: 'bg-green-50/60',
  hidden: 'bg-red-50/60',
}

const rowClass = (row: Record<string, any>) => ROW_TINTS[row.status as string] ?? ''

/**
 * Saving the status straight from the listing.
 *
 * `preserveScroll`, because the listing is long and a merchant changing five
 * products in a row should not be thrown back to the top each time.
 */
const setStatus = (row: ProductRow, status: string) =>
  router.patch(
    route('admin.products.status.update', row.slug),
    { status },
    { preserveScroll: true, preserveState: true },
  )

const deleting = ref<ProductRow | null>(null)

const confirmDelete = () => {
  const row = deleting.value
  deleting.value = null

  if (row) {
    router.delete(route('admin.products.destroy', row.slug), { preserveScroll: true })
  }
}

const price = (haler: number) =>
  new Intl.NumberFormat('cs-CZ', { style: 'currency', currency: 'CZK' }).format(haler / 100)

/** An amount that was never filled in reads as a dash, not as 0 Kč. */
const optionalPrice = (haler: number | null): string => (haler === null ? '—' : price(haler))

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

    <DataTable
      :columns="columns"
      :rows="products.data"
      :row-class="rowClass"
      caption="Seznam produktů e-shopu"
    >
      <template #empty>Zatím tu není žádný produkt.</template>

      <template #cell-name="{ row }">
        <Link
          :href="route('admin.products.show', (row as ProductRow).slug)"
          class="font-medium text-gray-900 underline hover:no-underline focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
        >
          {{ (row as ProductRow).name }}
        </Link>
        <!-- The short description, not the categories: those have a column of
             their own now, and this is the line that tells two similar
             products apart. -->
        <span v-if="(row as ProductRow).short_description" class="mt-0.5 block text-xs text-gray-700">
          {{ (row as ProductRow).short_description }}
        </span>
      </template>

      <!--
        Decorative: the product's name sits in the very next cell, so a screen
        reader announcing the photo as well would read every row twice.
        A product without one gets an empty box rather than a broken image.

        The preview floats over the table instead of growing the cell: a
        thumbnail that grows in flow makes the row taller and shoves every row
        below it down, which is worse than not seeing the picture.

        `fixed`, not `absolute`, and that is the whole trick — the listing sits
        in an `overflow-x-auto` wrapper, and a scroll container clips in both
        axes, so an absolutely positioned preview would be cut off at the edge
        of the table. A fixed element with no offsets stays exactly where it
        would have been laid out and is not clipped by any of that.

        Hidden by default and shown on hover, so it never occupies space and
        the row height does not move. It follows keyboard focus too, which is
        why the thumbnail is a link: hovering is not something a keyboard does
        (WCAG 2.1.1).
      -->
      <template #cell-image="{ row }">
        <Link
          v-if="(row as ProductRow).image"
          :href="route('admin.products.show', (row as ProductRow).slug)"
          class="group relative inline-block rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
        >
          <img
            :src="(row as ProductRow).image!"
            alt=""
            class="h-10 w-10 rounded border border-gray-200 bg-white object-cover"
          />

          <img
            :src="(row as ProductRow).image!"
            alt=""
            class="pointer-events-none fixed z-50 hidden h-56 w-56 -translate-y-1/2 translate-x-3 rounded-lg border border-gray-200 bg-white object-contain p-1 shadow-xl group-hover:block group-focus-visible:block"
          />

          <!-- A link with no text has no name at all; the image is
               deliberately decorative, so the name goes here. It repeats the
               next cell's link, which is what an image-plus-title pair does
               everywhere. -->
          <span class="sr-only">{{ (row as ProductRow).name }}</span>
        </Link>
        <span v-else class="block h-10 w-10 rounded border border-dashed border-gray-200" aria-hidden="true" />
      </template>

      <template #cell-ean="{ row }">{{ (row as ProductRow).ean ?? '—' }}</template>

      <!--
        One column per price, both figures inside it: net above in grey,
        gross below in the weight the listing already used. Two columns of
        their own made the table wide enough to scroll for something a
        merchant reads as a single fact.
      -->
      <template #cell-purchase_price="{ row }">
        <span v-if="vatApplies && (row as ProductRow).purchase_net_price !== null" class="block text-xs text-gray-500">
          {{ optionalPrice((row as ProductRow).purchase_net_price) }}
        </span>
        <span class="block">{{ optionalPrice((row as ProductRow).purchase_price) }}</span>
      </template>

      <template #cell-sale_price="{ row }">
        {{ optionalPrice((row as ProductRow).sale_price) }}
      </template>

      <template #cell-price="{ row }">
        <span v-if="vatApplies" class="block text-xs text-gray-500">
          {{ optionalPrice((row as ProductRow).net_price) }}
        </span>
        <span class="block">{{ price((row as ProductRow).price) }}</span>
      </template>

      <template #cell-stock="{ row }">
        <span v-if="(row as ProductRow).stock_tracked">{{ (row as ProductRow).stock_qty }}</span>
        <span v-else class="text-gray-700">nesleduje se</span>
      </template>

      <template #cell-product_categories="{ row }">
        <span v-if="!(row as ProductRow).categories.length" class="text-gray-700">—</span>
        <span
          v-for="category in (row as ProductRow).categories"
          :key="category.id"
          class="mb-1 mr-1 inline-block rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-800"
        >
          {{ category.name }}
        </span>
      </template>

      <!-- Changed here and saved at once: a status is the one field a merchant
           flips while scanning the list, and making them open the product for
           it is three clicks for a one-word change. -->
      <template #cell-status="{ row }">
        <label :for="`status-${(row as ProductRow).id}`" class="sr-only">
          Stav produktu {{ (row as ProductRow).name }}
        </label>
        <select
          :id="`status-${(row as ProductRow).id}`"
          :value="(row as ProductRow).status"
          class="rounded-md border-gray-300 py-1 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
          @change="setStatus(row as ProductRow, ($event.target as HTMLSelectElement).value)"
        >
          <option v-for="(label, value) in STATUS_LABELS" :key="value" :value="value">
            {{ label }}
          </option>
        </select>
      </template>

      <template #cell-actions="{ row }">
        <Link
          :href="route('admin.products.show', (row as ProductRow).slug)"
          class="inline-flex rounded-md p-1.5 text-gray-700 hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
        >
          <Pencil class="h-4 w-4" aria-hidden="true" />
          <span class="sr-only">Upravit {{ (row as ProductRow).name }}</span>
        </Link>

        <button
          type="button"
          class="ml-1 inline-flex rounded-md p-1.5 text-red-700 hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-700"
          @click="deleting = row as ProductRow"
        >
          <Trash2 class="h-4 w-4" aria-hidden="true" />
          <span class="sr-only">Smazat {{ (row as ProductRow).name }}</span>
        </button>
      </template>
    </DataTable>

    <Pagination :links="products.links" :meta="products.meta" />

    <ConfirmDialog
      :show="deleting !== null"
      title="Smazat produkt"
      :message="`Produkt ${deleting?.name ?? ''} se přesune do koše. Objednávky, které ho obsahují, zůstanou beze změny.`"
      confirm-label="Smazat"
      danger
      @cancel="deleting = null"
      @confirm="confirmDelete"
    />
  </AdminLayout>
</template>
