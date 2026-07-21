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

// The shop enters money in haléře, exactly as the product card does; the
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
})

const form = useForm(build())

// Settings (address, hours) belong to personal pickup only; a flat carrier
// sends none, and the writer drops any that lingered from a provider switch.
form.transform((data) => ({
  ...data,
  settings: data.provider === PROVIDER_PICKUP ? data.settings : null,
}))

// Reopening the modal for a different row must not carry the last one's values.
watch(
  () => props.show,
  (show) => {
    if (!show) return

    Object.assign(form, build())
    form.clearErrors()
  },
)

const isPickup = computed(() => form.provider === PROVIDER_PICKUP)
const isEdit = computed(() => props.method !== null)
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
            Cena s DPH (v haléřích)
          </label>
          <input
            id="s-price"
            v-model.number="form.price"
            type="number"
            min="0"
            step="1"
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
            Doprava zdarma od (v haléřích)
          </label>
          <input
            id="s-free-from"
            v-model.number="form.free_from"
            type="number"
            min="0"
            step="1"
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
