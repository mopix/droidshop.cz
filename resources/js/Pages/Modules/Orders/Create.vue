<script setup lang="ts">
import { computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import InputLabel from '@/Components/InputLabel.vue'
import InputError from '@/Components/InputError.vue'
import TextInput from '@/Components/TextInput.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

type ProductOption = {
  id: number
  name: string
  sku: string | null
  price: number
  currency: string
}

const props = defineProps<{
  products: ProductOption[]
}>()

type Line = { product_id: number | null; quantity: number }

const form = useForm({
  email: '',
  phone: '',
  billing: {
    name: '',
    company: '',
    ico: '',
    dic: '',
    street: '',
    city: '',
    zip: '',
    country: 'CZ',
  },
  ship_to_different: false,
  shipping: {
    name: '',
    street: '',
    city: '',
    zip: '',
    country: 'CZ',
  },
  items: [{ product_id: null, quantity: 1 }] as Line[],
  note: '',
})

const productById = (id: number | null) => props.products.find((p) => p.id === id) ?? null

const money = (haler: number, currency: string) =>
  new Intl.NumberFormat('cs-CZ', { style: 'currency', currency }).format(haler / 100)

/** Display-only estimate — the server always recomputes every figure from the catalogue. */
const estimatedTotal = computed(() =>
  form.items.reduce((sum, line) => {
    const product = productById(line.product_id)

    return product ? sum + product.price * line.quantity : sum
  }, 0),
)

const addLine = () => {
  form.items.push({ product_id: null, quantity: 1 })
}

const removeLine = (index: number) => {
  form.items.splice(index, 1)
}

const submit = () => {
  form.transform((data) => ({
    ...data,
    billing: Object.fromEntries(Object.entries(data.billing).filter(([, v]) => v !== '')),
    shipping: data.ship_to_different ? data.shipping : null,
    phone: data.phone || null,
    note: data.note || null,
  })).post(route('admin.orders.store'))
}
</script>

<template>
  <AdminLayout title="Nová objednávka">
    <template #header>
      <p class="text-sm text-gray-700">
        <Link
          :href="route('admin.orders.index')"
          class="underline hover:no-underline focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
        >
          Objednávky
        </Link>
      </p>
      <h1 class="mt-1 text-xl font-semibold text-gray-900">Nová objednávka</h1>
      <p class="mt-1 text-sm text-gray-600">
        Ruční objednávka (telefon, e-mail) — bez online platby. Ceny se vždy počítají znovu podle
        aktuálního ceníku.
      </p>
    </template>

    <form class="grid gap-6 lg:grid-cols-3" @submit.prevent="submit">
      <div class="space-y-6 lg:col-span-2">
        <!-- ===================== Items ===================== -->
        <fieldset class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
          <legend class="px-1 text-sm font-semibold text-gray-900">Položky</legend>

          <div v-for="(line, index) in form.items" :key="index" class="mt-3 flex flex-wrap items-end gap-3">
            <div class="min-w-[16rem] flex-1">
              <InputLabel :for="`item-product-${index}`">Produkt</InputLabel>
              <select
                :id="`item-product-${index}`"
                v-model="line.product_id"
                class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
              >
                <option :value="null">Vyberte produkt…</option>
                <option v-for="product in products" :key="product.id" :value="product.id">
                  {{ product.name }}<template v-if="product.sku"> ({{ product.sku }})</template>
                  — {{ money(product.price, product.currency) }}
                </option>
              </select>
            </div>

            <div class="w-28">
              <InputLabel :for="`item-quantity-${index}`">Množství</InputLabel>
              <TextInput
                :id="`item-quantity-${index}`"
                v-model.number="line.quantity"
                type="number"
                min="1"
                class="mt-1 w-full"
              />
            </div>

            <button
              type="button"
              :disabled="form.items.length <= 1"
              class="rounded-md px-3 py-2 text-sm font-medium text-red-800 hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-800 disabled:cursor-not-allowed disabled:text-gray-400 disabled:hover:bg-transparent"
              @click="removeLine(index)"
            >
              Odebrat
            </button>
          </div>

          <InputError class="mt-2" :message="form.errors.items" />

          <button
            type="button"
            class="mt-3 rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-800 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
            @click="addLine"
          >
            Přidat položku
          </button>

          <p class="mt-4 text-sm text-gray-700">
            Odhad celkem (položky): <strong>{{ money(estimatedTotal, 'CZK') }}</strong>
            <span class="block text-xs text-gray-600">
              Orientační — konečnou cenu vždy dopočítá server podle aktuálního ceníku.
            </span>
          </p>
        </fieldset>

        <!-- ===================== Contact ===================== -->
        <fieldset class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
          <legend class="px-1 text-sm font-semibold text-gray-900">Kontakt</legend>

          <div class="mt-3 grid gap-4 sm:grid-cols-2">
            <div>
              <InputLabel for="email">E-mail</InputLabel>
              <TextInput id="email" v-model="form.email" type="email" required class="mt-1 w-full" />
              <InputError class="mt-1" :message="form.errors.email" />
            </div>
            <div>
              <InputLabel for="phone">Telefon</InputLabel>
              <TextInput id="phone" v-model="form.phone" type="tel" class="mt-1 w-full" />
              <InputError class="mt-1" :message="form.errors.phone" />
            </div>
          </div>
        </fieldset>

        <!-- ===================== Billing ===================== -->
        <fieldset class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
          <legend class="px-1 text-sm font-semibold text-gray-900">Fakturační adresa</legend>

          <div class="mt-3 grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
              <InputLabel for="billing-name">Jméno a příjmení</InputLabel>
              <TextInput id="billing-name" v-model="form.billing.name" required class="mt-1 w-full" />
              <InputError class="mt-1" :message="form.errors['billing.name']" />
            </div>
            <div>
              <InputLabel for="billing-company">Firma (nepovinné)</InputLabel>
              <TextInput id="billing-company" v-model="form.billing.company" class="mt-1 w-full" />
            </div>
            <div>
              <InputLabel for="billing-ico">IČO (nepovinné)</InputLabel>
              <TextInput id="billing-ico" v-model="form.billing.ico" class="mt-1 w-full" />
              <InputError class="mt-1" :message="form.errors['billing.ico']" />
            </div>
            <div class="sm:col-span-2">
              <InputLabel for="billing-street">Ulice a číslo</InputLabel>
              <TextInput id="billing-street" v-model="form.billing.street" required class="mt-1 w-full" />
              <InputError class="mt-1" :message="form.errors['billing.street']" />
            </div>
            <div>
              <InputLabel for="billing-city">Město</InputLabel>
              <TextInput id="billing-city" v-model="form.billing.city" required class="mt-1 w-full" />
              <InputError class="mt-1" :message="form.errors['billing.city']" />
            </div>
            <div>
              <InputLabel for="billing-zip">PSČ</InputLabel>
              <TextInput id="billing-zip" v-model="form.billing.zip" required class="mt-1 w-full" />
              <InputError class="mt-1" :message="form.errors['billing.zip']" />
            </div>
            <div>
              <InputLabel for="billing-country">Země (kód, např. CZ)</InputLabel>
              <TextInput id="billing-country" v-model="form.billing.country" maxlength="2" required class="mt-1 w-full" />
              <InputError class="mt-1" :message="form.errors['billing.country']" />
            </div>
          </div>
        </fieldset>

        <!-- ===================== Delivery ===================== -->
        <fieldset class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
          <legend class="px-1 text-sm font-semibold text-gray-900">Doručovací adresa</legend>

          <label class="mt-3 flex items-center gap-2 text-sm text-gray-800">
            <input v-model="form.ship_to_different" type="checkbox" class="rounded border-gray-300 text-gray-900 focus:ring-gray-900" />
            Doručit na jinou adresu než fakturační
          </label>

          <div v-if="form.ship_to_different" class="mt-3 grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
              <InputLabel for="shipping-name">Jméno a příjmení</InputLabel>
              <TextInput id="shipping-name" v-model="form.shipping.name" class="mt-1 w-full" />
              <InputError class="mt-1" :message="form.errors['shipping.name']" />
            </div>
            <div class="sm:col-span-2">
              <InputLabel for="shipping-street">Ulice a číslo</InputLabel>
              <TextInput id="shipping-street" v-model="form.shipping.street" class="mt-1 w-full" />
              <InputError class="mt-1" :message="form.errors['shipping.street']" />
            </div>
            <div>
              <InputLabel for="shipping-city">Město</InputLabel>
              <TextInput id="shipping-city" v-model="form.shipping.city" class="mt-1 w-full" />
              <InputError class="mt-1" :message="form.errors['shipping.city']" />
            </div>
            <div>
              <InputLabel for="shipping-zip">PSČ</InputLabel>
              <TextInput id="shipping-zip" v-model="form.shipping.zip" class="mt-1 w-full" />
              <InputError class="mt-1" :message="form.errors['shipping.zip']" />
            </div>
            <div>
              <InputLabel for="shipping-country">Země (kód, např. CZ)</InputLabel>
              <TextInput id="shipping-country" v-model="form.shipping.country" maxlength="2" class="mt-1 w-full" />
              <InputError class="mt-1" :message="form.errors['shipping.country']" />
            </div>
          </div>
        </fieldset>

        <fieldset class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
          <legend class="px-1 text-sm font-semibold text-gray-900">Poznámka</legend>
          <textarea
            v-model="form.note"
            rows="3"
            maxlength="255"
            class="mt-2 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
          />
        </fieldset>
      </div>

      <div class="space-y-6">
        <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
          <h2 class="text-sm font-semibold text-gray-900">Vytvořit objednávku</h2>
          <p class="mt-1 text-sm text-gray-700">
            Objednávka se vytvoří ve stavu „Nová“, bez online platby (nezaplaceno). Sklad se odečte
            stejně jako u objednávky z e-shopu.
          </p>

          <PrimaryButton type="submit" class="mt-4 w-full justify-center" :disabled="form.processing">
            Vytvořit objednávku
          </PrimaryButton>
          <Link
            :href="route('admin.orders.index')"
            class="mt-2 inline-flex w-full items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
          >
            Zrušit
          </Link>
        </section>
      </div>
    </form>
  </AdminLayout>
</template>
