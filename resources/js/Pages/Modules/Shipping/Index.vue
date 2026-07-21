<script setup lang="ts">
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import ConfirmDialog from '@/Components/Ui/ConfirmDialog.vue'
import ShippingMethodForm, { type ShippingMethodRow } from './ShippingMethod.vue'
import PaymentMethodForm, { type PaymentMethodRow } from './PaymentMethod.vue'

type TaxRate = { id: number; name: string; percent: number }

const props = defineProps<{
  shippingMethods: ShippingMethodRow[]
  paymentMethods: PaymentMethodRow[]
  taxRates: TaxRate[]
}>()

const SHIPPING_PROVIDERS: Record<string, string> = {
  pickup: 'Osobní odběr',
  flat: 'Dopravce (pevná cena)',
}

const PAYMENT_PROVIDERS: Record<string, string> = {
  cod: 'Dobírka',
  bank_transfer: 'Bankovní převod',
}

const money = (haler: number | null) =>
  haler === null
    ? '—'
    : new Intl.NumberFormat('cs-CZ', { style: 'currency', currency: 'CZK' }).format(haler / 100)

// --- Shipping form modal -------------------------------------------------
const shippingFormOpen = ref(false)
const shippingEditing = ref<ShippingMethodRow | null>(null)

const openShippingCreate = () => {
  shippingEditing.value = null
  shippingFormOpen.value = true
}

const openShippingEdit = (row: ShippingMethodRow) => {
  shippingEditing.value = row
  shippingFormOpen.value = true
}

// --- Payment form modal --------------------------------------------------
const paymentFormOpen = ref(false)
const paymentEditing = ref<PaymentMethodRow | null>(null)

const openPaymentCreate = () => {
  paymentEditing.value = null
  paymentFormOpen.value = true
}

const openPaymentEdit = (row: PaymentMethodRow) => {
  paymentEditing.value = row
  paymentFormOpen.value = true
}

// --- Reorder (keyboard-operable up/down, never drag-only; WCAG 2.1.1) ----
// The reorder endpoint wants the full ordered list of ids, the way the
// category tree does it: a partial list would drop the missing rows to the top.
const shiftShipping = (index: number, direction: -1 | 1) => {
  const ids = props.shippingMethods.map((m) => m.id)
  const target = index + direction

  if (target < 0 || target >= ids.length) return

  ids.splice(target, 0, ids.splice(index, 1)[0])

  router.put(route('admin.shipping.methods.reorder'), { ids }, { preserveScroll: true })
}

const shiftPayment = (index: number, direction: -1 | 1) => {
  const ids = props.paymentMethods.map((m) => m.id)
  const target = index + direction

  if (target < 0 || target >= ids.length) return

  ids.splice(target, 0, ids.splice(index, 1)[0])

  router.put(route('admin.shipping.payments.reorder'), { ids }, { preserveScroll: true })
}

// --- Delete --------------------------------------------------------------
const deletingShipping = ref<ShippingMethodRow | null>(null)
const deletingPayment = ref<PaymentMethodRow | null>(null)

const confirmDeleteShipping = () => {
  const row = deletingShipping.value

  if (!row) return

  router.delete(route('admin.shipping.methods.destroy', row.id), {
    preserveScroll: true,
    onFinish: () => (deletingShipping.value = null),
  })
}

const confirmDeletePayment = () => {
  const row = deletingPayment.value

  if (!row) return

  router.delete(route('admin.shipping.payments.destroy', row.id), {
    preserveScroll: true,
    onFinish: () => (deletingPayment.value = null),
  })
}
</script>

<template>
  <AdminLayout title="Doprava a platba">
    <template #header>
      <h1 class="text-xl font-semibold text-gray-900">Doprava a platba</h1>
      <p class="mt-1 text-sm text-gray-600">
        Způsoby dopravy a platby vašeho e-shopu. Které platby jdou s kterou dopravou, nastavíte v
        <Link
          :href="route('admin.shipping.matrix')"
          class="underline hover:no-underline focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
        >
          matici dopravy a plateb</Link>.
      </p>
    </template>

    <!-- ===================== Shipping methods ===================== -->
    <section aria-labelledby="shipping-heading" class="mb-8">
      <div class="mb-3 flex items-center justify-between">
        <h2 id="shipping-heading" class="text-lg font-semibold text-gray-900">Způsoby dopravy</h2>
        <button
          type="button"
          class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
          @click="openShippingCreate"
        >
          Přidat dopravu
        </button>
      </div>

      <div class="rounded-lg border border-gray-200 bg-white shadow-sm">
        <p v-if="shippingMethods.length === 0" class="px-4 py-8 text-center text-gray-600">
          Zatím tu není žádný způsob dopravy.
        </p>

        <ul v-else class="divide-y divide-gray-200">
          <li
            v-for="(method, index) in shippingMethods"
            :key="method.id"
            class="flex flex-wrap items-center gap-3 px-4 py-3"
          >
            <div class="flex flex-none flex-col">
              <button
                type="button"
                class="rounded p-1 text-gray-600 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 disabled:cursor-not-allowed disabled:text-gray-300"
                :disabled="index === 0"
                :aria-label="`Posunout ${method.name} nahoru`"
                @click="shiftShipping(index, -1)"
              >
                <span aria-hidden="true">▲</span>
              </button>
              <button
                type="button"
                class="rounded p-1 text-gray-600 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 disabled:cursor-not-allowed disabled:text-gray-300"
                :disabled="index === shippingMethods.length - 1"
                :aria-label="`Posunout ${method.name} dolů`"
                @click="shiftShipping(index, 1)"
              >
                <span aria-hidden="true">▼</span>
              </button>
            </div>

            <div class="min-w-0 flex-1">
              <p class="font-medium text-gray-900">
                {{ method.name }}
                <span
                  v-if="!method.is_active"
                  class="ml-2 rounded bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700"
                >
                  Neaktivní
                </span>
              </p>
              <p class="text-sm text-gray-600">
                {{ SHIPPING_PROVIDERS[method.provider] ?? method.provider }} · {{ money(method.price) }}
                <span v-if="method.free_from !== null"> · zdarma od {{ money(method.free_from) }}</span>
              </p>
            </div>

            <div class="flex flex-none gap-2">
              <button
                type="button"
                class="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-800 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
                @click="openShippingEdit(method)"
              >
                Upravit
              </button>
              <button
                type="button"
                class="rounded-md px-3 py-1.5 text-sm font-medium text-red-800 hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-800"
                @click="deletingShipping = method"
              >
                Smazat
              </button>
            </div>
          </li>
        </ul>
      </div>
    </section>

    <!-- ===================== Payment methods ===================== -->
    <section aria-labelledby="payment-heading">
      <div class="mb-3 flex items-center justify-between">
        <h2 id="payment-heading" class="text-lg font-semibold text-gray-900">Způsoby platby</h2>
        <button
          type="button"
          class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
          @click="openPaymentCreate"
        >
          Přidat platbu
        </button>
      </div>

      <div class="rounded-lg border border-gray-200 bg-white shadow-sm">
        <p v-if="paymentMethods.length === 0" class="px-4 py-8 text-center text-gray-600">
          Zatím tu není žádný způsob platby.
        </p>

        <ul v-else class="divide-y divide-gray-200">
          <li
            v-for="(method, index) in paymentMethods"
            :key="method.id"
            class="flex flex-wrap items-center gap-3 px-4 py-3"
          >
            <div class="flex flex-none flex-col">
              <button
                type="button"
                class="rounded p-1 text-gray-600 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 disabled:cursor-not-allowed disabled:text-gray-300"
                :disabled="index === 0"
                :aria-label="`Posunout ${method.name} nahoru`"
                @click="shiftPayment(index, -1)"
              >
                <span aria-hidden="true">▲</span>
              </button>
              <button
                type="button"
                class="rounded p-1 text-gray-600 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 disabled:cursor-not-allowed disabled:text-gray-300"
                :disabled="index === paymentMethods.length - 1"
                :aria-label="`Posunout ${method.name} dolů`"
                @click="shiftPayment(index, 1)"
              >
                <span aria-hidden="true">▼</span>
              </button>
            </div>

            <div class="min-w-0 flex-1">
              <p class="font-medium text-gray-900">
                {{ method.name }}
                <span
                  v-if="!method.is_active"
                  class="ml-2 rounded bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700"
                >
                  Neaktivní
                </span>
              </p>
              <p class="text-sm text-gray-600">
                {{ PAYMENT_PROVIDERS[method.provider] ?? method.provider }} · příplatek
                {{ money(method.fee) }}
                <span v-if="method.provider === 'bank_transfer' && method.account_set">
                  · účet {{ method.account_masked }}
                </span>
              </p>
            </div>

            <div class="flex flex-none gap-2">
              <button
                type="button"
                class="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-800 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
                @click="openPaymentEdit(method)"
              >
                Upravit
              </button>
              <button
                type="button"
                class="rounded-md px-3 py-1.5 text-sm font-medium text-red-800 hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-800"
                @click="deletingPayment = method"
              >
                Smazat
              </button>
            </div>
          </li>
        </ul>
      </div>
    </section>

    <!-- Create/edit forms -->
    <ShippingMethodForm
      :show="shippingFormOpen"
      :method="shippingEditing"
      :tax-rates="taxRates"
      @close="shippingFormOpen = false"
    />
    <PaymentMethodForm
      :show="paymentFormOpen"
      :method="paymentEditing"
      :tax-rates="taxRates"
      @close="paymentFormOpen = false"
    />

    <!-- Delete confirmations -->
    <ConfirmDialog
      :show="deletingShipping !== null"
      title="Smazat způsob dopravy"
      :message="`Opravdu smazat dopravu ${deletingShipping?.name ?? ''}? Akci nelze vzít zpět.`"
      confirm-label="Smazat"
      danger
      @cancel="deletingShipping = null"
      @confirm="confirmDeleteShipping"
    />
    <ConfirmDialog
      :show="deletingPayment !== null"
      title="Smazat způsob platby"
      :message="`Opravdu smazat platbu ${deletingPayment?.name ?? ''}? Akci nelze vzít zpět.`"
      confirm-label="Smazat"
      danger
      @cancel="deletingPayment = null"
      @confirm="confirmDeletePayment"
    />
  </AdminLayout>
</template>
