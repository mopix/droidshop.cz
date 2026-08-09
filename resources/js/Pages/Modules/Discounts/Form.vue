<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import axios from 'axios'
import { Link, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

type DiscountDetail = {
  id: number
  name: string
  code: string | null
  active: boolean
  type: 'percent' | 'fixed' | 'free_shipping'
  value: number
  scope: 'cart' | 'categories' | 'products'
  starts_at: string | null
  ends_at: string | null
  min_cart_total: number | null
  usage_limit: number | null
  usage_limit_per_email: number | null
  used_count: number
  requires_login: boolean
  first_order_only: boolean
  combinable: boolean
  priority: number
  target_ids?: number[]
}

type Option = { id: number; name: string }

const props = defineProps<{
  /** null = create a new discount, otherwise edit this one. */
  discount: DiscountDetail | null
  categories: Option[]
  /**
   * Only the products this discount already targets (empty on create) —
   * never the whole catalogue. New products are found through the
   * search-as-you-type picker below, which asks the server directly instead
   * of receiving every product up front (final review, wave 2.6: an
   * unfiltered product list shipped a multi-megabyte prop for a shop with a
   * large catalogue and an unusable picker).
   */
  selectedProducts: Option[]
}>()

const isEdit = computed(() => props.discount !== null)

const TYPE_PERCENT = 'percent'
const TYPE_FIXED = 'fixed'
const TYPE_FREE_SHIPPING = 'free_shipping'

const SCOPE_CART = 'cart'
const SCOPE_CATEGORIES = 'categories'
const SCOPE_PRODUCTS = 'products'

/**
 * `datetime-local` inputs want "YYYY-MM-DDTHH:mm" in local time, not the ISO
 * 8601 string (with offset) the server sends.
 */
const toDatetimeLocal = (value: string | null): string => {
  if (!value) return ''

  const date = new Date(value)
  const pad = (n: number) => String(n).padStart(2, '0')

  return (
    `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}` +
    `T${pad(date.getHours())}:${pad(date.getMinutes())}`
  )
}

const form = useForm({
  name: props.discount?.name ?? '',
  code: props.discount?.code ?? '',
  type: props.discount?.type ?? TYPE_PERCENT,
  value: props.discount?.value ?? 0,
  scope: props.discount?.scope ?? SCOPE_CART,
  targets: [...(props.discount?.target_ids ?? [])] as number[],
  starts_at: toDatetimeLocal(props.discount?.starts_at ?? null),
  ends_at: toDatetimeLocal(props.discount?.ends_at ?? null),
  min_cart_total: props.discount?.min_cart_total ?? null,
  usage_limit: props.discount?.usage_limit ?? null,
  usage_limit_per_email: props.discount?.usage_limit_per_email ?? null,
  requires_login: props.discount?.requires_login ?? false,
  first_order_only: props.discount?.first_order_only ?? false,
  combinable: props.discount?.combinable ?? true,
  active: props.discount?.active ?? true,
  priority: props.discount?.priority ?? 0,
})

/**
 * `discounts.value` is stored in permille (10 = 1 %), but the admin thinks
 * in percent. This computed is the ONLY place that conversion happens, and
 * it happens client-side before the request is ever built: form.value —
 * what actually gets posted — is always the raw permille number, so
 * StoreDiscountRequest/UpdateDiscountRequest never have to parse a percent
 * on the server, the same "raw stored unit in, raw stored unit out"
 * convention money fields already use (see e.g. Products/Show.vue's `price`,
 * posted as bare haléře).
 */
const percentInput = computed<number>({
  get: () => Math.round(form.value) / 10,
  set: (percent: number) => {
    form.value = Math.round((Number.isFinite(percent) ? percent : 0) * 10)
  },
})

const isPercent = computed(() => form.type === TYPE_PERCENT)
const isFixed = computed(() => form.type === TYPE_FIXED)
const isFreeShipping = computed(() => form.type === TYPE_FREE_SHIPPING)

// A blank code means "automatic rule" server-side (StoreDiscountRequest
// folds an empty string to null) — mirrored here so the combinable field
// disappears the instant the admin starts typing a code, not only after save.
const isCoupon = computed(() => form.code.trim() !== '')

// A value typed under one type's unit is nonsense under another's — e.g. a
// 200 Kč fixed discount left in `form.value` would read as "2000 %" the
// instant the admin switches the select to percent. Reset rather than
// silently reinterpret it (final review, wave 2.6). Vue's watch only fires
// on a genuine change, never on the initial mount value, so loading an
// existing discount for edit does not trip this.
watch(
  () => form.type,
  () => {
    form.value = 0
  },
)

// Switching away from categories/products must not leave stale ids behind.
// The server would reject them anyway (a posted id is `prohibited` once
// scope=cart — see StoreDiscountRequest), but clearing here keeps the
// picker's own state honest rather than relying on a 422 to catch it.
watch(
  () => form.scope,
  (scope) => {
    if (scope !== SCOPE_CATEGORIES && scope !== SCOPE_PRODUCTS) form.targets = []
  },
)

// The engine only ever reads `combinable` on an automatic rule (a coupon's
// own flag is ignored) — offering the control for a coupon would be a
// checkbox with no effect, so it disappears rather than merely disabling.
// Forcing it back to true keeps the payload honest: "not applicable" is not
// the same as "not combinable". StoreDiscountRequest/UpdateDiscountRequest
// enforce the same thing server-side, so a direct POST cannot bypass this.
watch(isCoupon, (coupon) => {
  if (coupon) form.combinable = true
})

const money = (haler: number) =>
  new Intl.NumberFormat('cs-CZ', { style: 'currency', currency: 'CZK' }).format((haler || 0) / 100)

/**
 * Inertia flattens `targets.*` validation errors to dot-notation keys
 * (`targets.0`, `targets.1`, …) that are not part of useForm's static field
 * list, so they are read off the errors object by prefix rather than by a
 * typed property — one line, shown once, instead of trying to line each
 * error up with the checkbox that produced it.
 */
const targetsError = computed(
  () => Object.entries(form.errors).find(([key]) => key.startsWith('targets'))?.[1],
)

/**
 * A client-side convenience only — 8 uppercase letters/digits. Not a
 * security token, and the server never generates a code itself; the admin
 * can freely retype it before saving.
 */
const generateCode = () => {
  form.code = Math.random().toString(36).replace(/[^a-z0-9]/gi, '').slice(0, 8).toUpperCase()
}

// --- Product picker: search-as-you-type instead of the whole catalogue ---

const productQuery = ref('')
const productResults = ref<Option[]>([])
const productsLoading = ref(false)
const productsSearchFailed = ref(false)

// Names for every product currently selected, independent of whatever the
// search box shows right now — a "selected" chip must keep its name even
// after the admin has typed a different search term or cleared it. Seeded
// from selectedProducts (this discount's existing targets); a search only
// ever adds entries, never removes them.
const productNames = reactive<Record<number, string>>(
  Object.fromEntries(props.selectedProducts.map((option) => [option.id, option.name])),
)

// A slower, broader query (e.g. "sleva") fired before a narrower one (e.g.
// "sleva na boty") can resolve AFTER it — the 300 ms debounce only spaces
// requests out, it does nothing about the order responses land in. Without a
// guard, the stale response would overwrite the picker with its broader
// result set while the search box already reads the narrower term, and the
// nájemce could tick a product they never actually searched for. Each call
// stamps a sequence number; only the response matching the latest one is
// allowed to touch the visible state or the loading/failure flags.
let productSearchSequence = 0

const searchProducts = async (term: string) => {
  const sequence = ++productSearchSequence

  productsLoading.value = true
  productsSearchFailed.value = false

  try {
    const { data } = await axios.get<{ data: Option[] }>(route('admin.discounts.products.search'), {
      params: { q: term },
    })

    if (sequence !== productSearchSequence) return

    productResults.value = data.data

    for (const option of data.data) productNames[option.id] = option.name
  } catch {
    if (sequence !== productSearchSequence) return

    productResults.value = []
    productsSearchFailed.value = true
  } finally {
    if (sequence === productSearchSequence) productsLoading.value = false
  }
}

let productSearchTimer: ReturnType<typeof setTimeout> | undefined

watch(productQuery, (term) => {
  clearTimeout(productSearchTimer)
  productSearchTimer = setTimeout(() => searchProducts(term), 300)
})

// The first time the products picker becomes reachable, load an initial page
// of matches (an empty search term) so the admin sees something before
// typing anything.
let productsPrimed = false

watch(
  () => form.scope,
  (scope) => {
    if (scope === SCOPE_PRODUCTS && !productsPrimed) {
      productsPrimed = true
      searchProducts('')
    }
  },
  { immediate: true },
)

const selectedProductChips = computed(() =>
  form.targets.map((id) => ({ id, name: productNames[id] ?? `#${id}` })),
)

const removeProduct = (id: number) => {
  form.targets = form.targets.filter((targetId) => targetId !== id)
}

const productsStatus = computed(() => {
  if (productsLoading.value) return 'Hledání…'
  if (productsSearchFailed.value) return 'Hledání produktů se nezdařilo.'

  return `Nalezeno ${productResults.value.length} produktů.`
})

/**
 * Composes `aria-describedby` from whichever hint/error ids actually apply
 * right now, so a screen reader announces BOTH the field's static hint and
 * its current validation error — not just the hint, which is what a plain
 * always-on `aria-describedby="…-hint"` leaves a screen-reader user with
 * (final review, wave 2.6: most fields on this form had exactly that bug).
 */
const describedBy = (...ids: Array<string | false | null | undefined>) => {
  const list = ids.filter((id): id is string => Boolean(id))

  return list.length > 0 ? list.join(' ') : undefined
}

const usageLine = computed(() => {
  if (!isEdit.value || !props.discount) return null

  return `Čerpáno ${props.discount.used_count} / ${form.usage_limit ?? '∞'}`
})

const submit = () => {
  if (props.discount) {
    form.patch(route('admin.discounts.update', props.discount.id), { preserveScroll: true })

    return
  }

  form.post(route('admin.discounts.store'))
}
</script>

<template>
  <AdminLayout :title="isEdit ? 'Upravit slevu' : 'Nová sleva'">
    <template #header>
      <div>
        <h1 class="text-xl font-semibold text-gray-900">{{ isEdit ? 'Upravit slevu' : 'Nová sleva' }}</h1>
        <p class="mt-1 text-sm text-gray-600">
          <Link
            :href="route('admin.discounts.index')"
            class="underline hover:no-underline focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
          >
            ← Zpět na seznam slev
          </Link>
        </p>
      </div>
    </template>

    <form class="max-w-3xl rounded-lg border border-gray-200 bg-white p-6 shadow-sm" @submit.prevent="submit">
      <div class="grid gap-4 sm:grid-cols-2">
        <div class="sm:col-span-2">
          <label for="d-name" class="block text-sm font-medium text-gray-700">Název</label>
          <input
            id="d-name"
            v-model="form.name"
            type="text"
            required
            maxlength="120"
            class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
            :aria-describedby="describedBy('d-name-hint', form.errors.name && 'd-name-error')"
            :aria-invalid="form.errors.name ? 'true' : undefined"
          />
          <p id="d-name-hint" class="mt-1 text-sm text-gray-600">Interní název, zákazník ho vidí jen u kupónů bez kódu.</p>
          <p v-if="form.errors.name" id="d-name-error" class="mt-1 text-sm text-red-700">{{ form.errors.name }}</p>
        </div>

        <div class="sm:col-span-2">
          <label for="d-code" class="block text-sm font-medium text-gray-700">Kód kupónu</label>
          <div class="mt-1 flex gap-2">
            <input
              id="d-code"
              v-model="form.code"
              type="text"
              maxlength="64"
              autocomplete="off"
              class="w-full rounded-md border-gray-300 uppercase shadow-sm focus:border-gray-900 focus:ring-gray-900"
              :aria-describedby="describedBy('d-code-hint', form.errors.code && 'd-code-error')"
              :aria-invalid="form.errors.code ? 'true' : undefined"
            />
            <button
              type="button"
              class="flex-none rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
              @click="generateCode"
            >
              Vygenerovat
            </button>
          </div>
          <p id="d-code-hint" class="mt-1 text-sm text-gray-600">
            Prázdné = automatické pravidlo (platí samo, zákazník žádný kód nezadává).
          </p>
          <p v-if="form.errors.code" id="d-code-error" class="mt-1 text-sm text-red-700">{{ form.errors.code }}</p>
        </div>

        <div>
          <label for="d-type" class="block text-sm font-medium text-gray-700">Typ slevy</label>
          <select
            id="d-type"
            v-model="form.type"
            class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
            :aria-describedby="form.errors.type ? 'd-type-error' : undefined"
            :aria-invalid="form.errors.type ? 'true' : undefined"
          >
            <option :value="TYPE_PERCENT">Procento</option>
            <option :value="TYPE_FIXED">Pevná částka</option>
            <option :value="TYPE_FREE_SHIPPING">Doprava zdarma</option>
          </select>
          <p v-if="form.errors.type" id="d-type-error" class="mt-1 text-sm text-red-700">{{ form.errors.type }}</p>
        </div>

        <div v-if="isPercent">
          <label for="d-value-percent" class="block text-sm font-medium text-gray-700">Hodnota slevy (%)</label>
          <input
            id="d-value-percent"
            v-model.number="percentInput"
            type="number"
            min="0"
            max="100"
            step="0.1"
            required
            class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
            :aria-invalid="form.errors.value ? 'true' : undefined"
            :aria-describedby="form.errors.value ? 'd-value-error' : undefined"
          />
          <p v-if="form.errors.value" id="d-value-error" class="mt-1 text-sm text-red-700">{{ form.errors.value }}</p>
        </div>

        <div v-else-if="isFixed">
          <label for="d-value-fixed" class="block text-sm font-medium text-gray-700">
            Částka slevy s DPH (Kč)
          </label>
          <input
            id="d-value-fixed"
            v-model="form.value"
            type="text"
            inputmode="decimal"
            required
            class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
            :aria-describedby="describedBy('d-value-fixed-hint', form.errors.value && 'd-value-error')"
            :aria-invalid="form.errors.value ? 'true' : undefined"
          />
          <p id="d-value-fixed-hint" class="mt-1 text-sm text-gray-600">{{ money(form.value) }}</p>
          <p v-if="form.errors.value" id="d-value-error" class="mt-1 text-sm text-red-700">{{ form.errors.value }}</p>
        </div>

        <div v-else-if="isFreeShipping" class="flex items-end">
          <p class="text-sm text-gray-600">Doprava se v pokladně nabídne zdarma, žádná částka se nezadává.</p>
        </div>

        <div>
          <label for="d-scope" class="block text-sm font-medium text-gray-700">Rozsah slevy</label>
          <select
            id="d-scope"
            v-model="form.scope"
            class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
            :aria-describedby="form.errors.scope ? 'd-scope-error' : undefined"
            :aria-invalid="form.errors.scope ? 'true' : undefined"
          >
            <option :value="SCOPE_CART">Celý košík</option>
            <option :value="SCOPE_CATEGORIES">Vybrané kategorie</option>
            <option :value="SCOPE_PRODUCTS">Vybrané produkty</option>
          </select>
          <p v-if="form.errors.scope" id="d-scope-error" class="mt-1 text-sm text-red-700">{{ form.errors.scope }}</p>
        </div>

        <!-- Categories: naturally bounded per shop, so the full list ships
             as a prop and is rendered as a plain checkbox list. -->
        <fieldset
          v-if="form.scope === SCOPE_CATEGORIES"
          class="sm:col-span-2"
          :aria-describedby="describedBy(targetsError && 'd-targets-error')"
        >
          <legend class="text-sm font-medium text-gray-700">Kategorie, na které sleva platí</legend>
          <div class="mt-2 max-h-56 overflow-y-auto rounded-md border border-gray-200 p-3">
            <p v-if="categories.length === 0" class="text-sm text-gray-600">Zatím tu není žádná kategorie.</p>
            <div v-else class="grid gap-1 sm:grid-cols-2">
              <label v-for="option in categories" :key="option.id" class="flex items-center gap-2 text-sm">
                <input
                  v-model="form.targets"
                  type="checkbox"
                  :value="option.id"
                  class="rounded border-gray-300 text-gray-900 focus:ring-gray-900"
                />
                <span>{{ option.name }}</span>
              </label>
            </div>
          </div>
          <p v-if="targetsError" id="d-targets-error" class="mt-1 text-sm text-red-700">{{ targetsError }}</p>
        </fieldset>

        <!-- Products: potentially thousands per shop, so the picker never
             receives the whole catalogue — it searches on demand and keeps a
             separate "selected" chip list so a chosen product's name
             survives after the search box moves on to a different term. -->
        <fieldset
          v-else-if="form.scope === SCOPE_PRODUCTS"
          class="sm:col-span-2"
          :aria-describedby="describedBy(targetsError && 'd-targets-error')"
        >
          <legend class="text-sm font-medium text-gray-700">Produkty, na které sleva platí</legend>

          <div v-if="selectedProductChips.length > 0" class="mt-2 flex flex-wrap gap-2">
            <span
              v-for="chip in selectedProductChips"
              :key="chip.id"
              class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 py-1 pl-3 pr-1.5 text-sm text-gray-800"
            >
              {{ chip.name }}
              <button
                type="button"
                class="rounded-full p-0.5 text-gray-600 hover:bg-gray-200 hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
                :aria-label="`Odebrat ${chip.name} ze slevy`"
                @click="removeProduct(chip.id)"
              >
                <span aria-hidden="true">×</span>
              </button>
            </span>
          </div>

          <label for="d-product-search" class="mt-3 block text-sm font-medium text-gray-700">
            Hledat produkt podle názvu nebo SKU
          </label>
          <input
            id="d-product-search"
            v-model="productQuery"
            type="search"
            class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
            aria-describedby="d-product-search-status"
          />
          <p id="d-product-search-status" role="status" aria-live="polite" class="mt-1 text-sm text-gray-600">
            {{ productsStatus }}
          </p>

          <div class="mt-2 max-h-56 overflow-y-auto rounded-md border border-gray-200 p-3">
            <p v-if="!productsLoading && productResults.length === 0" class="text-sm text-gray-600">
              Žádný produkt neodpovídá hledání.
            </p>
            <div v-else class="grid gap-1 sm:grid-cols-2">
              <label v-for="option in productResults" :key="option.id" class="flex items-center gap-2 text-sm">
                <input
                  v-model="form.targets"
                  type="checkbox"
                  :value="option.id"
                  class="rounded border-gray-300 text-gray-900 focus:ring-gray-900"
                />
                <span>{{ option.name }}</span>
              </label>
            </div>
          </div>
          <p v-if="targetsError" id="d-targets-error" class="mt-1 text-sm text-red-700">{{ targetsError }}</p>
        </fieldset>

        <div>
          <label for="d-starts" class="block text-sm font-medium text-gray-700">Platí od</label>
          <input
            id="d-starts"
            v-model="form.starts_at"
            type="datetime-local"
            class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
            :aria-describedby="describedBy('d-starts-hint', form.errors.starts_at && 'd-starts-error')"
            :aria-invalid="form.errors.starts_at ? 'true' : undefined"
          />
          <p id="d-starts-hint" class="mt-1 text-sm text-gray-600">Prázdné = platí od nynějška.</p>
          <p v-if="form.errors.starts_at" id="d-starts-error" class="mt-1 text-sm text-red-700">
            {{ form.errors.starts_at }}
          </p>
        </div>

        <div>
          <label for="d-ends" class="block text-sm font-medium text-gray-700">Platí do</label>
          <input
            id="d-ends"
            v-model="form.ends_at"
            type="datetime-local"
            class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
            :aria-describedby="describedBy('d-ends-hint', form.errors.ends_at && 'd-ends-error')"
            :aria-invalid="form.errors.ends_at ? 'true' : undefined"
          />
          <p id="d-ends-hint" class="mt-1 text-sm text-gray-600">Prázdné = bez omezení konce platnosti.</p>
          <p v-if="form.errors.ends_at" id="d-ends-error" class="mt-1 text-sm text-red-700">{{ form.errors.ends_at }}</p>
        </div>

        <div>
          <label for="d-min-cart" class="block text-sm font-medium text-gray-700">
            Minimální hodnota košíku (Kč)
          </label>
          <input
            id="d-min-cart"
            v-model="form.min_cart_total"
            type="text"
            inputmode="decimal"
            class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
            :aria-describedby="describedBy('d-min-cart-hint', form.errors.min_cart_total && 'd-min-cart-error')"
            :aria-invalid="form.errors.min_cart_total ? 'true' : undefined"
          />
          <p id="d-min-cart-hint" class="mt-1 text-sm text-gray-600">
            {{ form.min_cart_total ? money(form.min_cart_total) : 'Prázdné = bez minima.' }}
          </p>
          <p v-if="form.errors.min_cart_total" id="d-min-cart-error" class="mt-1 text-sm text-red-700">
            {{ form.errors.min_cart_total }}
          </p>
        </div>

        <div>
          <label for="d-priority" class="block text-sm font-medium text-gray-700">Priorita</label>
          <input
            id="d-priority"
            v-model.number="form.priority"
            type="number"
            min="0"
            max="1000"
            step="1"
            class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
            :aria-describedby="describedBy('d-priority-hint', form.errors.priority && 'd-priority-error')"
            :aria-invalid="form.errors.priority ? 'true' : undefined"
          />
          <p id="d-priority-hint" class="mt-1 text-sm text-gray-600">Vyšší číslo se vyhodnocuje dřív při kolizi pravidel.</p>
          <p v-if="form.errors.priority" id="d-priority-error" class="mt-1 text-sm text-red-700">
            {{ form.errors.priority }}
          </p>
        </div>

        <div>
          <label for="d-usage-limit" class="block text-sm font-medium text-gray-700">Limit použití celkem</label>
          <input
            id="d-usage-limit"
            v-model.number="form.usage_limit"
            type="number"
            min="1"
            step="1"
            class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
            :aria-describedby="
              describedBy('d-usage-limit-hint', usageLine && 'd-usage-limit-used', form.errors.usage_limit && 'd-usage-limit-error')
            "
            :aria-invalid="form.errors.usage_limit ? 'true' : undefined"
          />
          <p id="d-usage-limit-hint" class="mt-1 text-sm text-gray-600">Prázdné = bez omezení.</p>
          <p v-if="usageLine" id="d-usage-limit-used" class="mt-1 text-sm text-gray-600">{{ usageLine }}</p>
          <p v-if="form.errors.usage_limit" id="d-usage-limit-error" class="mt-1 text-sm text-red-700">
            {{ form.errors.usage_limit }}
          </p>
        </div>

        <div>
          <label for="d-usage-limit-email" class="block text-sm font-medium text-gray-700">
            Limit použití na e-mail
          </label>
          <input
            id="d-usage-limit-email"
            v-model.number="form.usage_limit_per_email"
            type="number"
            min="1"
            step="1"
            class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
            :aria-describedby="
              describedBy('d-usage-limit-email-hint', form.errors.usage_limit_per_email && 'd-usage-limit-email-error')
            "
            :aria-invalid="form.errors.usage_limit_per_email ? 'true' : undefined"
          />
          <p id="d-usage-limit-email-hint" class="mt-1 text-sm text-gray-600">Prázdné = bez omezení.</p>
          <p v-if="form.errors.usage_limit_per_email" id="d-usage-limit-email-error" class="mt-1 text-sm text-red-700">
            {{ form.errors.usage_limit_per_email }}
          </p>
        </div>
      </div>

      <fieldset class="mt-6 grid gap-2 sm:grid-cols-2">
        <legend class="text-sm font-medium text-gray-700">Podmínky</legend>

        <label class="flex items-center gap-2 text-sm text-gray-700">
          <input
            v-model="form.active"
            type="checkbox"
            class="rounded border-gray-300 text-gray-900 focus:ring-gray-900"
          />
          Aktivní — sleva se nabízí zákazníkům
        </label>

        <label class="flex items-center gap-2 text-sm text-gray-700">
          <input
            v-model="form.requires_login"
            type="checkbox"
            class="rounded border-gray-300 text-gray-900 focus:ring-gray-900"
          />
          Jen pro přihlášené zákazníky
        </label>

        <label class="flex items-center gap-2 text-sm text-gray-700">
          <input
            v-model="form.first_order_only"
            type="checkbox"
            class="rounded border-gray-300 text-gray-900 focus:ring-gray-900"
          />
          Jen na první objednávku zákazníka
        </label>

        <!-- Only an automatic rule's own combinable flag is ever read by the
             evaluator — a coupon's flag is ignored, so the control is hidden
             rather than shown-but-inert once a code is typed.
             StoreDiscountRequest/UpdateDiscountRequest force it back to true
             server-side too, so this is UX, not the actual enforcement. -->
        <label v-if="!isCoupon" class="flex items-center gap-2 text-sm text-gray-700">
          <input
            v-model="form.combinable"
            type="checkbox"
            class="rounded border-gray-300 text-gray-900 focus:ring-gray-900"
          />
          Kombinovatelná s dalšími slevami
        </label>
      </fieldset>

      <div class="mt-6 flex justify-end gap-3">
        <Link
          :href="route('admin.discounts.index')"
          class="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
        >
          Zrušit
        </Link>
        <button
          type="submit"
          :disabled="form.processing"
          class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:bg-gray-400 disabled:text-gray-700"
        >
          {{ isEdit ? 'Uložit' : 'Vytvořit' }}
        </button>
      </div>
    </form>
  </AdminLayout>
</template>
