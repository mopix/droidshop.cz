<script setup lang="ts">
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import ConfirmDialog from '@/Components/Ui/ConfirmDialog.vue'

type Address = {
  id: number
  kind: string
  company: string | null
  reg_no: string | null
  vat_no: string | null
  street: string
  city: string
  zip: string
  country: string
  is_default: boolean
}

type CustomerDetail = {
  id: number
  full_name: string
  email: string
  phone: string | null
  email_verified: boolean
  anonymised: boolean
  anonymised_at: string | null
  last_login_at: string | null
  created_at: string | null
  addresses: Address[]
}

const props = defineProps<{
  customer: CustomerDetail
  can: { erase: boolean }
}>()

const KIND_LABELS: Record<string, string> = {
  billing: 'Fakturační',
  shipping: 'Doručovací',
}

const erasing = ref(false)
const processing = ref(false)

const confirmErase = () => {
  // Set before the request goes out, not in onFinish: a second click on the
  // dialog's confirm button between the first request being sent and its
  // response arriving must not fire a second erase request.
  processing.value = true

  router.post(route('admin.customers.erase', props.customer.id), {}, {
    onFinish: () => {
      processing.value = false
      erasing.value = false
    },
  })
}
</script>

<template>
  <AdminLayout :title="customer.full_name || customer.email">
    <template #header>
      <p class="text-sm text-gray-700">
        <Link
          :href="route('admin.customers.index')"
          class="underline hover:no-underline focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
        >
          Zákazníci
        </Link>
      </p>
      <h1 class="mt-1 text-xl font-semibold text-gray-900">
        {{ customer.full_name || '(bez jména)' }}
      </h1>
    </template>

    <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
      <p v-if="customer.anonymised" class="mb-4 rounded-md bg-gray-100 px-3 py-2 text-sm text-gray-800">
        Údaje zákazníka byly na žádost anonymizovány
        <span v-if="customer.anonymised_at">({{ customer.anonymised_at }})</span>.
      </p>

      <dl class="grid gap-4 sm:grid-cols-2">
        <div>
          <dt class="text-sm font-medium text-gray-700">E-mail</dt>
          <dd class="text-gray-900">{{ customer.email }}</dd>
        </div>

        <div>
          <dt class="text-sm font-medium text-gray-700">Telefon</dt>
          <dd class="text-gray-900">{{ customer.phone || '—' }}</dd>
        </div>

        <div>
          <dt class="text-sm font-medium text-gray-700">E-mail ověřen</dt>
          <dd class="text-gray-900">{{ customer.email_verified ? 'Ano' : 'Ne' }}</dd>
        </div>

        <div>
          <dt class="text-sm font-medium text-gray-700">Poslední přihlášení</dt>
          <dd class="text-gray-900">{{ customer.last_login_at || 'zatím nikdy' }}</dd>
        </div>
      </dl>

      <!-- Order history waits on the orders module — this section is a
           deliberate placeholder, not a fake list. -->
      <div class="mt-6 rounded-md border border-dashed border-gray-300 p-4 text-sm text-gray-600">
        Historie objednávek se zobrazí, až bude e-shop provozovat modul objednávek.
      </div>

      <h2 class="mt-6 text-sm font-semibold text-gray-900">Adresy</h2>

      <p v-if="customer.addresses.length === 0" class="mt-2 text-sm text-gray-600">
        Zákazník zatím nemá uloženou žádnou adresu.
      </p>

      <ul v-else class="mt-2 grid gap-3 sm:grid-cols-2">
        <li
          v-for="address in customer.addresses"
          :key="address.id"
          class="rounded-md border border-gray-200 p-3 text-sm"
        >
          <p class="font-medium text-gray-900">
            {{ KIND_LABELS[address.kind] ?? address.kind }}
            <span v-if="address.is_default" class="text-xs text-gray-600">(výchozí)</span>
          </p>
          <p v-if="address.company" class="text-gray-800">{{ address.company }}</p>
          <p class="text-gray-800">{{ address.street }}</p>
          <p class="text-gray-800">{{ address.zip }} {{ address.city }}, {{ address.country }}</p>
        </li>
      </ul>

      <div class="mt-6 flex flex-wrap items-center gap-3 border-t border-gray-200 pt-4">
        <a
          :href="route('admin.customers.export', customer.id)"
          class="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
        >
          Exportovat údaje (JSON)
        </a>

        <button
          v-if="can.erase && !customer.anonymised"
          type="button"
          class="rounded-md px-4 py-2 text-sm font-semibold text-red-800 hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-800"
          @click="erasing = true"
        >
          Anonymizovat údaje (GDPR)
        </button>
      </div>
    </div>

    <ConfirmDialog
      :show="erasing"
      title="Anonymizovat údaje zákazníka"
      :message="`Opravdu anonymizovat údaje zákazníka ${customer.full_name || customer.email}? Jméno, e-mail, telefon a adresy budou nenávratně odstraněny. Tuto akci nelze vzít zpět.`"
      confirm-label="Anonymizovat"
      danger
      :processing="processing"
      @cancel="erasing = false"
      @confirm="confirmErase"
    />
  </AdminLayout>
</template>
