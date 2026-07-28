<script setup lang="ts">
import { computed, watch } from 'vue'
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
  products: Option[]
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

const showTargets = computed(() => form.scope === SCOPE_CATEGORIES || form.scope === SCOPE_PRODUCTS)

const targetOptions = computed<Option[]>(() => {
  if (form.scope === SCOPE_CATEGORIES) return props.categories
  if (form.scope === SCOPE_PRODUCTS) return props.products

  return []
})

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
// the same as "not combinable".
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
            aria-describedby="d-name-hint"
            :aria-invalid="form.errors.name ? 'true' : undefined"
          />
          <p id="d-name-hint" class="mt-1 text-sm text-gray-600">Interní název, zákazník ho vidí jen u kupónů bez kódu.</p>
          <p v-if="form.errors.name" class="mt-1 text-sm text-red-700">{{ form.errors.name }}</p>
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
              aria-describedby="d-code-hint"
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
          <p v-if="form.errors.code" class="mt-1 text-sm text-red-700">{{ form.errors.code }}</p>
        </div>

        <div>
          <label for="d-type" class="block text-sm font-medium text-gray-700">Typ slevy</label>
          <select
            id="d-type"
            v-model="form.type"
            class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
          >
            <option :value="TYPE_PERCENT">Procento</option>
            <option :value="TYPE_FIXED">Pevná částka</option>
            <option :value="TYPE_FREE_SHIPPING">Doprava zdarma</option>
          </select>
          <p v-if="form.errors.type" class="mt-1 text-sm text-red-700">{{ form.errors.type }}</p>
        </div>

        <div v-if="isPercent">
          <label for="d-value-percent" class="block text-sm font-medium text-gray-700">Hodnota slevy (%)</label>
          <input
            id="d-value-percent"
            v-model.number="percentInput"
            type="number"
            min="0"
            max="100000"
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
            Částka slevy s DPH (v haléřích)
          </label>
          <input
            id="d-value-fixed"
            v-model.number="form.value"
            type="number"
            min="0"
            step="1"
            required
            class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
            aria-describedby="d-value-fixed-hint"
            :aria-invalid="form.errors.value ? 'true' : undefined"
          />
          <p id="d-value-fixed-hint" class="mt-1 text-sm text-gray-600">{{ money(form.value) }}</p>
          <p v-if="form.errors.value" class="mt-1 text-sm text-red-700">{{ form.errors.value }}</p>
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
          >
            <option :value="SCOPE_CART">Celý košík</option>
            <option :value="SCOPE_CATEGORIES">Vybrané kategorie</option>
            <option :value="SCOPE_PRODUCTS">Vybrané produkty</option>
          </select>
          <p v-if="form.errors.scope" class="mt-1 text-sm text-red-700">{{ form.errors.scope }}</p>
        </div>

        <fieldset v-if="showTargets" class="sm:col-span-2">
          <legend class="text-sm font-medium text-gray-700">
            {{ form.scope === SCOPE_CATEGORIES ? 'Kategorie, na které sleva platí' : 'Produkty, na které sleva platí' }}
          </legend>
          <div class="mt-2 max-h-56 overflow-y-auto rounded-md border border-gray-200 p-3">
            <p v-if="targetOptions.length === 0" class="text-sm text-gray-600">
              {{ form.scope === SCOPE_CATEGORIES ? 'Zatím tu není žádná kategorie.' : 'Zatím tu není žádný produkt.' }}
            </p>
            <div v-else class="grid gap-1 sm:grid-cols-2">
              <label v-for="option in targetOptions" :key="option.id" class="flex items-center gap-2 text-sm">
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
          <p v-if="targetsError" class="mt-1 text-sm text-red-700">{{ targetsError }}</p>
        </fieldset>

        <div>
          <label for="d-starts" class="block text-sm font-medium text-gray-700">Platí od</label>
          <input
            id="d-starts"
            v-model="form.starts_at"
            type="datetime-local"
            class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
            aria-describedby="d-starts-hint"
          />
          <p id="d-starts-hint" class="mt-1 text-sm text-gray-600">Prázdné = platí od nynějška.</p>
          <p v-if="form.errors.starts_at" class="mt-1 text-sm text-red-700">{{ form.errors.starts_at }}</p>
        </div>

        <div>
          <label for="d-ends" class="block text-sm font-medium text-gray-700">Platí do</label>
          <input
            id="d-ends"
            v-model="form.ends_at"
            type="datetime-local"
            class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
            aria-describedby="d-ends-hint"
            :aria-invalid="form.errors.ends_at ? 'true' : undefined"
          />
          <p id="d-ends-hint" class="mt-1 text-sm text-gray-600">Prázdné = bez omezení konce platnosti.</p>
          <p v-if="form.errors.ends_at" class="mt-1 text-sm text-red-700">{{ form.errors.ends_at }}</p>
        </div>

        <div>
          <label for="d-min-cart" class="block text-sm font-medium text-gray-700">
            Minimální hodnota košíku (v haléřích)
          </label>
          <input
            id="d-min-cart"
            v-model.number="form.min_cart_total"
            type="number"
            min="0"
            step="1"
            class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
            aria-describedby="d-min-cart-hint"
          />
          <p id="d-min-cart-hint" class="mt-1 text-sm text-gray-600">
            {{ form.min_cart_total ? money(form.min_cart_total) : 'Prázdné = bez minima.' }}
          </p>
          <p v-if="form.errors.min_cart_total" class="mt-1 text-sm text-red-700">{{ form.errors.min_cart_total }}</p>
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
            aria-describedby="d-priority-hint"
          />
          <p id="d-priority-hint" class="mt-1 text-sm text-gray-600">Vyšší číslo se vyhodnocuje dřív při kolizi pravidel.</p>
          <p v-if="form.errors.priority" class="mt-1 text-sm text-red-700">{{ form.errors.priority }}</p>
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
            aria-describedby="d-usage-limit-hint"
          />
          <p id="d-usage-limit-hint" class="mt-1 text-sm text-gray-600">Prázdné = bez omezení.</p>
          <p v-if="form.errors.usage_limit" class="mt-1 text-sm text-red-700">{{ form.errors.usage_limit }}</p>
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
            aria-describedby="d-usage-limit-email-hint"
          />
          <p id="d-usage-limit-email-hint" class="mt-1 text-sm text-gray-600">Prázdné = bez omezení.</p>
          <p v-if="form.errors.usage_limit_per_email" class="mt-1 text-sm text-red-700">
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
             rather than shown-but-inert once a code is typed. -->
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
