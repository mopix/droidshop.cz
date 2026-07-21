<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'

export type PaymentMethodRow = {
  id: number
  provider: string
  name: string
  description: string | null
  fee: number
  tax_rate_id: number | null
  is_active: boolean
  position: number
  /** Only the masked tail ever reaches the browser — never the full account. */
  account_masked: string | null
  account_set: boolean
}

type TaxRate = { id: number; name: string; percent: number }

const props = defineProps<{
  show: boolean
  /** null = create, otherwise edit that method. */
  method: PaymentMethodRow | null
  taxRates: TaxRate[]
}>()

const emit = defineEmits<{ (e: 'close'): void }>()

const PROVIDER_COD = 'cod'
const PROVIDER_BANK_TRANSFER = 'bank_transfer'

const build = () => ({
  provider: props.method?.provider ?? PROVIDER_COD,
  name: props.method?.name ?? '',
  description: props.method?.description ?? '',
  fee: props.method?.fee ?? 0,
  tax_rate_id: props.method?.tax_rate_id ?? null,
  is_active: props.method?.is_active ?? true,
  // The account is a credential: it is never pre-filled, only entered anew.
  account: '',
})

const form = useForm(build())

// The stored account must never be blanked by saving an untouched form: an
// empty account is dropped from the payload, so the server keeps the one it
// holds. On create with a bank transfer, dropping it lets the required rule
// fire, which is what we want.
form.transform((data) => {
  const out: Record<string, unknown> = { ...data }

  if (typeof out.account !== 'string' || (out.account as string).trim() === '') {
    delete out.account
  }

  return out
})

// Whether the account input is shown. For an existing bank transfer with an
// account already set, it stays hidden behind a "změnit" affordance so the
// admin does not have to retype a secret they cannot see.
const changingAccount = ref(false)

watch(
  () => props.show,
  (show) => {
    if (!show) return

    Object.assign(form, build())
    form.clearErrors()
    changingAccount.value = false
  },
)

const isBankTransfer = computed(() => form.provider === PROVIDER_BANK_TRANSFER)
const isEdit = computed(() => props.method !== null)
const accountAlreadySet = computed(() => props.method?.account_set ?? false)
const showAccountInput = computed(
  () => isBankTransfer.value && (!accountAlreadySet.value || changingAccount.value),
)
const titleId = 'payment-form-title'

const money = (haler: number) =>
  new Intl.NumberFormat('cs-CZ', { style: 'currency', currency: 'CZK' }).format((haler || 0) / 100)

const submit = () => {
  const onSuccess = () => emit('close')

  if (props.method) {
    form.put(route('admin.shipping.payments.update', props.method.id), {
      preserveScroll: true,
      onSuccess,
    })

    return
  }

  form.post(route('admin.shipping.payments.store'), { onSuccess })
}
</script>

<template>
  <Modal :show="show" max-width="2xl" @close="emit('close')">
    <form class="p-6" :aria-labelledby="titleId" @submit.prevent="submit">
      <h2 :id="titleId" class="text-lg font-semibold text-gray-900">
        {{ isEdit ? 'Upravit způsob platby' : 'Nový způsob platby' }}
      </h2>

      <div class="mt-4 grid gap-4 sm:grid-cols-2">
        <div>
          <label for="pay-provider" class="block text-sm font-medium text-gray-700">Typ platby</label>
          <select
            id="pay-provider"
            v-model="form.provider"
            class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
          >
            <option :value="PROVIDER_COD">Dobírka</option>
            <option :value="PROVIDER_BANK_TRANSFER">Bankovní převod (QR)</option>
          </select>
          <p v-if="form.errors.provider" class="mt-1 text-sm text-red-700">{{ form.errors.provider }}</p>
        </div>

        <div class="flex items-center gap-2 sm:mt-6">
          <input
            id="pay-active"
            v-model="form.is_active"
            type="checkbox"
            class="rounded border-gray-300 text-gray-900 focus:ring-gray-900"
          />
          <label for="pay-active" class="text-sm text-gray-700">Aktivní — nabízí se v pokladně</label>
        </div>

        <div class="sm:col-span-2">
          <label for="pay-name" class="block text-sm font-medium text-gray-700">Název</label>
          <input
            id="pay-name"
            v-model="form.name"
            type="text"
            required
            maxlength="191"
            class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
            :aria-invalid="form.errors.name ? 'true' : undefined"
            :aria-describedby="form.errors.name ? 'pay-name-error' : undefined"
          />
          <p v-if="form.errors.name" id="pay-name-error" class="mt-1 text-sm text-red-700">
            {{ form.errors.name }}
          </p>
        </div>

        <div class="sm:col-span-2">
          <label for="pay-description" class="block text-sm font-medium text-gray-700">Popis</label>
          <textarea
            id="pay-description"
            v-model="form.description"
            rows="2"
            maxlength="500"
            class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
            aria-describedby="pay-description-hint"
          />
          <p id="pay-description-hint" class="mt-1 text-sm text-gray-600">
            Zobrazí se u volby platby v pokladně. Nepovinné.
          </p>
        </div>

        <div>
          <label for="pay-fee" class="block text-sm font-medium text-gray-700">
            Příplatek s DPH (v haléřích)
          </label>
          <input
            id="pay-fee"
            v-model.number="form.fee"
            type="number"
            min="0"
            step="1"
            required
            class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
            aria-describedby="pay-fee-hint"
            :aria-invalid="form.errors.fee ? 'true' : undefined"
          />
          <p id="pay-fee-hint" class="mt-1 text-sm text-gray-600">
            {{ money(form.fee) }} · např. příplatek za dobírku. 0 = bez příplatku.
          </p>
          <p v-if="form.errors.fee" class="mt-1 text-sm text-red-700">{{ form.errors.fee }}</p>
        </div>

        <div>
          <label for="pay-rate" class="block text-sm font-medium text-gray-700">Sazba DPH</label>
          <select
            id="pay-rate"
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
      </div>

      <!-- The bank account for the QR payment. Stored encrypted, never handed
           back in the clear; changing it means typing it again. -->
      <fieldset v-show="isBankTransfer" class="mt-6 rounded-md border border-gray-200 p-4">
        <legend class="px-1 text-sm font-medium text-gray-700">Bankovní účet pro QR platbu</legend>

        <div v-if="accountAlreadySet && !changingAccount" class="flex flex-wrap items-center gap-3">
          <p class="text-sm text-gray-700">
            Účet je nastaven: <span class="font-mono">{{ method?.account_masked }}</span>
          </p>
          <button
            type="button"
            class="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
            @click="changingAccount = true"
          >
            Změnit účet
          </button>
        </div>

        <div v-else-if="showAccountInput">
          <label for="pay-account" class="block text-sm font-medium text-gray-700">
            Číslo účtu nebo IBAN
          </label>
          <input
            id="pay-account"
            v-model="form.account"
            type="text"
            maxlength="64"
            autocomplete="off"
            :required="isBankTransfer && !accountAlreadySet"
            class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
            aria-describedby="pay-account-hint"
            :aria-invalid="form.errors.account ? 'true' : undefined"
          />
          <p id="pay-account-hint" class="mt-1 text-sm text-gray-600">
            Např. 123456789/0800 nebo CZ65 0800 0000 1920 0014 5399. Z účtu se generuje QR platba;
            zpět se z bezpečnostních důvodů zobrazí jen poslední čtyři znaky.
          </p>
          <p v-if="form.errors.account" class="mt-1 text-sm text-red-700">{{ form.errors.account }}</p>

          <button
            v-if="accountAlreadySet"
            type="button"
            class="mt-2 text-sm font-medium text-gray-700 underline hover:no-underline focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
            @click="((changingAccount = false), (form.account = ''), form.clearErrors('account'))"
          >
            Ponechat stávající účet
          </button>
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
