<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

type MethodRef = { id: number; name: string }

const props = defineProps<{
  shippingMethods: MethodRef[]
  paymentMethods: MethodRef[]
  /** shipping method id → list of allowed payment method ids. */
  matrix: Record<string, number[]>
}>()

// One array of ticked payment ids per shipping row. An empty array is a valid,
// meaningful state — the server reads it as "all active payments allowed"
// (decision 1) — so it is kept, not dropped.
const form = useForm<{ matrix: Record<number, number[]> }>({
  matrix: Object.fromEntries(
    props.shippingMethods.map((s) => [s.id, [...(props.matrix[s.id] ?? [])]]),
  ),
})

const rowIsOpen = (shippingId: number) => (form.matrix[shippingId]?.length ?? 0) === 0

const save = () => form.put(route('admin.shipping.matrix.update'), { preserveScroll: true })
</script>

<template>
  <AdminLayout title="Matice dopravy a plateb">
    <template #header>
      <p class="text-sm text-gray-700">
        <Link
          :href="route('admin.shipping.index')"
          class="underline hover:no-underline focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
        >
          Doprava a platba
        </Link>
      </p>
      <h1 class="mt-1 text-xl font-semibold text-gray-900">Matice dopravy a plateb</h1>
    </template>

    <div
      class="mb-6 rounded-md border border-sky-300 bg-sky-50 px-4 py-3 text-sm text-sky-900"
      role="note"
    >
      <p class="font-semibold">Jak matice funguje</p>
      <p class="mt-1">
        Zaškrtnutím určíte, které platby jdou u dané dopravy použít.
        <strong>Řada bez jediného zaškrtnutí znamená, že jsou u té dopravy povoleny všechny
        platby</strong> — nejde o zákaz všech plateb. Chcete-li dopravu omezit, zaškrtněte konkrétní
        platby.
      </p>
      <p class="mt-1">V matici se zobrazují jen aktivní způsoby dopravy a platby.</p>
    </div>

    <form @submit.prevent="save">
      <div
        v-if="shippingMethods.length === 0 || paymentMethods.length === 0"
        class="rounded-lg border border-gray-200 bg-white px-4 py-8 text-center text-gray-600 shadow-sm"
      >
        Pro sestavení matice potřebujete alespoň jeden aktivní způsob dopravy a jeden aktivní způsob
        platby.
      </div>

      <div v-else class="overflow-x-auto rounded-lg border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full border-collapse text-sm">
          <caption class="sr-only">
            Povolené kombinace dopravy a platby. Sloupce jsou způsoby platby, řádky způsoby dopravy.
          </caption>
          <thead>
            <tr>
              <th scope="col" class="px-4 py-3 text-left font-semibold text-gray-900">Doprava \ Platba</th>
              <th
                v-for="payment in paymentMethods"
                :key="payment.id"
                scope="col"
                class="px-4 py-3 text-center font-semibold text-gray-900"
              >
                {{ payment.name }}
              </th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200">
            <tr v-for="shipping in shippingMethods" :key="shipping.id">
              <th scope="row" class="px-4 py-3 text-left font-medium text-gray-900">
                {{ shipping.name }}
                <span
                  v-if="rowIsOpen(shipping.id)"
                  class="mt-1 block text-xs font-normal text-gray-600"
                >
                  všechny platby povoleny
                </span>
              </th>
              <td
                v-for="payment in paymentMethods"
                :key="payment.id"
                class="px-4 py-3 text-center"
              >
                <input
                  type="checkbox"
                  :value="payment.id"
                  v-model="form.matrix[shipping.id]"
                  class="h-4 w-4 rounded border-gray-300 text-gray-900 focus:ring-gray-900"
                  :aria-label="`Povolit platbu ${payment.name} u dopravy ${shipping.name}`"
                />
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="mt-6 flex items-center gap-3">
        <button
          type="submit"
          :disabled="form.processing || shippingMethods.length === 0 || paymentMethods.length === 0"
          class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:bg-gray-400 disabled:text-gray-700"
        >
          Uložit matici
        </button>
      </div>
    </form>
  </AdminLayout>
</template>
