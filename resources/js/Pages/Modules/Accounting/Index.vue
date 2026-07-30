<script setup lang="ts">
import { ref } from 'vue'
import AdminLayout from '@/Layouts/AdminLayout.vue'

const props = defineProps<{
  formats: { key: string; label: string }[]
  maxDocuments: number
}>()

const today = new Date()
const firstOfMonth = new Date(today.getFullYear(), today.getMonth(), 1)
const pad = (n: number) => String(n).padStart(2, '0')
const iso = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`

const from = ref(iso(firstOfMonth))
const to = ref(iso(today))
const format = ref(props.formats[0]?.key ?? 'pohoda')
</script>

<template>
  <AdminLayout title="Účetní export">
    <div class="mx-auto max-w-2xl">
      <h1 class="text-lg font-semibold text-gray-900">Účetní export</h1>
      <p class="mt-1 text-sm text-gray-600">
        Doklady za období ve formátu, který naimportuje účetní program. Exportují se faktury
        a dobropisy podle data uskutečnění plnění, nejvýše {{ maxDocuments }} dokladů na jeden export.
      </p>

      <!-- A plain GET form: the response is a file, not an Inertia page, so
           router.get() would leave the visitor on a blank Inertia response. -->
      <form method="GET" :action="route('admin.accounting.export')" class="mt-6 space-y-4">
        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <label for="from" class="block text-sm font-medium text-gray-700">Od</label>
            <input id="from" v-model="from" name="from" type="date" required
              class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900" />
          </div>
          <div>
            <label for="to" class="block text-sm font-medium text-gray-700">Do</label>
            <input id="to" v-model="to" name="to" type="date" required
              class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900" />
          </div>
        </div>

        <div>
          <label for="format" class="block text-sm font-medium text-gray-700">Formát</label>
          <select id="format" v-model="format" name="format"
            class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900">
            <option v-for="option in formats" :key="option.key" :value="option.key">{{ option.label }}</option>
          </select>
        </div>

        <button type="submit"
          class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900">
          Stáhnout export
        </button>
      </form>

      <p class="mt-6 text-sm text-gray-600">
        Předkontace a členění DPH pro Pohodu nastavíte v
        <a :href="route('admin.settings.modules.edit', 'accounting')" class="underline hover:no-underline">
          nastavení modulu</a>.
      </p>
    </div>
  </AdminLayout>
</template>
