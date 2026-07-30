<script setup lang="ts">
import { computed, ref } from 'vue'
import { usePage } from '@inertiajs/vue3'
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

// The export form posts nothing through useForm (it is a native GET, so the
// response can be a file), which means the shared `errors` bag is the only
// place a refusal arrives — the document cap and a reversed date range both
// redirect back with one. Rendered here because AdminLayout shows flash
// messages only, so until now both failures bounced the nájemce back to an
// unchanged screen with no explanation (final review, wave 2.11).
const page = usePage()
const errors = computed(() => (page.props.errors as Record<string, string> | undefined) ?? {})
const errorList = computed(() => Object.values(errors.value).filter(Boolean))
</script>

<template>
  <AdminLayout title="Účetní export">
    <div class="mx-auto max-w-2xl">
      <h1 class="text-lg font-semibold text-gray-900">Účetní export</h1>
      <p class="mt-1 text-sm text-gray-600">
        Doklady za období ve formátu, který naimportuje účetní program. Exportují se faktury
        a dobropisy podle data uskutečnění plnění, nejvýše {{ maxDocuments }} dokladů na jeden export.
      </p>

      <!-- role="alert" so the refusal is announced the moment the page comes
           back, not only if the nájemce happens to look at the top of the form.
           Each message is also repeated next to its own field below, where one
           exists, so the association is programmatic and not merely visual. -->
      <div
        v-if="errorList.length > 0"
        role="alert"
        class="mt-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-900 ring-1 ring-inset ring-red-700/40"
      >
        <p class="font-semibold">Export se nepodařilo vytvořit</p>
        <ul class="mt-1 list-inside list-disc">
          <li v-for="(message, key) in errors" :key="key">{{ message }}</li>
        </ul>
      </div>

      <!-- A plain GET form: the response is a file, not an Inertia page, so
           router.get() would leave the visitor on a blank Inertia response. -->
      <form method="GET" :action="route('admin.accounting.export')" class="mt-6 space-y-4">
        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <label for="from" class="block text-sm font-medium text-gray-700">Od</label>
            <input id="from" v-model="from" name="from" type="date" required
              :aria-describedby="errors.from ? 'from-error' : undefined"
              :aria-invalid="errors.from ? 'true' : undefined"
              class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900" />
            <p v-if="errors.from" id="from-error" class="mt-1 text-sm text-red-700">{{ errors.from }}</p>
          </div>
          <div>
            <label for="to" class="block text-sm font-medium text-gray-700">Do</label>
            <input id="to" v-model="to" name="to" type="date" required
              :aria-describedby="errors.to ? 'to-error' : undefined"
              :aria-invalid="errors.to ? 'true' : undefined"
              class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900" />
            <p v-if="errors.to" id="to-error" class="mt-1 text-sm text-red-700">{{ errors.to }}</p>
          </div>
        </div>

        <div>
          <label for="format" class="block text-sm font-medium text-gray-700">Formát</label>
          <select id="format" v-model="format" name="format"
            :aria-describedby="errors.format ? 'format-error' : undefined"
            :aria-invalid="errors.format ? 'true' : undefined"
            class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900">
            <option v-for="option in formats" :key="option.key" :value="option.key">{{ option.label }}</option>
          </select>
          <p v-if="errors.format" id="format-error" class="mt-1 text-sm text-red-700">{{ errors.format }}</p>
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
