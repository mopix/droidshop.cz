<script setup lang="ts">
import { computed, nextTick, ref } from 'vue'
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
  price: number
  net_price: number
  compare_at_price: number | null
  purchase_price: number | null
  tax_rate_id: number
  sku: string | null
  ean: string | null
  manufacturer: string | null
  weight_g: number
  stock_tracked: boolean
  stock_qty: number
  stock_policy: string
  stock_alert_qty: number | null
  seo_title: string | null
  seo_description: string | null
  url: string
  images: ProductImage[]
  category_ids: number[]
  primary_category_id: number | null
}

const props = defineProps<{
  product: Product
  taxRates: { id: number; name: string; percent: number }[]
  categories: { id: number; name: string; depth: number }[]
  can: { edit: boolean; costs: boolean }
}>()

const TABS = [
  { key: 'basic', label: 'Základní' },
  { key: 'prices', label: 'Ceny' },
  { key: 'images', label: 'Obrázky' },
  { key: 'stock', label: 'Sklad' },
  { key: 'seo', label: 'SEO' },
] as const

const tab = ref<(typeof TABS)[number]['key']>('basic')

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
  compare_at_price: props.product.compare_at_price,
  purchase_price: props.product.purchase_price,
  tax_rate_id: props.product.tax_rate_id,
  sku: props.product.sku ?? '',
  ean: props.product.ean ?? '',
  manufacturer: props.product.manufacturer ?? '',
  weight_g: props.product.weight_g,
  stock_tracked: props.product.stock_tracked,
  stock_qty: props.product.stock_qty,
  stock_policy: props.product.stock_policy,
  stock_alert_qty: props.product.stock_alert_qty,
  category_ids: [...props.product.category_ids],
  primary_category_id: props.product.primary_category_id,
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
const netPreview = computed(() => {
  const percent = rate.value?.percent ?? 0

  return Math.round(form.price / (1 + percent / 100))
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
        </div>

        <div
          v-show="tab === 'prices'"
          :id="'panel-prices'"
          role="tabpanel"
          aria-labelledby="tab-prices"
          class="grid gap-4 sm:grid-cols-2"
        >
          <div>
            <label for="p-price" class="block text-sm font-medium text-gray-700">
              Cena s DPH (v haléřích)
            </label>
            <input
              id="p-price"
              v-model.number="form.price"
              type="number"
              min="0"
              step="1"
              class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
              aria-describedby="p-price-hint"
              :aria-invalid="form.errors.price ? 'true' : undefined"
            />
            <p id="p-price-hint" class="mt-1 text-sm text-gray-600">
              {{ money(form.price) }} · bez DPH přibližně {{ money(netPreview) }}
            </p>
            <p v-if="form.errors.price" class="mt-1 text-sm text-red-700">{{ form.errors.price }}</p>
          </div>

          <div>
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
            <label for="p-compare" class="block text-sm font-medium text-gray-700">
              Běžná cena (přeškrtnutá)
            </label>
            <input
              id="p-compare"
              v-model.number="form.compare_at_price"
              type="number"
              min="0"
              class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
            />
          </div>

          <div v-if="can.costs">
            <label for="p-purchase" class="block text-sm font-medium text-gray-700">
              Nákupní cena
            </label>
            <input
              id="p-purchase"
              v-model.number="form.purchase_price"
              type="number"
              min="0"
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

        <div class="mt-6 flex flex-wrap items-center gap-3 border-t border-gray-200 pt-4">
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

        <p v-if="product.images.length === 0" class="py-6 text-gray-600">
          Produkt zatím nemá obrázky.
        </p>

        <ul v-else class="grid gap-4 sm:grid-cols-3 lg:grid-cols-4">
          <li
            v-for="image in product.images"
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
  </AdminLayout>
</template>
