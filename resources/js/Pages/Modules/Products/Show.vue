<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import ConfirmDialog from '@/Components/Ui/ConfirmDialog.vue'

type ProductImage = { id: number; url: string; alt: string | null; is_main: boolean }

type Product = {
  id: number
  slug: string
  name: string
  status: string
  short_description: string | null
  description: string | null
  price: string
  net_price: string | null
  sale_price: string | null
  sale_starts_at: string | null
  sale_ends_at: string | null
  purchase_price: string | null
  tax_rate_id: number
  sku: string | null
  ean: string | null
  manufacturer: string | null
  weight_g: number
  length_mm: number | null
  width_mm: number | null
  height_mm: number | null
  stock_tracked: boolean
  stock_qty: number
  stock_policy: string
  stock_alert_qty: number | null
  // null = inherit the shop-wide default; 'radio'/'select' override it for
  // this product only.
  variant_display: string | null
  seo_title: string | null
  seo_description: string | null
  url: string
  images: ProductImage[]
  category_ids: number[]
  primary_category_id: number | null
}

type ProductOptionValue = { id: number; value: string; position: number }
type ProductOption = { id: number; name: string; position: number; values: ProductOptionValue[] }

// Korunas-or-null as a string, same convention as the product's
// own 'price' prop, which the matrix's price column sits right next to.
type ProductVariant = {
  id: number
  label: string
  sku: string | null
  ean: string | null
  price: string | null
  sale_price: string | null
  stock_tracked: boolean
  stock_qty: number
  stock_policy: string
  active: boolean
}

const props = defineProps<{
  product: Product
  taxRates: { id: number; name: string; percent: number }[]
  vatApplies: boolean
  categories: { id: number; name: string; depth: number }[]
  options: ProductOption[]
  variants: ProductVariant[]
  can: { edit: boolean; costs: boolean }
}>()

/** Tabs whose fields belong to the main product form. */
const FORM_TABS = ['basic', 'prices', 'stock', 'seo']

const TABS = [
  { key: 'basic', label: 'Základní' },
  { key: 'prices', label: 'Ceny' },
  { key: 'images', label: 'Obrázky' },
  { key: 'stock', label: 'Sklad' },
  { key: 'variants', label: 'Varianty' },
  { key: 'seo', label: 'SEO' },
] as const

const tab = ref<(typeof TABS)[number]['key']>('basic')

const formTab = computed(() => FORM_TABS.includes(tab.value))

/**
 * Arrow keys move between tabs, Home/End jump to the ends (ARIA APG).
 *
 * Without this a tablist is reachable but not operable from the keyboard:
 * roving tabindex means Tab enters the strip and then leaves it, so the other
 * tabs have no way to be reached at all.
 */
const onTabKeydown = (event: KeyboardEvent, index: number) => {
  const moves: Record<string, number> = {
    ArrowRight: index + 1,
    ArrowDown: index + 1,
    ArrowLeft: index - 1,
    ArrowUp: index - 1,
    Home: 0,
    End: TABS.length - 1,
  }

  const target = moves[event.key]

  if (target === undefined) return

  event.preventDefault()

  const next = TABS[(target + TABS.length) % TABS.length]
  tab.value = next.key

  nextTick(() => document.getElementById(`tab-${next.key}`)?.focus())
}

const form = useForm({
  name: props.product.name,
  slug: props.product.slug,
  status: props.product.status,
  short_description: props.product.short_description ?? '',
  description: props.product.description ?? '',
  price: props.product.price,
  net_price: null as number | null,
  sale_price: props.product.sale_price,
  sale_starts_at: props.product.sale_starts_at,
  sale_ends_at: props.product.sale_ends_at,
  purchase_price: props.product.purchase_price,
  tax_rate_id: props.product.tax_rate_id,
  sku: props.product.sku ?? '',
  ean: props.product.ean ?? '',
  manufacturer: props.product.manufacturer ?? '',
  weight_g: props.product.weight_g,
  length_mm: props.product.length_mm,
  width_mm: props.product.width_mm,
  height_mm: props.product.height_mm,
  stock_tracked: props.product.stock_tracked,
  stock_qty: props.product.stock_qty,
  stock_policy: props.product.stock_policy,
  stock_alert_qty: props.product.stock_alert_qty,
  category_ids: [...props.product.category_ids],
  primary_category_id: props.product.primary_category_id,
  variant_display: props.product.variant_display,
  seo_title: props.product.seo_title ?? '',
  seo_description: props.product.seo_description ?? '',
})

const rate = computed(
  () => props.taxRates.find((r) => r.id === form.tax_rate_id) ?? props.taxRates[0],
)

/**
 * Gross is the field the shop edits; net is shown alongside it as a
 * convenience. The binding figure is always the server's — this is display
 * arithmetic, never the price anyone is charged.
 */
/**
 * Reads a price the way the merchant typed it, for preview arithmetic only.
 *
 * The server has its own parser (App\Core\Money\MoneyInput) and it is the
 * one that decides what gets stored — this is display, and a disagreement
 * between the two shows up as a preview that is a haléř out, never as a wrong
 * price.
 */
const korunas = (value: string | number | null): number => {
  if (value === null || value === '') return 0

  const parsed = Number(String(value).replace(/[\s\u00A0\u202F]/g, '').replace(',', '.'))

  return Number.isFinite(parsed) ? Math.round(parsed * 100) : 0
}

const netPreview = computed(() => {
  const percent = rate.value?.percent ?? 0

  return Math.round(korunas(form.price) / (1 + percent / 100))
})

/**
 * A field with both a permanent hint and an error has to point at both: naming
 * only the error drops the hint, naming only the hint hides the failure.
 */
const describedBy = (id: string, field: keyof typeof form.errors) =>
  [`${id}-hint`, form.errors[field] ? `${id}-error` : null].filter(Boolean).join(' ')

const money = (haler: number) =>
  new Intl.NumberFormat('cs-CZ', { style: 'currency', currency: 'CZK' }).format(haler / 100)

const save = () => form.patch(route('admin.products.update', props.product.slug))

const deleting = ref(false)

const confirmDelete = () =>
  router.delete(route('admin.products.destroy', props.product.slug), {
    onFinish: () => (deleting.value = false),
  })

const uploads = ref<File[] | null>(null)

/** Whether a file is currently being dragged over the drop area. */
const dragging = ref(false)

/**
 * Files dropped onto the panel (wave 3.8).
 *
 * A convenience beside the file input, never instead of it: dropping is not
 * something a keyboard can do (WCAG 2.1.1), which is the same reason image
 * and category order is moved with buttons rather than by dragging
 * (rozhodnutí 2026-07-20).
 */
const onDrop = (event: DragEvent) => {
  dragging.value = false

  const files = Array.from(event.dataTransfer?.files ?? []).filter((file) =>
    file.type.startsWith('image/'),
  )

  if (files.length) {
    uploads.value = files
    uploadImages()
  }
}

const uploadImages = () => {
  if (!uploads.value?.length) return

  router.post(
    route('admin.products.images.store', props.product.slug),
    { images: uploads.value },
    { forceFormData: true, preserveScroll: true, onSuccess: () => (uploads.value = null) },
  )
}

const setMain = (image: ProductImage) =>
  router.patch(
    route('admin.products.images.update', [props.product.slug, image.id]),
    { is_main: true, alt: image.alt },
    { preserveScroll: true },
  )

const removeImage = (image: ProductImage) =>
  router.delete(route('admin.products.images.destroy', [props.product.slug, image.id]), {
    preserveScroll: true,
  })

/**
 * Moves an image one place in the gallery.
 *
 * The endpoint has existed since wave 1.2 and nothing ever called it, so the
 * order was whatever the upload order happened to be.
 */
const moveImage = (index: number, direction: -1 | 1) => {
  const ids = props.product.images.map((image) => image.id)
  const target = index + direction

  if (target < 0 || target >= ids.length) return

  ;[ids[index], ids[target]] = [ids[target], ids[index]]

  router.post(
    route('admin.products.images.reorder', props.product.slug),
    { ids },
    { preserveScroll: true },
  )
}

// --- Variant matrix (options, values, variants) ---------------------------

const newOption = ref('')
const newValue = ref<Record<number, string>>({})

/**
 * A matrix row plus one bit of client-only state: whether the merchant has
 * changed one of its editable fields since it was last loaded from — or
 * saved to — the server. Not sent to the server (saveVariant only ever picks
 * the named fields off this).
 */
type MatrixRow = ProductVariant & {
  /** Typing helper only — never stored. See UpdateProductVariantRequest. */
  net_price: number | null
  dirty: boolean
}

/**
 * A local, decoupled copy of the matrix rows — the same reason every other
 * editable field on this page goes through `useForm()` instead of binding
 * `v-model` straight into `props.*`.
 *
 * Every other variant action (add/move/delete axis or value, generate) is a
 * full `router.post`/`delete` visit with no `only`/`preserveState`, so it
 * replaces `props.variants` wholesale. If the matrix's inputs bound directly
 * into that prop, typing into row B and then saving row A (or reordering an
 * axis) would silently discard row B's in-progress, unsaved edit the moment
 * the visit's response replaced the prop.
 *
 * The merge below is keyed on `dirty`, not on mere presence: an earlier
 * version of this kept every already-known row's local values forever,
 * which protected in-progress typing but also meant a row the merchant never
 * touched would never again pick up a server-side change — e.g. stock a
 * completed order decremented on a variant nobody was editing would be
 * invisible, and a later save of that untouched row would silently resend
 * (and thus revert) the stale local quantity. Only a row flagged dirty keeps
 * its local values; every other row re-seeds from the server on each visit.
 */
/**
 * The net figure to show as a placeholder, using the rate currently selected
 * on the product. A preview only: what gets stored is whatever the server
 * computes from the field the merchant actually typed in.
 */
const netOf = (gross: number): number => {
  const percent = rate.value?.percent ?? 0

  return Math.round(gross / (1 + percent / 100))
}

const rows = ref<MatrixRow[]>(
  props.variants.map((variant) => ({ ...variant, net_price: null, dirty: false })),
)

const markDirty = (row: MatrixRow) => {
  row.dirty = true
}

watch(
  () => props.variants,
  (next) => {
    const previous = new Map(rows.value.map((row) => [row.id, row]))

    rows.value = next.map((incoming) => {
      const existing = previous.get(incoming.id)

      // A variant this page has never held locally (just generated, or the
      // very first render) has no in-progress edit to protect.
      if (!existing) return { ...incoming, dirty: false }

      if (existing.dirty) {
        // Keep the local, still-unsaved (or server-rejected) edit — that is
        // the whole point of this mechanism. 'label' is server-computed and
        // never edited in this matrix, so it always tracks the server (it
        // can legitimately change, e.g. after reordering an axis swaps the
        // order the option values are joined in).
        return { ...existing, label: incoming.label }
      }

      // Untouched since load or since its last successful save: take the
      // server's row as-is, so a change from outside this tab (another
      // admin, a completed order decrementing stock) actually shows up.
      return { ...incoming, dirty: false }
    })
  },
)

const addOption = () => {
  router.post(
    route('admin.products.variants.options.store', props.product.slug),
    { name: newOption.value },
    { preserveScroll: true, onSuccess: () => (newOption.value = '') },
  )
}

const moveOption = (option: ProductOption, direction: 'up' | 'down') =>
  router.post(
    route('admin.products.variants.options.move', [props.product.slug, option.id]),
    { direction },
    { preserveScroll: true },
  )

const addValue = (option: ProductOption) => {
  router.post(
    route('admin.products.variants.values.store', [props.product.slug, option.id]),
    { value: newValue.value[option.id] ?? '' },
    { preserveScroll: true, onSuccess: () => (newValue.value[option.id] = '') },
  )
}

const moveValue = (option: ProductOption, value: ProductOptionValue, direction: 'up' | 'down') =>
  router.post(
    route('admin.products.variants.values.move', [props.product.slug, option.id, value.id]),
    { direction },
    { preserveScroll: true },
  )

const generate = () =>
  router.post(route('admin.products.variants.generate', props.product.slug), {}, { preserveScroll: true })

const saveVariant = (variant: MatrixRow) => {
  router.patch(
    route('admin.products.variants.update', [props.product.slug, variant.id]),
    {
      price: variant.price,
      // Only one of the two is ever set: typing in either column clears the
      // other, and the server converts (see UpdateProductVariantRequest).
      net_price: variant.net_price,
      sale_price: variant.sale_price,
      sku: variant.sku,
      // The matrix's own "Sleduje sklad" checkbox, not a forced value: a
      // variant can legitimately have stock_tracked = false (untracked = no
      // stock check at all, a fully supported state — see
      // ProductVariant::isAvailable()). Sending anything but the row's own
      // value here would silently flip it on every save of an unrelated
      // field.
      stock_tracked: variant.stock_tracked,
      // Unlike price (nullable = "inherit"), the server's stock_qty rule has
      // no nullable: an emptied number input leaves v-model.number holding
      // '', which the global ConvertEmptyStringsToNull middleware turns into
      // null and the bare 'integer' rule would then reject. Coerce here
      // instead of teaching the matrix its own per-field error display.
      stock_qty: Number(variant.stock_qty) || 0,
      active: variant.active,
    },
    {
      preserveScroll: true,
      // Only a confirmed-successful save clears 'dirty': on failure the row
      // must stay flagged so the merge above keeps showing the merchant's
      // (rejected, still-unsaved) edit instead of quietly re-seeding from
      // whatever the server still has on record.
      onSuccess: () => (variant.dirty = false),
    },
  )
}

/**
 * A single pending destructive action, routed through one shared
 * ConfirmDialog (same convention as the product-delete dialog below and
 * Categories/Index.vue's category-delete dialog) instead of three separate
 * ones or a native confirm().
 */
type PendingVariantDelete =
  | { kind: 'option'; option: ProductOption }
  | { kind: 'value'; option: ProductOption; value: ProductOptionValue }
  | { kind: 'variant'; variant: ProductVariant }

const pendingVariantDelete = ref<PendingVariantDelete | null>(null)
const variantDeleteProcessing = ref(false)

const confirmRemoveOption = (option: ProductOption) => (pendingVariantDelete.value = { kind: 'option', option })
const confirmRemoveValue = (option: ProductOption, value: ProductOptionValue) =>
  (pendingVariantDelete.value = { kind: 'value', option, value })
const confirmRemoveVariant = (variant: ProductVariant) => (pendingVariantDelete.value = { kind: 'variant', variant })

const variantDeleteTitle = computed(() => {
  const target = pendingVariantDelete.value

  if (target?.kind === 'option') return 'Odebrat vlastnost'
  if (target?.kind === 'value') return 'Odebrat hodnotu'
  if (target?.kind === 'variant') return 'Smazat variantu'

  return ''
})

// Deleting an axis or a value also deletes every variant built on it — the
// confirmation text says so, rather than a generic "are you sure".
const variantDeleteMessage = computed(() => {
  const target = pendingVariantDelete.value

  if (target?.kind === 'option') {
    return `Opravdu odebrat vlastnost „${target.option.name}“? Smažou se i varianty, které tuto vlastnost používají.`
  }

  if (target?.kind === 'value') {
    return `Opravdu odebrat hodnotu „${target.value.value}“? Smažou se varianty, které tuto hodnotu používají.`
  }

  if (target?.kind === 'variant') {
    return `Opravdu smazat variantu „${target.variant.label}“?`
  }

  return ''
})

const runVariantDelete = () => {
  const target = pendingVariantDelete.value

  if (!target) return

  variantDeleteProcessing.value = true

  const onFinish = () => {
    variantDeleteProcessing.value = false
    pendingVariantDelete.value = null
  }

  if (target.kind === 'option') {
    router.delete(
      route('admin.products.variants.options.destroy', [props.product.slug, target.option.id]),
      { preserveScroll: true, onFinish },
    )

    return
  }

  if (target.kind === 'value') {
    router.delete(
      route('admin.products.variants.values.destroy', [props.product.slug, target.option.id, target.value.id]),
      { preserveScroll: true, onFinish },
    )

    return
  }

  router.delete(
    route('admin.products.variants.destroy', [props.product.slug, target.variant.id]),
    { preserveScroll: true, onFinish },
  )
}
</script>

<template>
  <AdminLayout :title="product.name">
    <template #header>
      <p class="text-sm text-gray-700">
        <Link
          :href="route('admin.products.index')"
          class="underline hover:no-underline focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
        >
          Produkty
        </Link>
      </p>
      <h1 class="mt-1 text-xl font-semibold text-gray-900">{{ product.name }}</h1>
      <p class="mt-1 text-sm text-gray-700"><code>{{ product.url }}</code></p>
    </template>

    <div class="rounded-lg border border-gray-200 bg-white shadow-sm">
      <!-- Tabs as a real tablist: arrow keys move between them, and the panel
           is associated with its tab rather than merely rendered nearby. -->
      <div role="tablist" aria-label="Sekce karty produktu" class="flex flex-wrap gap-1 border-b border-gray-200 p-2">
        <button
          v-for="(item, index) in TABS"
          :id="`tab-${item.key}`"
          :key="item.key"
          type="button"
          role="tab"
          :aria-selected="tab === item.key"
          :aria-controls="`panel-${item.key}`"
          :tabindex="tab === item.key ? 0 : -1"
          class="rounded-md px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 aria-selected:bg-gray-900 aria-selected:text-white"
          @click="tab = item.key"
          @keydown="onTabKeydown($event, index)"
        >
          {{ item.label }}
        </button>
      </div>

      <form class="p-4" @submit.prevent="save">
        <div
          v-show="tab === 'basic'"
          :id="'panel-basic'"
          role="tabpanel"
          aria-labelledby="tab-basic"
          class="grid gap-4 sm:grid-cols-2"
        >
          <div>
            <label for="p-name" class="block text-sm font-medium text-gray-700">Název</label>
            <input
              id="p-name"
              v-model="form.name"
              type="text"
              class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
              :aria-invalid="form.errors.name ? 'true' : undefined"
              :aria-describedby="form.errors.name ? 'p-name-error' : undefined"
            />
            <p v-if="form.errors.name" id="p-name-error" class="mt-1 text-sm text-red-700">
              {{ form.errors.name }}
            </p>
          </div>

          <div>
            <label for="p-slug" class="block text-sm font-medium text-gray-700">URL adresa</label>
            <input
              id="p-slug"
              v-model="form.slug"
              type="text"
              class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
              :aria-invalid="form.errors.slug ? 'true' : undefined"
              :aria-describedby="describedBy('p-slug', 'slug')"
            />
            <p id="p-slug-hint" class="mt-1 text-sm text-gray-600">
              Změna adresy nechá na staré trvalé přesměrování.
            </p>
            <p v-if="form.errors.slug" id="p-slug-error" class="mt-1 text-sm text-red-700">
              {{ form.errors.slug }}
            </p>
          </div>

          <div>
            <label for="p-status" class="block text-sm font-medium text-gray-700">Stav</label>
            <select
              id="p-status"
              v-model="form.status"
              class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
            >
              <option value="draft">Koncept — není veřejný</option>
              <option value="active">Aktivní — prodává se</option>
              <option value="hidden">Skrytý — jen na přímý odkaz</option>
            </select>
          </div>

          <div>
            <label for="p-sku" class="block text-sm font-medium text-gray-700">Kód (SKU)</label>
            <input
              id="p-sku"
              v-model="form.sku"
              type="text"
              class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
            />
          </div>

          <div>
            <label for="p-ean" class="block text-sm font-medium text-gray-700">EAN</label>
            <input
              id="p-ean"
              v-model="form.ean"
              type="text"
              inputmode="numeric"
              class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
              :aria-invalid="form.errors.ean ? 'true' : undefined"
              :aria-describedby="form.errors.ean ? 'p-ean-error' : undefined"
            />
            <p v-if="form.errors.ean" id="p-ean-error" class="mt-1 text-sm text-red-700">
              {{ form.errors.ean }}
            </p>
          </div>

          <div>
            <label for="p-manufacturer" class="block text-sm font-medium text-gray-700">Výrobce</label>
            <input
              id="p-manufacturer"
              v-model="form.manufacturer"
              type="text"
              class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
            />
          </div>

          <div class="sm:col-span-2">
            <label for="p-short" class="block text-sm font-medium text-gray-700">
              Krátký popis
            </label>
            <textarea
              id="p-short"
              v-model="form.short_description"
              rows="2"
              maxlength="240"
              class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
              aria-describedby="p-short-hint"
            />
            <p id="p-short-hint" class="mt-1 text-sm text-gray-600">
              Nejvýše 240 znaků. Zobrazuje se ve výpisech.
            </p>
          </div>

          <div class="sm:col-span-2">
            <label for="p-description" class="block text-sm font-medium text-gray-700">Popis</label>
            <textarea
              id="p-description"
              v-model="form.description"
              rows="8"
              class="mt-1 w-full rounded-md border-gray-300 font-mono text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
              aria-describedby="p-description-hint"
            />
            <p id="p-description-hint" class="mt-1 text-sm text-gray-600">
              Povolené HTML: odstavce, tučné, kurzíva, seznamy, nadpisy, odkazy, obrázky, tabulky.
              Ostatní se při uložení odstraní.
            </p>
          </div>

          <fieldset class="sm:col-span-2">
            <legend class="text-sm font-medium text-gray-700">Kategorie</legend>
            <div class="mt-2 grid gap-1 sm:grid-cols-2">
              <label v-for="category in categories" :key="category.id" class="flex items-center gap-2 text-sm">
                <input
                  v-model="form.category_ids"
                  type="checkbox"
                  :value="category.id"
                  class="rounded border-gray-300 text-gray-900 focus:ring-gray-900"
                />
                <span :style="{ paddingLeft: category.depth * 12 + 'px' }">{{ category.name }}</span>
              </label>
            </div>

            <div class="mt-3">
              <label for="p-primary-category" class="block text-sm font-medium text-gray-700">
                Hlavní kategorie
              </label>
              <select
                id="p-primary-category"
                v-model="form.primary_category_id"
                class="mt-1 rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
                aria-describedby="p-primary-hint"
              >
                <option :value="null">— první vybraná —</option>
                <option
                  v-for="category in categories.filter((c) => form.category_ids.includes(c.id))"
                  :key="category.id"
                  :value="category.id"
                >
                  {{ category.name }}
                </option>
              </select>
              <p id="p-primary-hint" class="mt-1 text-sm text-gray-600">
                Použije se v drobečkové navigaci na e-shopu.
              </p>
            </div>
          </fieldset>

          <div>
            <label for="p-variant-display" class="block text-sm font-medium text-gray-700">
              Zobrazení výběru varianty
            </label>
            <select
              id="p-variant-display"
              v-model="form.variant_display"
              class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
              aria-describedby="p-variant-display-hint"
            >
              <option :value="null">Zdědit z nastavení obchodu</option>
              <option value="radio">Přepínače (radio)</option>
              <option value="select">Rozbalovací seznam</option>
            </select>
            <p id="p-variant-display-hint" class="mt-1 text-sm text-gray-600">
              Platí jen pro produkty s variantami. Bez volby se použije nastavení celého obchodu.
            </p>
          </div>
        </div>

        <div
          v-show="tab === 'prices'"
          :id="'panel-prices'"
          role="tabpanel"
          aria-labelledby="tab-prices"
          class="grid gap-4 sm:grid-cols-2"
        >
          <p v-if="variants.length" class="rounded-md bg-amber-50 p-3 text-sm text-amber-900 sm:col-span-2">
            Produkt má varianty — tato cena platí jen pro varianty bez vlastní ceny.
          </p>

          <div>
            <label for="p-price" class="block text-sm font-medium text-gray-700">
              {{ vatApplies ? 'Cena s DPH (Kč)' : 'Cena (Kč)' }}
            </label>
            <input
              id="p-price"
              v-model="form.price"
              type="text"
              inputmode="decimal"
              class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
              aria-describedby="p-price-hint"
              :aria-invalid="form.errors.price ? 'true' : undefined"
              @input="form.net_price = null"
            />
            <p id="p-price-hint" class="mt-1 text-sm text-gray-600">
              <template v-if="vatApplies">
                {{ money(korunas(form.price)) }} · bez DPH přibližně {{ money(netPreview) }}
              </template>
              <template v-else>{{ money(korunas(form.price)) }}</template>
            </p>
            <p v-if="form.errors.price" class="mt-1 text-sm text-red-700">{{ form.errors.price }}</p>
          </div>

          <!--
            Entering the price without VAT (wave 3.7). Wholesale price lists
            quote net, and retyping them through a calculator is how a haléř
            gets lost.

            The conversion is the server's: filling this in clears the gross
            field, and the request computes it. Doing the arithmetic here would
            round differently often enough that the merchant would watch the
            price change on save.
          -->
          <div v-if="vatApplies">
            <label for="p-net-price" class="block text-sm font-medium text-gray-700">
              Cena bez DPH (Kč)
            </label>
            <input
              id="p-net-price"
              v-model="form.net_price"
              type="text"
              inputmode="decimal"
              class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
              aria-describedby="p-net-price-hint"
              @input="form.price = null"
            />
            <p id="p-net-price-hint" class="mt-1 text-sm text-gray-600">
              Vyplňte jedno z polí — cenu s DPH nebo bez ní. Druhé dopočítá server podle sazby.
            </p>
            <p v-if="form.errors.net_price" class="mt-1 text-sm text-red-700">
              {{ form.errors.net_price }}
            </p>
          </div>

          <div v-if="vatApplies">
            <label for="p-rate" class="block text-sm font-medium text-gray-700">Sazba DPH</label>
            <select
              id="p-rate"
              v-model.number="form.tax_rate_id"
              class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
            >
              <option v-for="option in taxRates" :key="option.id" :value="option.id">
                {{ option.name }}
              </option>
            </select>
          </div>

          <div>
            <label for="p-sale" class="block text-sm font-medium text-gray-700">
              Akční cena (Kč)
            </label>
            <input
              id="p-sale"
              v-model="form.sale_price"
              type="number"
              min="0"
              class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
              aria-describedby="p-sale-hint"
            />
            <p id="p-sale-hint" class="mt-1 text-sm text-gray-600">
              Musí být nižší než běžná cena. Na e-shopu se vedle ní ukáže přeškrtnutá běžná cena
              a povinný údaj o nejnižší ceně za posledních 30 dní.
            </p>
            <p v-if="form.errors.sale_price" class="mt-1 text-sm text-red-700">
              {{ form.errors.sale_price }}
            </p>
          </div>

          <div>
            <label for="p-sale-from" class="block text-sm font-medium text-gray-700">
              Akce od
            </label>
            <input
              id="p-sale-from"
              v-model="form.sale_starts_at"
              type="datetime-local"
              class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
              aria-describedby="p-sale-from-hint"
            />
            <p id="p-sale-from-hint" class="mt-1 text-sm text-gray-600">
              Prázdné = akce platí ihned.
            </p>
            <p v-if="form.errors.sale_starts_at" class="mt-1 text-sm text-red-700">
              {{ form.errors.sale_starts_at }}
            </p>
          </div>

          <div>
            <label for="p-sale-to" class="block text-sm font-medium text-gray-700">
              Akce do
            </label>
            <input
              id="p-sale-to"
              v-model="form.sale_ends_at"
              type="datetime-local"
              class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
              aria-describedby="p-sale-to-hint"
            />
            <p id="p-sale-to-hint" class="mt-1 text-sm text-gray-600">
              Prázdné = akce běží, dokud ji neukončíte. Konec se uplatní sám, bez zásahu.
            </p>
            <p v-if="form.errors.sale_ends_at" class="mt-1 text-sm text-red-700">
              {{ form.errors.sale_ends_at }}
            </p>
          </div>

          <div v-if="can.costs">
            <label for="p-purchase" class="block text-sm font-medium text-gray-700">
              Nákupní cena (Kč)
            </label>
            <input
              id="p-purchase"
              v-model="form.purchase_price"
              type="text"
              inputmode="decimal"
              class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
              aria-describedby="p-purchase-hint"
            />
            <p id="p-purchase-hint" class="mt-1 text-sm text-gray-600">
              Vidí jen uživatelé s právem na nákupní ceny. Na e-shopu se nezobrazuje.
            </p>
          </div>
        </div>

        <div
          v-show="tab === 'stock'"
          :id="'panel-stock'"
          role="tabpanel"
          aria-labelledby="tab-stock"
          class="grid gap-4 sm:grid-cols-2"
        >
          <p v-if="variants.length" class="rounded-md bg-amber-50 p-3 text-sm text-amber-900 sm:col-span-2">
            Produkt má varianty — sklad se sleduje na jednotlivých variantách, tato hodnota se nepoužije.
          </p>

          <div class="flex items-center gap-2">
            <input
              id="p-tracked"
              v-model="form.stock_tracked"
              type="checkbox"
              class="rounded border-gray-300 text-gray-900 focus:ring-gray-900"
            />
            <label for="p-tracked" class="text-sm text-gray-700">Sledovat skladovou zásobu</label>
          </div>

          <div>
            <label for="p-qty" class="block text-sm font-medium text-gray-700">Množství</label>
            <input
              id="p-qty"
              v-model.number="form.stock_qty"
              type="number"
              :disabled="!form.stock_tracked"
              class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900 disabled:bg-gray-100"
            />
          </div>

          <div>
            <label for="p-policy" class="block text-sm font-medium text-gray-700">
              Když je vyprodáno
            </label>
            <select
              id="p-policy"
              v-model="form.stock_policy"
              class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
            >
              <option value="hide">Skrýt produkt</option>
              <option value="show_sold_out">Zobrazit jako vyprodané</option>
              <option value="backorder">Dovolit objednat</option>
            </select>
          </div>

          <div>
            <label for="p-alert" class="block text-sm font-medium text-gray-700">
              Upozornit při poklesu pod
            </label>
            <input
              id="p-alert"
              v-model.number="form.stock_alert_qty"
              type="number"
              min="0"
              class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
            />
          </div>

          <div>
            <label for="p-weight" class="block text-sm font-medium text-gray-700">
              Hmotnost (g)
            </label>
            <input
              id="p-weight"
              v-model.number="form.weight_g"
              type="number"
              min="0"
              max="200000"
              class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
              :aria-invalid="form.errors.weight_g ? 'true' : undefined"
              :aria-describedby="describedBy('p-weight', 'weight_g')"
            />
            <p id="p-weight-hint" class="mt-1 text-sm text-gray-600">
              Používá se pro výpočet ceny dopravy.
            </p>
            <p v-if="form.errors.weight_g" id="p-weight-error" class="mt-1 text-sm text-red-700">
              {{ form.errors.weight_g }}
            </p>
          </div>

          <!--
            Dimensions (wave 3.8). Millimetres, because that is what a carrier
            API takes; the product page shows them in centimetres.
          -->
          <fieldset>
            <legend class="block text-sm font-medium text-gray-700">Rozměry (mm)</legend>
            <p id="p-dimensions-hint" class="mt-1 text-sm text-gray-600">
              Nepovinné. Vyplňte všechny tři, nebo žádný — zákazník je uvidí v parametrech a
              dopravce je dostane při podání zásilky.
            </p>

            <div class="mt-2 grid gap-4 sm:grid-cols-3">
              <div>
                <label for="p-length" class="block text-sm text-gray-700">Délka</label>
                <input
                  id="p-length"
                  v-model.number="form.length_mm"
                  type="number"
                  min="1"
                  max="2000"
                  aria-describedby="p-dimensions-hint"
                  class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
                />
                <p v-if="form.errors.length_mm" class="mt-1 text-sm text-red-700">
                  {{ form.errors.length_mm }}
                </p>
              </div>

              <div>
                <label for="p-width" class="block text-sm text-gray-700">Šířka</label>
                <input
                  id="p-width"
                  v-model.number="form.width_mm"
                  type="number"
                  min="1"
                  max="2000"
                  aria-describedby="p-dimensions-hint"
                  class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
                />
                <p v-if="form.errors.width_mm" class="mt-1 text-sm text-red-700">
                  {{ form.errors.width_mm }}
                </p>
              </div>

              <div>
                <label for="p-height" class="block text-sm text-gray-700">Výška</label>
                <input
                  id="p-height"
                  v-model.number="form.height_mm"
                  type="number"
                  min="1"
                  max="2000"
                  aria-describedby="p-dimensions-hint"
                  class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
                />
                <p v-if="form.errors.height_mm" class="mt-1 text-sm text-red-700">
                  {{ form.errors.height_mm }}
                </p>
              </div>
            </div>
          </fieldset>
        </div>

        <div
          v-show="tab === 'seo'"
          :id="'panel-seo'"
          role="tabpanel"
          aria-labelledby="tab-seo"
          class="grid gap-4"
        >
          <div>
            <label for="p-seo-title" class="block text-sm font-medium text-gray-700">
              Titulek stránky
            </label>
            <input
              id="p-seo-title"
              v-model="form.seo_title"
              type="text"
              maxlength="191"
              class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
              aria-describedby="p-seo-title-hint"
            />
            <p id="p-seo-title-hint" class="mt-1 text-sm text-gray-600">
              {{ form.seo_title.length }} znaků. Ve výsledcích hledání se obvykle zobrazí prvních 60.
            </p>
          </div>

          <div>
            <label for="p-seo-desc" class="block text-sm font-medium text-gray-700">
              Popisek stránky
            </label>
            <textarea
              id="p-seo-desc"
              v-model="form.seo_description"
              rows="3"
              maxlength="500"
              class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
              aria-describedby="p-seo-desc-hint"
            />
            <p id="p-seo-desc-hint" class="mt-1 text-sm text-gray-600">
              {{ form.seo_description.length }} znaků. Doporučeno 120–160.
            </p>
          </div>
        </div>

        <!--
          Only on the tabs this form actually saves. The images and variants
          panels are separate forms of their own, and a Save button floating
          above them looked like it belonged to what was underneath — which is
          why the "set as main image" control went unnoticed (wave 3.8).
        -->
        <div
          v-show="formTab"
          class="mt-6 flex flex-wrap items-center gap-3 border-t border-gray-200 pt-4"
        >
          <button
            v-if="can.edit"
            type="submit"
            :disabled="form.processing"
            class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:bg-gray-400 disabled:text-gray-700"
          >
            Uložit
          </button>

          <button
            v-if="can.edit"
            type="button"
            class="rounded-md px-4 py-2 text-sm font-semibold text-red-800 hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-800"
            @click="deleting = true"
          >
            Smazat produkt
          </button>
        </div>
      </form>

      <!-- Outside the main form: a file input inside it would be submitted
           with every save. -->
      <div
        v-show="tab === 'images'"
        id="panel-images"
        role="tabpanel"
        aria-labelledby="tab-images"
        class="border-t border-gray-200 p-4"
      >
        <div v-if="can.edit" class="mb-4 flex flex-wrap items-end gap-3">
          <div>
            <label for="p-images" class="block text-sm font-medium text-gray-700">
              Přidat obrázky
            </label>
            <input
              id="p-images"
              type="file"
              multiple
              accept="image/jpeg,image/png,image/webp"
              class="mt-1 block text-sm"
              aria-describedby="p-images-hint"
              @change="uploads = Array.from(($event.target as HTMLInputElement).files ?? [])"
            />
            <p id="p-images-hint" class="mt-1 text-sm text-gray-600">
              JPG, PNG nebo WebP, nejvýše 8 MB na soubor.
            </p>
          </div>

          <button
            type="button"
            :disabled="!uploads?.length"
            class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:bg-gray-400 disabled:text-gray-700"
            @click="uploadImages"
          >
            Nahrát
          </button>
        </div>

        <!--
          A place to drop files, beside the button and never instead of it:
          dropping is not something a keyboard can do (WCAG 2.1.1).
          aria-hidden because it duplicates the input above — a screen reader
          gains nothing from being told about a target it cannot use.
        -->
        <div
          v-if="can.edit"
          aria-hidden="true"
          class="mb-6 rounded-lg border-2 border-dashed p-6 text-center text-sm transition-colors"
          :class="dragging ? 'border-gray-900 bg-gray-50 text-gray-900' : 'border-gray-300 text-gray-600'"
          @dragover.prevent="dragging = true"
          @dragleave.prevent="dragging = false"
          @drop.prevent="onDrop"
        >
          Přetáhněte sem obrázky, nebo je vyberte tlačítkem výše.
        </div>

        <p v-if="product.images.length === 0" class="py-6 text-gray-600">
          Produkt zatím nemá obrázky.
        </p>

        <ul v-else class="grid gap-4 sm:grid-cols-3 lg:grid-cols-4">
          <li
            v-for="(image, index) in product.images"
            :key="image.id"
            class="rounded-md border border-gray-200 p-2"
          >
            <!-- Never a bare alt="": these are content, and an empty alt tells
                 a screen reader the image carries nothing. Until the shop
                 writes one, say what the picture is of. -->
            <img
              :src="image.url"
              :alt="image.alt || `Fotografie produktu ${product.name}`"
              class="h-32 w-full rounded object-cover"
            />

            <p class="mt-2 text-xs text-gray-700">
              {{ image.is_main ? 'Hlavní obrázek' : 'Doplňkový obrázek' }}
            </p>

            <div v-if="can.edit" class="mt-2 flex flex-wrap gap-2">
              <!-- Buttons, not dragging: the order has to be changeable from
                   a keyboard (rozhodnutí 2026-07-20). -->
              <button
                type="button"
                :disabled="index === 0"
                class="rounded-md border border-gray-300 px-2 py-1 text-xs font-medium text-gray-800 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 disabled:cursor-not-allowed disabled:text-gray-400"
                @click="moveImage(index, -1)"
              >
                ←<span class="sr-only"> Posunout obrázek {{ index + 1 }} dopředu</span>
              </button>
              <button
                type="button"
                :disabled="index === product.images.length - 1"
                class="rounded-md border border-gray-300 px-2 py-1 text-xs font-medium text-gray-800 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 disabled:cursor-not-allowed disabled:text-gray-400"
                @click="moveImage(index, 1)"
              >
                →<span class="sr-only"> Posunout obrázek {{ index + 1 }} dozadu</span>
              </button>
              <button
                v-if="!image.is_main"
                type="button"
                class="rounded-md border border-gray-300 px-2 py-1 text-xs font-medium text-gray-800 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
                @click="setMain(image)"
              >
                Nastavit jako hlavní
              </button>
              <button
                type="button"
                class="rounded-md px-2 py-1 text-xs font-medium text-red-800 hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-800"
                @click="removeImage(image)"
              >
                Smazat obrázek
              </button>
            </div>
          </li>
        </ul>
      </div>

      <!-- Outside the main form, same reason as the images panel: each axis
           and value has its own <form> to submit, and a <form> nested inside
           another <form> is invalid HTML. -->
      <section
        v-show="tab === 'variants'"
        id="panel-variants"
        role="tabpanel"
        aria-labelledby="tab-variants"
        class="border-t border-gray-200 p-4"
      >
        <h2 class="text-lg font-semibold text-gray-900">Vlastnosti a varianty</h2>
        <p class="mt-1 text-sm text-gray-600">
          Přidejte vlastnost (např. Velikost) a její hodnoty, pak vygenerujte kombinace.
          Když má produkt varianty, sleduje se sklad a cena na variantě.
        </p>

        <div v-for="(option, index) in options" :key="option.id" class="mt-6 rounded-lg border border-gray-200 p-4">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <h3 class="font-medium text-gray-900">{{ option.name }}</h3>

            <!-- Buttons, not drag & drop: reordering must be operable from
                 the keyboard (WCAG 2.1.1). -->
            <div v-if="can.edit" class="flex items-center gap-1">
              <button
                type="button"
                class="rounded-md px-2 py-1.5 text-sm text-gray-700 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 disabled:cursor-not-allowed disabled:text-gray-400"
                :disabled="index === 0"
                @click="moveOption(option, 'up')"
              >
                <span aria-hidden="true">↑</span>
                <span class="sr-only">Posunout vlastnost {{ option.name }} nahoru</span>
              </button>
              <button
                type="button"
                class="rounded-md px-2 py-1.5 text-sm text-gray-700 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 disabled:cursor-not-allowed disabled:text-gray-400"
                :disabled="index === options.length - 1"
                @click="moveOption(option, 'down')"
              >
                <span aria-hidden="true">↓</span>
                <span class="sr-only">Posunout vlastnost {{ option.name }} dolů</span>
              </button>
              <button
                type="button"
                class="rounded-md px-2 py-1.5 text-sm font-medium text-red-800 hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-800"
                @click="confirmRemoveOption(option)"
              >
                Odebrat<span class="sr-only"> vlastnost {{ option.name }}</span>
              </button>
            </div>
          </div>

          <ul class="mt-3 flex flex-wrap gap-2">
            <li
              v-for="(value, valueIndex) in option.values"
              :key="value.id"
              class="inline-flex items-center gap-1 rounded-full bg-gray-100 py-1 pl-3 pr-1 text-sm text-gray-800"
            >
              {{ value.value }}

              <span v-if="can.edit" class="flex items-center gap-0.5">
                <button
                  type="button"
                  class="rounded-full p-1.5 text-gray-600 hover:bg-gray-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 disabled:cursor-not-allowed disabled:text-gray-400"
                  :disabled="valueIndex === 0"
                  @click="moveValue(option, value, 'up')"
                >
                  <span aria-hidden="true">↑</span>
                  <span class="sr-only">Posunout hodnotu {{ value.value }} nahoru</span>
                </button>
                <button
                  type="button"
                  class="rounded-full p-1.5 text-gray-600 hover:bg-gray-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 disabled:cursor-not-allowed disabled:text-gray-400"
                  :disabled="valueIndex === option.values.length - 1"
                  @click="moveValue(option, value, 'down')"
                >
                  <span aria-hidden="true">↓</span>
                  <span class="sr-only">Posunout hodnotu {{ value.value }} dolů</span>
                </button>
                <button
                  type="button"
                  class="rounded-full p-1.5 text-red-800 hover:bg-red-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-800"
                  @click="confirmRemoveValue(option, value)"
                >
                  <span aria-hidden="true">×</span>
                  <span class="sr-only">Odebrat hodnotu {{ value.value }}</span>
                </button>
              </span>
            </li>
          </ul>

          <form v-if="can.edit" class="mt-3 flex flex-wrap items-end gap-2" @submit.prevent="addValue(option)">
            <div>
              <label :for="`new-value-${option.id}`" class="sr-only">
                Nová hodnota vlastnosti {{ option.name }}
              </label>
              <input
                :id="`new-value-${option.id}`"
                v-model="newValue[option.id]"
                type="text"
                placeholder="Nová hodnota, např. M"
                class="rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
              />
            </div>
            <button
              type="submit"
              class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
            >
              Přidat hodnotu
            </button>
          </form>
        </div>

        <form v-if="can.edit" class="mt-6 flex flex-wrap items-end gap-2" @submit.prevent="addOption">
          <div>
            <label for="new-option-name" class="block text-sm font-medium text-gray-700">Nová vlastnost</label>
            <input
              id="new-option-name"
              v-model="newOption"
              type="text"
              placeholder="např. Velikost"
              class="mt-1 rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
            />
          </div>
          <button
            type="submit"
            class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
          >
            Přidat vlastnost
          </button>
        </form>

        <button
          v-if="can.edit && options.length"
          type="button"
          class="mt-6 rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
          @click="generate"
        >
          Generovat varianty
        </button>

        <div v-if="rows.length" class="mt-6 overflow-x-auto">
          <table class="w-full text-left text-sm">
            <caption class="sr-only">Varianty produktu {{ product.name }}</caption>
            <thead>
              <tr class="text-gray-500">
                <th scope="col" class="py-2 pr-2 font-medium">Kombinace</th>
                <th scope="col" class="px-2 py-2 font-medium">Cena (Kč)</th>
                <th v-if="vatApplies" scope="col" class="px-2 py-2 font-medium">Bez DPH</th>
                <th scope="col" class="px-2 py-2 font-medium">Akční cena</th>
                <th scope="col" class="px-2 py-2 font-medium">SKU</th>
                <th scope="col" class="px-2 py-2 font-medium">Sleduje sklad</th>
                <th scope="col" class="px-2 py-2 font-medium">Sklad (ks)</th>
                <th scope="col" class="px-2 py-2 font-medium">Aktivní</th>
                <th v-if="can.edit" scope="col" class="py-2 pl-2"><span class="sr-only">Akce</span></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="variant in rows" :key="variant.id" class="border-t border-gray-100">
                <th scope="row" class="py-2 pr-2 text-left font-normal text-gray-900">{{ variant.label }}</th>

                <td class="px-2 py-2">
                  <label :for="`variant-price-${variant.id}`" class="sr-only">
                    Cena varianty {{ variant.label }} v korunách
                  </label>
                  <input
                    :id="`variant-price-${variant.id}`"
                    v-model="variant.price"
                    type="text"
                    inputmode="decimal"
                    placeholder="dědí"
                    :disabled="!can.edit"
                    class="w-28 rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900 disabled:bg-gray-100"
                    @input="variant.net_price = null; markDirty(variant)"
                  />
                </td>

                <td v-if="vatApplies" class="px-2 py-2">
                  <label :for="`variant-net-price-${variant.id}`" class="sr-only">
                    Cena varianty {{ variant.label }} bez DPH v korunách
                  </label>
                  <input
                    :id="`variant-net-price-${variant.id}`"
                    v-model="variant.net_price"
                    type="text"
                    inputmode="decimal"
                    :placeholder="variant.price === null ? 'dědí' : String(netOf(variant.price))"
                    :disabled="!can.edit"
                    class="w-28 rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900 disabled:bg-gray-100"
                    @input="variant.price = null; markDirty(variant)"
                  />
                </td>

                <td class="px-2 py-2">
                  <label :for="`variant-sale-${variant.id}`" class="sr-only">
                    Akční cena varianty {{ variant.label }} v korunách
                  </label>
                  <input
                    :id="`variant-sale-${variant.id}`"
                    v-model="variant.sale_price"
                    type="text"
                    inputmode="decimal"
                    step="1"
                    placeholder="bez akce"
                    :disabled="!can.edit"
                    class="w-28 rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900 disabled:bg-gray-100"
                    @input="markDirty(variant)"
                  />
                </td>

                <td class="px-2 py-2">
                  <label :for="`variant-sku-${variant.id}`" class="sr-only">SKU varianty {{ variant.label }}</label>
                  <input
                    :id="`variant-sku-${variant.id}`"
                    v-model="variant.sku"
                    type="text"
                    :disabled="!can.edit"
                    class="w-32 rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900 disabled:bg-gray-100"
                    @input="markDirty(variant)"
                  />
                </td>

                <td class="px-2 py-2">
                  <input
                    :id="`variant-tracked-${variant.id}`"
                    v-model="variant.stock_tracked"
                    type="checkbox"
                    :disabled="!can.edit"
                    class="rounded border-gray-300 text-gray-900 focus:ring-gray-900"
                    @change="markDirty(variant)"
                  />
                  <label :for="`variant-tracked-${variant.id}`" class="sr-only">
                    Varianta {{ variant.label }} sleduje skladovou zásobu
                  </label>
                </td>

                <td class="px-2 py-2">
                  <label :for="`variant-stock-${variant.id}`" class="sr-only">
                    Skladová zásoba varianty {{ variant.label }}
                  </label>
                  <input
                    :id="`variant-stock-${variant.id}`"
                    v-model.number="variant.stock_qty"
                    type="number"
                    :disabled="!can.edit || !variant.stock_tracked"
                    class="w-20 rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900 disabled:bg-gray-100"
                    @input="markDirty(variant)"
                  />
                </td>

                <td class="px-2 py-2">
                  <input
                    :id="`variant-active-${variant.id}`"
                    v-model="variant.active"
                    type="checkbox"
                    :disabled="!can.edit"
                    class="rounded border-gray-300 text-gray-900 focus:ring-gray-900"
                    @change="markDirty(variant)"
                  />
                  <label :for="`variant-active-${variant.id}`" class="sr-only">
                    Varianta {{ variant.label }} je aktivní
                  </label>
                </td>

                <td v-if="can.edit" class="py-2 pl-2 text-right whitespace-nowrap">
                  <button
                    type="button"
                    class="rounded-md border border-gray-300 bg-white px-2 py-1 text-xs font-semibold text-gray-800 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
                    @click="saveVariant(variant)"
                  >
                    Uložit<span class="sr-only"> variantu {{ variant.label }}</span>
                  </button>
                  <button
                    type="button"
                    class="ml-1 rounded-md px-2 py-1 text-xs font-semibold text-red-800 hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-800"
                    @click="confirmRemoveVariant(variant)"
                  >
                    Smazat<span class="sr-only"> variantu {{ variant.label }}</span>
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <p v-else-if="options.length" class="mt-6 text-sm text-gray-600">
          Zatím žádné varianty. Přidejte hodnoty vlastností a klikněte na „Generovat varianty“.
        </p>
      </section>
    </div>

    <ConfirmDialog
      :show="deleting"
      title="Smazat produkt"
      :message="`Opravdu smazat produkt ${product.name}? Zůstane u již vytvořených objednávek, ale z e-shopu zmizí.`"
      confirm-label="Smazat"
      danger
      @cancel="deleting = false"
      @confirm="confirmDelete"
    />

    <ConfirmDialog
      :show="pendingVariantDelete !== null"
      :title="variantDeleteTitle"
      :message="variantDeleteMessage"
      confirm-label="Smazat"
      danger
      :processing="variantDeleteProcessing"
      @cancel="pendingVariantDelete = null"
      @confirm="runVariantDelete"
    />
  </AdminLayout>
</template>
