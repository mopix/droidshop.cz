<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import ConfirmDialog from '@/Components/Ui/ConfirmDialog.vue'

type Address = {
  name?: string
  company?: string
  ico?: string
  dic?: string
  street?: string
  city?: string
  zip?: string
  country?: string
} | null

type OrderItem = {
  id: number
  product_id: number | null
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

type DocumentRow = {
  number: string
  type: string
  total: number
  currency: string
  issued_at: string | null
  sent_at: string | null
  downloadable: boolean
}

type OrderDetail = {
  uuid: string
  number: string
  email: string
  phone: string | null
  customer_id: number | null
  fulfillment_status: string
  payment_status: string
  // Gateway transaction reference (Comgate transId), null for offline payments.
  payment_reference: string | null
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
  // Whether OrderEditor::edit() still accepts a PATCH on this order right
  // now (fulfillment status up to and including "shipped") — server-derived
  // via OrderEditor::isEditable(), never re-guessed here.
  editable: boolean
  items: OrderItem[]
  events: OrderEventRow[]
  documents: DocumentRow[]
}

const props = defineProps<{
  order: OrderDetail
  can: { edit: boolean; cancel: boolean; issueDocument: boolean }
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
  failed: 'Platba selhala',
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
//
// "cancelled" is deliberately absent from every list here: cancellation is
// not offered through this generic state select at all (server-side,
// ChangeStateRequest no longer accepts it either) — it has its own button,
// permission (orders.cancel) and confirm dialog below.
const FULFILLMENT_NEXT: Record<string, string[]> = {
  new: ['accepted'],
  accepted: ['processing'],
  processing: ['shipped'],
  shipped: ['delivered'],
  delivered: [],
  cancelled: [],
}

const PAYMENT_NEXT: Record<string, string[]> = {
  unpaid: ['paid'],
  paid: ['refunded'],
  refunded: [],
}

const money = (haler: number, currency: string = props.order.currency) =>
  new Intl.NumberFormat('cs-CZ', { style: 'currency', currency }).format(haler / 100)

const fulfillmentOptions = computed(() => FULFILLMENT_NEXT[props.order.fulfillment_status] ?? [])
const paymentOptions = computed(() => PAYMENT_NEXT[props.order.payment_status] ?? [])

// A cancel is offered whenever the fulfillment machine still allows it —
// every state except the two terminal ones (delivered, cancelled). Mirrors
// Modules\Orders\Services\OrderWorkflow's graph; the server is what actually
// enforces it (OrderEditor::cancel → OrderWorkflow::transitionFulfillment).
const canCancelNow = computed(
  () => !['delivered', 'cancelled'].includes(props.order.fulfillment_status),
)

const fulfillmentForm = useForm({ machine: 'fulfillment', to: '', note: '', send_email: false })
const paymentForm = useForm({ machine: 'payment', to: '', note: '', send_email: false })

const submitFulfillment = () => {
  if (!fulfillmentForm.to) return

  fulfillmentForm.patch(route('admin.orders.state.update', props.order.uuid), {
    preserveScroll: true,
    onSuccess: () => {
      fulfillmentForm.reset('to', 'note', 'send_email')
    },
  })
}

const submitPayment = () => {
  if (!paymentForm.to) return

  paymentForm.patch(route('admin.orders.state.update', props.order.uuid), {
    preserveScroll: true,
    onSuccess: () => {
      paymentForm.reset('to', 'note', 'send_email')
    },
  })
}

// --- Cancellation (storno) -------------------------------------------------

const cancelling = ref(false)
const returnStock = ref(true)
const sendCancelEmail = ref(true)
const cancelForm = useForm({ reason: '', return_stock: true, send_email: true })

const submitCancel = (reason: string) => {
  cancelForm
    .transform((data) => ({ ...data, reason, return_stock: returnStock.value, send_email: sendCancelEmail.value }))
    .post(route('admin.orders.cancel', props.order.uuid), {
      preserveScroll: true,
      onSuccess: () => {
        cancelling.value = false
      },
      onError: () => {
        // Keep the dialog open so the admin sees the validation error
        // (e.g. a missing reason) instead of losing what they typed.
      },
    })
}

// --- Documents (invoices) ---------------------------------------------------
//
// Issuing is not destructive (Document::booted refuses updates/deletes on an
// issued row, so there is nothing here to undo) — no confirm dialog, unlike
// storno above. issue() is idempotent server-side (InvoiceIssuer), so a
// double click here at worst re-submits the same request rather than
// creating a second document.

const DOCUMENT_TYPE_LABELS: Record<string, string> = {
  invoice: 'Faktura',
  proforma: 'Zálohová faktura',
  credit_note: 'Dobropis',
}

const issueForm = useForm({ order_uuid: props.order.uuid })

const issueDocument = () => {
  issueForm.post(route('admin.docs.store'), { preserveScroll: true })
}

// --- Item / address edit ----------------------------------------------------
//
// Mirrors exactly what Modules\Orders\Http\Requests\UpdateOrderRequest
// validates: items[].{product_id,quantity}, email, phone, billing (with
// name/company/ico/dic/street/city/zip/country), an optional shipping
// address of the same shape (minus company/ico/dic), and note — the latter
// becomes the order_events row's note (an internal record of *why* the
// order was edited), never order.note (the customer's own note, left
// untouched). No price/total is computed or sent from here: the server
// re-prices every line from the catalogue (OrderEditor::edit), same as the
// rest of checkout.
//
// Scope note: this only edits existing lines' quantities (or removes a
// line) — there is no "add a new product" control here, since Show.vue has
// no product catalogue to pick from (unlike Create.vue's manual-order
// form). Quantity 0 is not offered as a way to remove a line because
// UpdateOrderRequest validates items.*.quantity as min:1 — removal is done
// by dropping the line from the array entirely (the "Odebrat" button).

type EditableLine = {
  product_id: number | null
  quantity: number
  name: string
  sku: string | null
}

const editForm = useForm({
  email: '',
  phone: '',
  billing: { name: '', company: '', ico: '', dic: '', street: '', city: '', zip: '', country: 'CZ' },
  ship_to_different: false,
  shipping: { name: '', street: '', city: '', zip: '', country: 'CZ' },
  items: [] as EditableLine[],
  note: '',
})

const editingOrder = ref(false)

const canRemoveEditLine = computed(() => editForm.items.length > 1)

const resetEditForm = () => {
  editForm.email = props.order.email
  editForm.phone = props.order.phone ?? ''
  editForm.billing = {
    name: props.order.billing?.name ?? '',
    company: props.order.billing?.company ?? '',
    ico: props.order.billing?.ico ?? '',
    dic: props.order.billing?.dic ?? '',
    street: props.order.billing?.street ?? '',
    city: props.order.billing?.city ?? '',
    zip: props.order.billing?.zip ?? '',
    country: props.order.billing?.country ?? 'CZ',
  }
  editForm.ship_to_different = !!props.order.shipping
  editForm.shipping = {
    name: props.order.shipping?.name ?? '',
    street: props.order.shipping?.street ?? '',
    city: props.order.shipping?.city ?? '',
    zip: props.order.shipping?.zip ?? '',
    country: props.order.shipping?.country ?? 'CZ',
  }
  editForm.items = props.order.items.map((item) => ({
    product_id: item.product_id,
    quantity: item.quantity,
    name: item.name,
    sku: item.sku,
  }))
  editForm.note = ''
  editForm.clearErrors()
}

const toggleEditOrder = () => {
  if (!editingOrder.value) {
    resetEditForm()
  }

  editingOrder.value = !editingOrder.value
}

const removeEditLine = (index: number) => {
  if (!canRemoveEditLine.value) return

  editForm.items.splice(index, 1)
}

const submitEdit = () => {
  editForm
    .transform((data) => ({
      // Only the fields UpdateOrderRequest declares a rule for — the
      // display-only name/sku on each line are dropped here rather than
      // relying on the server to ignore them.
      items: data.items.map((line) => ({ product_id: line.product_id, quantity: line.quantity })),
      email: data.email,
      phone: data.phone || null,
      billing: Object.fromEntries(Object.entries(data.billing).filter(([, v]) => v !== '')),
      shipping: data.ship_to_different
        ? {
            name: data.shipping.name,
            street: data.shipping.street,
            city: data.shipping.city,
            zip: data.shipping.zip,
            country: data.shipping.country,
          }
        : null,
      note: data.note || null,
    }))
    .patch(route('admin.orders.update', props.order.uuid), {
      preserveScroll: true,
      onSuccess: () => {
        editingOrder.value = false
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

        <!-- ===================== Documents (invoices) ===================== -->
        <section v-if="can.issueDocument || order.documents.length" aria-labelledby="documents-heading" class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
          <div class="flex items-center justify-between gap-3">
            <h2 id="documents-heading" class="text-sm font-semibold text-gray-900">Doklady</h2>

            <button
              v-if="can.issueDocument"
              type="button"
              :disabled="issueForm.processing"
              class="rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:bg-gray-400 disabled:text-gray-700"
              @click="issueDocument"
            >
              Vytvořit doklad
            </button>
          </div>

          <p v-if="order.documents.length === 0" class="mt-2 text-sm text-gray-600">
            K této objednávce zatím nebyl vystaven žádný doklad.
          </p>

          <ul v-else class="mt-3 divide-y divide-gray-200">
            <li v-for="document in order.documents" :key="document.number" class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
              <div>
                <span class="font-medium text-gray-900">{{ document.number }}</span>
                <span class="ml-2 text-gray-700">{{ DOCUMENT_TYPE_LABELS[document.type] ?? document.type }}</span>
                <span v-if="document.issued_at" class="block text-xs text-gray-600">vystaveno {{ document.issued_at }}</span>
              </div>
              <div class="flex items-center gap-3">
                <span class="text-gray-900">{{ money(document.total, document.currency) }}</span>
                <a
                  v-if="document.downloadable"
                  :href="route('admin.docs.download', { number: document.number, type: document.type })"
                  class="underline hover:no-underline focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
                >
                  Stáhnout PDF
                </a>
                <span v-else class="text-gray-700">Připravuje se…</span>
              </div>
            </li>
          </ul>
        </section>

        <!-- ===================== Edit (items / addresses) ===================== -->
        <section v-if="can.edit" aria-labelledby="edit-heading" class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
          <div class="flex items-center justify-between gap-3">
            <h2 id="edit-heading" class="text-sm font-semibold text-gray-900">Úprava položek a adres</h2>

            <button
              v-if="order.editable"
              type="button"
              class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-800 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
              @click="toggleEditOrder"
            >
              {{ editingOrder ? 'Zavřít úpravu' : 'Upravit položky a adresy' }}
            </button>
          </div>

          <p v-if="!order.editable" class="mt-2 text-sm text-gray-600">
            Objednávku v aktuálním stavu vyřízení už nelze upravovat.
          </p>

          <form v-else-if="editingOrder" class="mt-4 space-y-6" @submit.prevent="submitEdit">
            <!-- Items -->
            <fieldset>
              <legend class="text-sm font-medium text-gray-700">Položky</legend>

              <div
                v-for="(line, index) in editForm.items"
                :key="index"
                class="mt-2 flex flex-wrap items-end gap-3 border-b border-gray-100 pb-2 last:border-0"
              >
                <div class="min-w-[12rem] flex-1">
                  <p class="text-sm text-gray-900">
                    {{ line.name }}
                    <span v-if="line.sku" class="block text-xs text-gray-600">{{ line.sku }}</span>
                  </p>
                </div>

                <div class="w-28">
                  <label :for="`edit-item-quantity-${index}`" class="block text-xs font-medium text-gray-700">Množství</label>
                  <input
                    :id="`edit-item-quantity-${index}`"
                    v-model.number="line.quantity"
                    type="number"
                    min="1"
                    class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
                  />
                  <p v-if="editForm.errors[`items.${index}.quantity`]" class="mt-1 text-xs text-red-700">
                    {{ editForm.errors[`items.${index}.quantity`] }}
                  </p>
                </div>

                <button
                  type="button"
                  :disabled="!canRemoveEditLine"
                  class="rounded-md px-3 py-2 text-sm font-medium text-red-800 hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-800 disabled:cursor-not-allowed disabled:text-gray-400 disabled:hover:bg-transparent"
                  @click="removeEditLine(index)"
                >
                  Odebrat
                </button>
              </div>

              <p v-if="editForm.errors.items" class="mt-2 text-sm text-red-700">{{ editForm.errors.items }}</p>
            </fieldset>

            <!-- Contact -->
            <fieldset class="grid gap-3 sm:grid-cols-2">
              <legend class="text-sm font-medium text-gray-700">Kontakt</legend>

              <div>
                <label for="edit-email" class="block text-xs font-medium text-gray-700">E-mail</label>
                <input
                  id="edit-email"
                  v-model="editForm.email"
                  type="email"
                  required
                  class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
                />
                <p v-if="editForm.errors.email" class="mt-1 text-xs text-red-700">{{ editForm.errors.email }}</p>
              </div>

              <div>
                <label for="edit-phone" class="block text-xs font-medium text-gray-700">Telefon</label>
                <input
                  id="edit-phone"
                  v-model="editForm.phone"
                  type="tel"
                  class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
                />
                <p v-if="editForm.errors.phone" class="mt-1 text-xs text-red-700">{{ editForm.errors.phone }}</p>
              </div>
            </fieldset>

            <!-- Billing -->
            <fieldset class="grid gap-3 sm:grid-cols-2">
              <legend class="text-sm font-medium text-gray-700">Fakturační adresa</legend>

              <div class="sm:col-span-2">
                <label for="edit-billing-name" class="block text-xs font-medium text-gray-700">Jméno a příjmení</label>
                <input
                  id="edit-billing-name"
                  v-model="editForm.billing.name"
                  required
                  class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
                />
                <p v-if="editForm.errors['billing.name']" class="mt-1 text-xs text-red-700">{{ editForm.errors['billing.name'] }}</p>
              </div>

              <div>
                <label for="edit-billing-company" class="block text-xs font-medium text-gray-700">Firma (nepovinné)</label>
                <input
                  id="edit-billing-company"
                  v-model="editForm.billing.company"
                  class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
                />
              </div>

              <div>
                <label for="edit-billing-ico" class="block text-xs font-medium text-gray-700">IČO (nepovinné)</label>
                <input
                  id="edit-billing-ico"
                  v-model="editForm.billing.ico"
                  class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
                />
                <p v-if="editForm.errors['billing.ico']" class="mt-1 text-xs text-red-700">{{ editForm.errors['billing.ico'] }}</p>
              </div>

              <div class="sm:col-span-2">
                <label for="edit-billing-street" class="block text-xs font-medium text-gray-700">Ulice a číslo</label>
                <input
                  id="edit-billing-street"
                  v-model="editForm.billing.street"
                  required
                  class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
                />
                <p v-if="editForm.errors['billing.street']" class="mt-1 text-xs text-red-700">{{ editForm.errors['billing.street'] }}</p>
              </div>

              <div>
                <label for="edit-billing-city" class="block text-xs font-medium text-gray-700">Město</label>
                <input
                  id="edit-billing-city"
                  v-model="editForm.billing.city"
                  required
                  class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
                />
                <p v-if="editForm.errors['billing.city']" class="mt-1 text-xs text-red-700">{{ editForm.errors['billing.city'] }}</p>
              </div>

              <div>
                <label for="edit-billing-zip" class="block text-xs font-medium text-gray-700">PSČ</label>
                <input
                  id="edit-billing-zip"
                  v-model="editForm.billing.zip"
                  required
                  class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
                />
                <p v-if="editForm.errors['billing.zip']" class="mt-1 text-xs text-red-700">{{ editForm.errors['billing.zip'] }}</p>
              </div>

              <div>
                <label for="edit-billing-country" class="block text-xs font-medium text-gray-700">Země (kód, např. CZ)</label>
                <input
                  id="edit-billing-country"
                  v-model="editForm.billing.country"
                  maxlength="2"
                  required
                  class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
                />
                <p v-if="editForm.errors['billing.country']" class="mt-1 text-xs text-red-700">{{ editForm.errors['billing.country'] }}</p>
              </div>
            </fieldset>

            <!-- Delivery -->
            <fieldset class="grid gap-3 sm:grid-cols-2">
              <legend class="text-sm font-medium text-gray-700">Doručovací adresa</legend>

              <label class="flex items-center gap-2 text-sm text-gray-800 sm:col-span-2">
                <input
                  v-model="editForm.ship_to_different"
                  type="checkbox"
                  class="rounded border-gray-300 text-gray-900 focus:ring-gray-900"
                />
                Doručit na jinou adresu než fakturační
              </label>

              <template v-if="editForm.ship_to_different">
                <div class="sm:col-span-2">
                  <label for="edit-shipping-name" class="block text-xs font-medium text-gray-700">Jméno a příjmení</label>
                  <input
                    id="edit-shipping-name"
                    v-model="editForm.shipping.name"
                    class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
                  />
                  <p v-if="editForm.errors['shipping.name']" class="mt-1 text-xs text-red-700">{{ editForm.errors['shipping.name'] }}</p>
                </div>

                <div class="sm:col-span-2">
                  <label for="edit-shipping-street" class="block text-xs font-medium text-gray-700">Ulice a číslo</label>
                  <input
                    id="edit-shipping-street"
                    v-model="editForm.shipping.street"
                    class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
                  />
                  <p v-if="editForm.errors['shipping.street']" class="mt-1 text-xs text-red-700">{{ editForm.errors['shipping.street'] }}</p>
                </div>

                <div>
                  <label for="edit-shipping-city" class="block text-xs font-medium text-gray-700">Město</label>
                  <input
                    id="edit-shipping-city"
                    v-model="editForm.shipping.city"
                    class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
                  />
                  <p v-if="editForm.errors['shipping.city']" class="mt-1 text-xs text-red-700">{{ editForm.errors['shipping.city'] }}</p>
                </div>

                <div>
                  <label for="edit-shipping-zip" class="block text-xs font-medium text-gray-700">PSČ</label>
                  <input
                    id="edit-shipping-zip"
                    v-model="editForm.shipping.zip"
                    class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
                  />
                  <p v-if="editForm.errors['shipping.zip']" class="mt-1 text-xs text-red-700">{{ editForm.errors['shipping.zip'] }}</p>
                </div>

                <div>
                  <label for="edit-shipping-country" class="block text-xs font-medium text-gray-700">Země (kód, např. CZ)</label>
                  <input
                    id="edit-shipping-country"
                    v-model="editForm.shipping.country"
                    maxlength="2"
                    class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
                  />
                  <p v-if="editForm.errors['shipping.country']" class="mt-1 text-xs text-red-700">{{ editForm.errors['shipping.country'] }}</p>
                </div>
              </template>
            </fieldset>

            <!-- Internal note -->
            <div>
              <label for="edit-note" class="block text-sm font-medium text-gray-700">Interní poznámka k úpravě (nepovinné)</label>
              <textarea
                id="edit-note"
                v-model="editForm.note"
                rows="2"
                maxlength="255"
                class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
              />
              <p v-if="editForm.errors.note" class="mt-1 text-xs text-red-700">{{ editForm.errors.note }}</p>
            </div>

            <div class="flex gap-3">
              <button
                type="submit"
                :disabled="editForm.processing"
                class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:bg-gray-400 disabled:text-gray-700"
              >
                Uložit změny
              </button>
              <button
                type="button"
                class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-800 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
                @click="toggleEditOrder"
              >
                Zrušit úpravu
              </button>
            </div>
          </form>
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

            <label class="flex items-center gap-2 text-sm text-gray-800">
              <input
                v-model="fulfillmentForm.send_email"
                type="checkbox"
                class="rounded border-gray-300 text-gray-900 focus:ring-gray-900"
              />
              Poslat e-mail zákazníkovi o změně stavu
            </label>

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
          <p v-if="order.payment_reference" class="mt-1 text-sm text-gray-700">
            Reference platby: <span class="font-mono">{{ order.payment_reference }}</span>
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

            <label class="flex items-center gap-2 text-sm text-gray-800">
              <input
                v-model="paymentForm.send_email"
                type="checkbox"
                class="rounded border-gray-300 text-gray-900 focus:ring-gray-900"
              />
              Poslat e-mail zákazníkovi o změně stavu
            </label>

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

        <!-- ===================== Cancellation (storno) ===================== -->
        <section v-if="can.cancel && canCancelNow" aria-labelledby="cancel-heading" class="rounded-lg border border-red-200 bg-red-50 p-4 shadow-sm">
          <h2 id="cancel-heading" class="text-sm font-semibold text-red-900">Storno objednávky</h2>
          <p class="mt-1 text-sm text-red-800">
            Objednávku zruší a nastaví stav vyřízení na „Zrušená“. Tuto akci nelze vrátit zpět.
          </p>

          <button
            type="button"
            class="mt-3 rounded-md border border-red-300 bg-white px-4 py-2 text-sm font-semibold text-red-800 hover:bg-red-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-800"
            @click="cancelling = true"
          >
            Stornovat objednávku
          </button>
        </section>
      </div>
    </div>

    <ConfirmDialog
      :show="cancelling"
      title="Stornovat objednávku"
      :message="`Opravdu stornovat objednávku ${order.number}? Stav vyřízení se změní na „Zrušená“ a akci nelze vrátit zpět.`"
      confirm-label="Stornovat"
      danger
      require-reason
      reason-label="Důvod storna"
      :reason-error="cancelForm.errors.reason"
      :processing="cancelForm.processing"
      @cancel="cancelling = false"
      @confirm="submitCancel"
    >
      <div class="space-y-3">
        <label class="flex items-center gap-2 text-sm text-gray-800">
          <input
            v-model="returnStock"
            type="checkbox"
            class="rounded border-gray-300 text-gray-900 focus:ring-gray-900"
          />
          Vrátit odečtené kusy zpět na sklad
        </label>

        <label class="flex items-center gap-2 text-sm text-gray-800">
          <input
            v-model="sendCancelEmail"
            type="checkbox"
            class="rounded border-gray-300 text-gray-900 focus:ring-gray-900"
          />
          Poslat zákazníkovi e-mail o zrušení objednávky
        </label>
      </div>
    </ConfirmDialog>
  </AdminLayout>
</template>
