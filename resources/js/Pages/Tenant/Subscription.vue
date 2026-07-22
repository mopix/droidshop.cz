<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

const props = defineProps<{
  status: string
  statusLabel: string
  planName: string | null
  priceMonth: number | null
  paidThrough: string | null
  hasSubscription: boolean
  billingProfileComplete: boolean
}>()

const processing = ref(false)

const money = (h: number) => (h / 100).toLocaleString('cs-CZ', { style: 'currency', currency: 'CZK' })

function post(url: string) {
  processing.value = true
  router.post(url, {}, { onFinish: () => (processing.value = false) })
}
</script>

<template>
  <AdminLayout title="Předplatné">
    <div class="mx-auto max-w-2xl">
      <h1 class="text-lg font-semibold text-gray-900">Předplatné</h1>
      <p class="mt-1 text-sm text-gray-600">Stav vašeho předplatného platformy DroidShop.cz.</p>

      <dl class="mt-6 space-y-3 rounded-md border border-gray-200 p-4 text-sm">
        <div class="flex justify-between gap-4">
          <dt class="text-gray-600">Stav</dt>
          <dd class="font-medium text-gray-900">{{ props.statusLabel }}</dd>
        </div>
        <div class="flex justify-between gap-4">
          <dt class="text-gray-600">Tarif</dt>
          <dd class="font-medium text-gray-900">{{ props.planName ?? '—' }}</dd>
        </div>
        <div class="flex justify-between gap-4">
          <dt class="text-gray-600">Cena / měsíc</dt>
          <dd class="font-medium text-gray-900">
            {{ props.priceMonth !== null ? money(props.priceMonth) : '—' }}
          </dd>
        </div>
        <div class="flex justify-between gap-4">
          <dt class="text-gray-600">Placeno do</dt>
          <dd class="font-medium text-gray-900">{{ props.paidThrough ?? '—' }}</dd>
        </div>
      </dl>

      <p
        v-if="!props.billingProfileComplete"
        role="status"
        class="mt-4 rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900"
      >
        Před aktivací předplatného vyplňte
        <a href="/admin/nastaveni/fakturace" class="font-semibold underline hover:no-underline">
          fakturační údaje
        </a>
        .
      </p>

      <div class="mt-6 flex justify-end">
        <button
          v-if="!props.hasSubscription"
          type="button"
          :disabled="!props.billingProfileComplete || processing"
          class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:bg-gray-400 disabled:text-gray-700"
          @click="post('/admin/predplatne/checkout')"
        >
          Aktivovat předplatné
        </button>
        <button
          v-else
          type="button"
          :disabled="processing"
          class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 disabled:cursor-not-allowed disabled:opacity-50"
          @click="post('/admin/predplatne/portal')"
        >
          Spravovat předplatné
        </button>
      </div>
    </div>
  </AdminLayout>
</template>
