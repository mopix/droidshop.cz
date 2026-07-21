<script setup lang="ts">
import { computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

type Address = {
  name?: string
  company?: string
  street?: string
  city?: string
  zip?: string
  country?: string
} | null

type OrderItem = {
  id: number
  name: string
  sku: string | null
  unit_price: number
  tax_rate: number
  quantity: number
  line_total: number
}

type OrderEventRow = {
  id: number
  actor_type: string
  actor_id: number | null
  type: string
  from: string | null
  to: string | null
  note: string | null
  created_at: string | null
}

type OrderDetail = {
  uuid: string
  number: string
  email: string
  phone: string | null
  customer_id: number | null
  fulfillment_status: string
  payment_status: string
  items_total: number
  shipping_total: number
  payment_fee: number
  total: number
  currency: string
  placed_at: string | null
  source: string
  billing: Address
  shipping: Address
  note: string | null
  items: OrderItem[]
  events: OrderEventRow[]
}

const props = defineProps<{
  order: OrderDetail
  can: { edit: boolean }
}>()

const FULFILLMENT_LABELS: Record<string, string> = {
  new: 'Nová',
  accepted: 'Přijatá',
  processing: 'Zpracovává se',
  shipped: 'Odeslaná',
  delivered: 'Doručená',
  cancelled: 'Zrušená',
}

const PAYMENT_LABELS: Record<string, string> = {
  unpaid: 'Nezaplaceno',
  paid: 'Zaplaceno',
  refunded: 'Vráceno',
}

const ACTOR_LABELS: Record<string, string> = {
  system: 'Systém',
  admin: 'Administrátor',
  customer: 'Zákazník',
}

// Client-side convenience only — mirrors Modules\Orders\Services\OrderWorkflow
// so the select never offers a move the server would reject anyway. The
// server re-checks the same graph regardless; this is not the enforcement.
const FULFILLMENT_NEXT: Record<string, string[]> = {
  new: ['accepted', 'cancelled'],
  accepted: ['processing', 'cancelled'],
  processing: ['shipped', 'cancelled'],
  shipped: ['delivered', 'cancelled'],
  delivered: [],
  cancelled: [],
}

const PAYMENT_NEXT: Record<string, string[]> = {
  unpaid: ['paid'],
  paid: ['refunded'],
  refunded: [],
}

const money = (haler: number) =>
  new Intl.NumberFormat('cs-CZ', { style: 'currency', currency: props.order.currency }).format(haler / 100)

const fulfillmentOptions = computed(() => FULFILLMENT_NEXT[props.order.fulfillment_status] ?? [])
const paymentOptions = computed(() => PAYMENT_NEXT[props.order.payment_status] ?? [])

const fulfillmentForm = useForm({ machine: 'fulfillment', to: '', note: '' })
const paymentForm = useForm({ machine: 'payment', to: '', note: '' })

const submitFulfillment = () => {
  if (!fulfillmentForm.to) return

  fulfillmentForm.patch(route('admin.orders.state.update', props.order.uuid), {
    preserveScroll: true,
    onSuccess: () => {
      fulfillmentForm.reset('to', 'note')
    },
  })
}

const submitPayment = () => {
  if (!paymentForm.to) return

  paymentForm.patch(route('admin.orders.state.update', props.order.uuid), {
    preserveScroll: true,
    onSuccess: () => {
      paymentForm.reset('to', 'note')
    },
  })
}

const formatAddress = (address: Address) => {
  if (!address) return null

  return [address.company, address.name, address.street, [address.zip, address.city].filter(Boolean).join(' '), address.country]
    .filter(Boolean)
    .join(', ')
}
</script>

<template>
  <AdminLayout :title="`Objednávka ${order.number}`">
    <template #header>
      <p class="text-sm text-gray-700">
        <Link
          :href="route('admin.orders.index')"
          class="underline hover:no-underline focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
        >
          Objednávky
        </Link>
      </p>
      <h1 class="mt-1 text-xl font-semibold text-gray-900">Objednávka {{ order.number }}</h1>
      <p class="mt-1 text-sm text-gray-600">
        {{ order.email }}<span v-if="order.phone"> · {{ order.phone }}</span>
        <span v-if="order.placed_at"> · přijata {{ order.placed_at }}</span>
      </p>
    </template>

    <div class="grid gap-6 lg:grid-cols-3">
      <div class="space-y-6 lg:col-span-2">
        <!-- ===================== Items ===================== -->
        <section aria-labelledby="items-heading" class="rounded-lg border border-gray-200 bg-white shadow-sm">
          <h2 id="items-heading" class="border-b border-gray-200 px-4 py-3 text-sm font-semibold text-gray-900">
            Položky
          </h2>

          <table class="min-w-full divide-y divide-gray-200 text-sm">
            <caption class="sr-only">Položky objednávky {{ order.number }}</caption>
            <thead class="bg-gray-50">
              <tr>
                <th scope="col" class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-700">Položka</th>
                <th scope="col" class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wide text-gray-700">Kus</th>
                <th scope="col" class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wide text-gray-700">Množství</th>
                <th scope="col" class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wide text-gray-700">Celkem</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
              <tr v-for="item in order.items" :key="item.id">
                <td class="px-4 py-2 text-gray-900">
                  {{ item.name }}
                  <span v-if="item.sku" class="block text-xs text-gray-600">{{ item.sku }}</span>
                </td>
                <td class="px-4 py-2 text-right text-gray-900">{{ money(item.unit_price) }}</td>
                <td class="px-4 py-2 text-right text-gray-900">{{ item.quantity }}</td>
                <td class="px-4 py-2 text-right text-gray-900">{{ money(item.line_total) }}</td>
              </tr>
            </tbody>
          </table>

          <dl class="grid gap-1 border-t border-gray-200 px-4 py-3 text-sm">
            <div class="flex justify-between">
              <dt class="text-gray-700">Položky</dt>
              <dd class="text-gray-900">{{ money(order.items_total) }}</dd>
            </div>
            <div class="flex justify-between">
              <dt class="text-gray-700">Doprava</dt>
              <dd class="text-gray-900">{{ order.shipping_total === 0 ? 'zdarma' : money(order.shipping_total) }}</dd>
            </div>
            <div v-if="order.payment_fee" class="flex justify-between">
              <dt class="text-gray-700">Poplatek za platbu</dt>
              <dd class="text-gray-900">{{ money(order.payment_fee) }}</dd>
            </div>
            <div class="flex justify-between font-semibold">
              <dt class="text-gray-900">Celkem</dt>
              <dd class="text-gray-900">{{ money(order.total) }}</dd>
            </div>
          </dl>
        </section>

        <!-- ===================== Addresses ===================== -->
        <section aria-labelledby="addresses-heading" class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
          <h2 id="addresses-heading" class="text-sm font-semibold text-gray-900">Adresy</h2>

          <dl class="mt-3 grid gap-4 sm:grid-cols-2">
            <div>
              <dt class="text-sm font-medium text-gray-700">Fakturační adresa</dt>
              <dd class="text-gray-900">{{ formatAddress(order.billing) ?? '—' }}</dd>
            </div>
            <div>
              <dt class="text-sm font-medium text-gray-700">Doručovací adresa</dt>
              <dd class="text-gray-900">{{ formatAddress(order.shipping) ?? 'shodná s fakturační' }}</dd>
            </div>
          </dl>

          <p v-if="order.note" class="mt-4 rounded-md bg-gray-100 px-3 py-2 text-sm text-gray-800">
            <span class="font-medium">Poznámka zákazníka:</span> {{ order.note }}
          </p>
        </section>

        <!-- ===================== History ===================== -->
        <section aria-labelledby="history-heading" class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
          <h2 id="history-heading" class="text-sm font-semibold text-gray-900">Historie a interní poznámky</h2>

          <p v-if="order.events.length === 0" class="mt-2 text-sm text-gray-600">Zatím žádná událost.</p>

          <ol v-else class="mt-3 space-y-3">
            <li v-for="event in order.events" :key="event.id" class="border-l-2 border-gray-200 pl-3 text-sm">
              <p class="text-gray-900">
                <span class="font-medium">{{ ACTOR_LABELS[event.actor_type] ?? event.actor_type }}</span>
                <span v-if="event.from && event.to">
                  změnil{{ event.type === 'payment' ? ' platbu' : ' vyřízení' }} z
                  „{{ FULFILLMENT_LABELS[event.from] ?? PAYMENT_LABELS[event.from] ?? event.from }}“ na
                  „{{ FULFILLMENT_LABELS[event.to] ?? PAYMENT_LABELS[event.to] ?? event.to }}“
                </span>
                <span v-else-if="event.to"> — {{ event.type }}</span>
              </p>
              <p v-if="event.note" class="text-gray-700">{{ event.note }}</p>
              <p v-if="event.created_at" class="text-xs text-gray-600">{{ event.created_at }}</p>
            </li>
          </ol>
        </section>
      </div>

      <!-- ===================== State machines ===================== -->
      <div class="space-y-6">
        <section aria-labelledby="fulfillment-heading" class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
          <h2 id="fulfillment-heading" class="text-sm font-semibold text-gray-900">Stav vyřízení</h2>
          <p class="mt-1 text-sm text-gray-700">
            Aktuálně: <strong>{{ FULFILLMENT_LABELS[order.fulfillment_status] ?? order.fulfillment_status }}</strong>
          </p>

          <form v-if="can.edit && fulfillmentOptions.length" class="mt-3 space-y-2" @submit.prevent="submitFulfillment">
            <div>
              <label for="fulfillment-to" class="block text-sm font-medium text-gray-700">Nový stav</label>
              <select
                id="fulfillment-to"
                v-model="fulfillmentForm.to"
                class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
              >
                <option value="">Vyberte…</option>
                <option v-for="target in fulfillmentOptions" :key="target" :value="target">
                  {{ FULFILLMENT_LABELS[target] ?? target }}
                </option>
              </select>
            </div>

            <div>
              <label for="fulfillment-note" class="block text-sm font-medium text-gray-700">Poznámka (nepovinné)</label>
              <textarea
                id="fulfillment-note"
                v-model="fulfillmentForm.note"
                rows="2"
                maxlength="255"
                class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
              />
            </div>

            <p v-if="fulfillmentForm.errors.to" class="text-sm text-red-700">{{ fulfillmentForm.errors.to }}</p>

            <button
              type="submit"
              :disabled="fulfillmentForm.processing || !fulfillmentForm.to"
              class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:bg-gray-400 disabled:text-gray-700"
            >
              Změnit stav
            </button>
          </form>

          <p v-else-if="can.edit" class="mt-3 text-sm text-gray-600">Konečný stav, dál se již neposouvá.</p>
        </section>

        <section aria-labelledby="payment-heading" class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
          <h2 id="payment-heading" class="text-sm font-semibold text-gray-900">Stav platby</h2>
          <p class="mt-1 text-sm text-gray-700">
            Aktuálně: <strong>{{ PAYMENT_LABELS[order.payment_status] ?? order.payment_status }}</strong>
          </p>

          <form v-if="can.edit && paymentOptions.length" class="mt-3 space-y-2" @submit.prevent="submitPayment">
            <div>
              <label for="payment-to" class="block text-sm font-medium text-gray-700">Nový stav</label>
              <select
                id="payment-to"
                v-model="paymentForm.to"
                class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
              >
                <option value="">Vyberte…</option>
                <option v-for="target in paymentOptions" :key="target" :value="target">
                  {{ PAYMENT_LABELS[target] ?? target }}
                </option>
              </select>
            </div>

            <div>
              <label for="payment-note" class="block text-sm font-medium text-gray-700">Poznámka (nepovinné)</label>
              <textarea
                id="payment-note"
                v-model="paymentForm.note"
                rows="2"
                maxlength="255"
                class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
              />
            </div>

            <p v-if="paymentForm.errors.to" class="text-sm text-red-700">{{ paymentForm.errors.to }}</p>

            <button
              type="submit"
              :disabled="paymentForm.processing || !paymentForm.to"
              class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:bg-gray-400 disabled:text-gray-700"
            >
              Změnit stav
            </button>
          </form>

          <p v-else-if="can.edit" class="mt-3 text-sm text-gray-600">Konečný stav, dál se již neposouvá.</p>
        </section>
      </div>
    </div>
  </AdminLayout>
</template>
