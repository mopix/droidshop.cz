<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'

export type ShippingMethodRow = {
  id: number
  provider: string
  name: string
  description: string | null
  price: number
  free_from: number | null
  max_weight_g: number | null
  tax_rate_id: number | null
  is_active: boolean
  position: number
  settings: {
    street?: string | null
    city?: string | null
    zip?: string | null
    opening_hours?: string | null
  } | null
  /** Zásilkovna (Packeta). api_key and eshop are not secret and are shown. */
  packeta_api_key?: string | null
  packeta_eshop?: string | null
  packeta_default_weight_g?: number | null
  /** The Packeta API password is a credential: only its presence is exposed. */
  has_api_password?: boolean
}

type TaxRate = { id: number; name: string; percent: number }

const props = defineProps<{
  show: boolean
  /** null = create, otherwise edit that method. */
  method: ShippingMethodRow | null
  taxRates: TaxRate[]
}>()

const emit = defineEmits<{ (e: 'close'): void }>()

const PROVIDER_PICKUP = 'pickup'
const PROVIDER_FLAT = 'flat'
const PROVIDER_PACKETA = 'packeta'

// The shop enters money in korunas, exactly as the product card does (wave 3.8); the
// integer travels to the server untouched and never becomes a float.
const build = () => ({
  provider: props.method?.provider ?? PROVIDER_FLAT,
  name: props.method?.name ?? '',
  description: props.method?.description ?? '',
  price: props.method?.price ?? 0,
  tax_rate_id: props.method?.tax_rate_id ?? null,
  free_from: props.method?.free_from ?? null,
  max_weight_g: props.method?.max_weight_g ?? null,
  is_active: props.method?.is_active ?? true,
  settings: {
    street: props.method?.settings?.street ?? '',
    city: props.method?.settings?.city ?? '',
    zip: props.method?.settings?.zip ?? '',
    opening_hours: props.method?.settings?.opening_hours ?? '',
  },
  // Zásilkovna. api_key and eshop are not secret and are pre-filled;
  // api_password is a credential, handled like the Comgate secret (empty =
  // keep the stored one).
  api_key: props.method?.packeta_api_key ?? '',
  eshop: props.method?.packeta_eshop ?? '',
  default_weight_g: props.method?.packeta_default_weight_g ?? null,
  api_password: '',
})

const form = useForm(build())

// Settings (address, hours) belong to personal pickup only; a flat carrier
// sends none, and the writer drops any that lingered from a provider switch.
form.transform((data) => {
  const out: Record<string, unknown> = {
    ...data,
    settings: data.provider === PROVIDER_PICKUP ? data.settings : null,
  }

  // Packeta credentials belong to that provider only. ShippingMethod has no
  // $fillable guard, so stray api_key/eshop/default_weight_g reaching the
  // writer for a flat/pickup method would hit an "Unknown column" SQL error —
  // these are not table columns, only settings the writer folds for packeta.
  if (data.provider !== PROVIDER_PACKETA) {
    delete out.api_key
    delete out.eshop
    delete out.default_weight_g
    delete out.api_password
  } else if (typeof out.api_password !== 'string' || (out.api_password as string).trim() === '') {
    // The stored Packeta password must never be blanked by saving an
    // untouched form: an empty value is dropped from the payload, so the
    // server keeps the one it holds. On create, dropping it lets the
    // required rule fire.
    delete out.api_password
  }

  return out
})

// Whether the password input is shown. For an existing Packeta method with a
// password already set, it stays hidden behind a "změnit" affordance so the
// admin does not have to retype a secret they cannot see.
const changingPassword = ref(false)

// Reopening the modal for a different row must not carry the last one's values.
watch(
  () => props.show,
  (show) => {
    if (!show) return

    Object.assign(form, build())
    form.clearErrors()
    changingPassword.value = false
  },
)

const isPickup = computed(() => form.provider === PROVIDER_PICKUP)
const isPacketa = computed(() => form.provider === PROVIDER_PACKETA)
const isEdit = computed(() => props.method !== null)
const passwordAlreadySet = computed(() => props.method?.has_api_password ?? false)
const showPasswordInput = computed(
  () => isPacketa.value && (!passwordAlreadySet.value || changingPassword.value),
)
const titleId = 'shipping-form-title'

const money = (haler: number) =>
  new Intl.NumberFormat('cs-CZ', { style: 'currency', currency: 'CZK' }).format((haler || 0) / 100)

const submit = () => {
  const onSuccess = () => emit('close')

  if (props.method) {
    form.put(route('admin.shipping.methods.update', props.method.id), {
      preserveScroll: true,
      onSuccess,
    })

    return
  }

  form.post(route('admin.shipping.methods.store'), { onSuccess })
}
</script>

<template>
  <Modal :show="show" max-width="2xl" @close="emit('close')">
    <form class="p-6" :aria-labelledby="titleId" @submit.prevent="submit">
      <h2 :id="titleId" class="text-lg font-semibold text-gray-900">
        {{ isEdit ? 'Upravit způsob dopravy' : 'Nový způsob dopravy' }}
      </h2>

      <div class="mt-4 grid gap-4 sm:grid-cols-2">
        <div>
          <label for="s-provider" class="block text-sm font-medium text-gray-700">Typ dopravy</label>
          <select
            id="s-provider"
            v-model="form.provider"
            class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
          >
            <option :value="PROVIDER_FLAT">Dopravce (pevná cena)</option>
            <option :value="PROVIDER_PICKUP">Osobní odběr</option>
            <option :value="PROVIDER_PACKETA">Zásilkovna</option>
          </select>
          <p v-if="form.errors.provider" class="mt-1 text-sm text-red-700">{{ form.errors.provider }}</p>
        </div>

        <div class="flex items-center gap-2 sm:mt-6">
          <input
            id="s-active"
            v-model="form.is_active"
            type="checkbox"
            class="rounded border-gray-300 text-gray-900 focus:ring-gray-900"
          />
          <label for="s-active" class="text-sm text-gray-700">Aktivní — nabízí se v pokladně</label>
        </div>

        <div class="sm:col-span-2">
          <label for="s-name" class="block text-sm font-medium text-gray-700">Název</label>
          <input
            id="s-name"
            v-model="form.name"
            type="text"
            required
            maxlength="191"
            class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
            :aria-invalid="form.errors.name ? 'true' : undefined"
            :aria-describedby="form.errors.name ? 's-name-error' : undefined"
          />
          <p v-if="form.errors.name" id="s-name-error" class="mt-1 text-sm text-red-700">
            {{ form.errors.name }}
          </p>
        </div>

        <div class="sm:col-span-2">
          <label for="s-description" class="block text-sm font-medium text-gray-700">Popis</label>
          <textarea
            id="s-description"
            v-model="form.description"
            rows="2"
            maxlength="500"
            class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
            aria-describedby="s-description-hint"
          />
          <p id="s-description-hint" class="mt-1 text-sm text-gray-600">
            Zobrazí se u volby dopravy v pokladně. Nepovinné.
          </p>
        </div>

        <div>
          <label for="s-price" class="block text-sm font-medium text-gray-700">
            Cena s DPH (Kč)
          </label>
          <input
            id="s-price"
            v-model="form.price"
            type="text"
            inputmode="decimal"
            required
            class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
            aria-describedby="s-price-hint"
            :aria-invalid="form.errors.price ? 'true' : undefined"
          />
          <p id="s-price-hint" class="mt-1 text-sm text-gray-600">{{ money(form.price) }}</p>
          <p v-if="form.errors.price" class="mt-1 text-sm text-red-700">{{ form.errors.price }}</p>
        </div>

        <div>
          <label for="s-rate" class="block text-sm font-medium text-gray-700">Sazba DPH</label>
          <select
            id="s-rate"
            v-model.number="form.tax_rate_id"
            class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
          >
            <option :value="null">— nepřiřazeno —</option>
            <option v-for="option in taxRates" :key="option.id" :value="option.id">
              {{ option.name }}
            </option>
          </select>
          <p v-if="form.errors.tax_rate_id" class="mt-1 text-sm text-red-700">
            {{ form.errors.tax_rate_id }}
          </p>
        </div>

        <div>
          <label for="s-free-from" class="block text-sm font-medium text-gray-700">
            Doprava zdarma od (Kč)
          </label>
          <input
            id="s-free-from"
            v-model="form.free_from"
            type="text"
            inputmode="decimal"
            class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
            aria-describedby="s-free-from-hint"
          />
          <p id="s-free-from-hint" class="mt-1 text-sm text-gray-600">
            Prázdné = doprava zdarma se neuplatní.
          </p>
          <p v-if="form.errors.free_from" class="mt-1 text-sm text-red-700">{{ form.errors.free_from }}</p>
        </div>

        <div>
          <label for="s-max-weight" class="block text-sm font-medium text-gray-700">
            Maximální hmotnost (g)
          </label>
          <input
            id="s-max-weight"
            v-model.number="form.max_weight_g"
            type="number"
            min="0"
            step="1"
            class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
            aria-describedby="s-max-weight-hint"
          />
          <p id="s-max-weight-hint" class="mt-1 text-sm text-gray-600">
            Nad tuto hmotnost se doprava v pokladně nenabídne. Prázdné = bez omezení.
          </p>
          <p v-if="form.errors.max_weight_g" class="mt-1 text-sm text-red-700">
            {{ form.errors.max_weight_g }}
          </p>
        </div>
      </div>

      <!-- Pickup carries an address and opening hours printed on the storefront. -->
      <fieldset v-show="isPickup" class="mt-6 rounded-md border border-gray-200 p-4">
        <legend class="px-1 text-sm font-medium text-gray-700">Výdejní místo</legend>

        <div class="grid gap-4 sm:grid-cols-2">
          <div class="sm:col-span-2">
            <label for="s-street" class="block text-sm font-medium text-gray-700">Ulice a číslo</label>
            <input
              id="s-street"
              v-model="form.settings.street"
              type="text"
              maxlength="191"
              :required="isPickup"
              class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
              :aria-invalid="form.errors['settings.street'] ? 'true' : undefined"
              :aria-describedby="form.errors['settings.street'] ? 's-street-error' : undefined"
            />
            <p v-if="form.errors['settings.street']" id="s-street-error" class="mt-1 text-sm text-red-700">
              {{ form.errors['settings.street'] }}
            </p>
          </div>

          <div>
            <label for="s-city" class="block text-sm font-medium text-gray-700">Město</label>
            <input
              id="s-city"
              v-model="form.settings.city"
              type="text"
              maxlength="191"
              :required="isPickup"
              class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
              :aria-invalid="form.errors['settings.city'] ? 'true' : undefined"
              :aria-describedby="form.errors['settings.city'] ? 's-city-error' : undefined"
            />
            <p v-if="form.errors['settings.city']" id="s-city-error" class="mt-1 text-sm text-red-700">
              {{ form.errors['settings.city'] }}
            </p>
          </div>

          <div>
            <label for="s-zip" class="block text-sm font-medium text-gray-700">PSČ</label>
            <input
              id="s-zip"
              v-model="form.settings.zip"
              type="text"
              maxlength="20"
              :required="isPickup"
              class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
              :aria-invalid="form.errors['settings.zip'] ? 'true' : undefined"
              :aria-describedby="form.errors['settings.zip'] ? 's-zip-error' : undefined"
            />
            <p v-if="form.errors['settings.zip']" id="s-zip-error" class="mt-1 text-sm text-red-700">
              {{ form.errors['settings.zip'] }}
            </p>
          </div>

          <div class="sm:col-span-2">
            <label for="s-hours" class="block text-sm font-medium text-gray-700">Otevírací doba</label>
            <textarea
              id="s-hours"
              v-model="form.settings.opening_hours"
              rows="3"
              maxlength="2000"
              class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
              aria-describedby="s-hours-hint"
            />
            <p id="s-hours-hint" class="mt-1 text-sm text-gray-600">
              Např. Po–Pá 9–17, So 9–12. Zobrazí se zákazníkovi u výdejního místa.
            </p>
            <p v-if="form.errors['settings.opening_hours']" class="mt-1 text-sm text-red-700">
              {{ form.errors['settings.opening_hours'] }}
            </p>
          </div>
        </div>
      </fieldset>

      <!-- Zásilkovna (Packeta). api_key and eshop are not secret and are
           shown; api_password is a credential, stored encrypted and never
           handed back — changing it means typing it again. -->
      <fieldset v-show="isPacketa" class="mt-6 rounded-md border border-gray-200 p-4">
        <legend class="px-1 text-sm font-medium text-gray-700">Nastavení Zásilkovny</legend>

        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <label for="s-packeta-api-key" class="block text-sm font-medium text-gray-700">API klíč</label>
            <input
              id="s-packeta-api-key"
              v-model="form.api_key"
              type="text"
              maxlength="64"
              autocomplete="off"
              :required="isPacketa"
              class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
              aria-describedby="s-packeta-api-key-hint"
              :aria-invalid="form.errors.api_key ? 'true' : undefined"
            />
            <p id="s-packeta-api-key-hint" class="mt-1 text-sm text-gray-600">
              Najdete v klientské sekci Zásilkovny. Není tajné.
            </p>
            <p v-if="form.errors.api_key" class="mt-1 text-sm text-red-700">{{ form.errors.api_key }}</p>
          </div>

          <div>
            <label for="s-packeta-eshop" class="block text-sm font-medium text-gray-700">Označení e-shopu</label>
            <input
              id="s-packeta-eshop"
              v-model="form.eshop"
              type="text"
              maxlength="64"
              autocomplete="off"
              :required="isPacketa"
              class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
              aria-describedby="s-packeta-eshop-hint"
              :aria-invalid="form.errors.eshop ? 'true' : undefined"
            />
            <p id="s-packeta-eshop-hint" class="mt-1 text-sm text-gray-600">
              Označení odesílatele (eshop) z klientské sekce Zásilkovny — Nastavení e-shopu → API. Není tajné.
            </p>
            <p v-if="form.errors.eshop" class="mt-1 text-sm text-red-700">{{ form.errors.eshop }}</p>
          </div>

          <div>
            <label for="s-packeta-weight" class="block text-sm font-medium text-gray-700">
              Výchozí hmotnost zásilky (g)
            </label>
            <input
              id="s-packeta-weight"
              v-model.number="form.default_weight_g"
              type="number"
              min="1"
              max="30000"
              step="1"
              class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
              aria-describedby="s-packeta-weight-hint"
              :aria-invalid="form.errors.default_weight_g ? 'true' : undefined"
            />
            <p id="s-packeta-weight-hint" class="mt-1 text-sm text-gray-600">
              Použije se, pokud produkt hmotnost neuvádí. Prázdné = výchozí hodnota 1000 g.
            </p>
            <p v-if="form.errors.default_weight_g" class="mt-1 text-sm text-red-700">
              {{ form.errors.default_weight_g }}
            </p>
          </div>

          <div>
            <div v-if="passwordAlreadySet && !changingPassword" class="flex flex-wrap items-center gap-3">
              <p class="text-sm text-gray-700">Heslo API je uloženo.</p>
              <button
                type="button"
                class="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
                @click="changingPassword = true"
              >
                Změnit heslo
              </button>
            </div>

            <div v-else-if="showPasswordInput">
              <label for="s-packeta-password" class="block text-sm font-medium text-gray-700">Heslo API</label>
              <input
                id="s-packeta-password"
                v-model="form.api_password"
                type="password"
                maxlength="255"
                autocomplete="off"
                :required="isPacketa && !passwordAlreadySet"
                class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
                aria-describedby="s-packeta-password-hint"
                :aria-invalid="form.errors.api_password ? 'true' : undefined"
              />
              <p id="s-packeta-password-hint" class="mt-1 text-sm text-gray-600">
                <template v-if="passwordAlreadySet">Ponechte prázdné = beze změny.</template>
                <template v-else>
                  Heslo pro API Zásilkovny z klientské sekce. Ukládá se šifrovaně a zpět se nezobrazí.
                </template>
              </p>
              <p v-if="form.errors.api_password" class="mt-1 text-sm text-red-700">
                {{ form.errors.api_password }}
              </p>

              <button
                v-if="passwordAlreadySet"
                type="button"
                class="mt-2 text-sm font-medium text-gray-700 underline hover:no-underline focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
                @click="((changingPassword = false), (form.api_password = ''), form.clearErrors('api_password'))"
              >
                Ponechat stávající heslo
              </button>
            </div>
          </div>
        </div>
      </fieldset>

      <div class="mt-6 flex justify-end gap-3">
        <button
          type="button"
          class="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
          @click="emit('close')"
        >
          Zrušit
        </button>
        <button
          type="submit"
          :disabled="form.processing"
          class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:bg-gray-400 disabled:text-gray-700"
        >
          {{ isEdit ? 'Uložit' : 'Vytvořit' }}
        </button>
      </div>
    </form>
  </Modal>
</template>
