<script setup lang="ts">
import { ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

type ImportRun = {
  id: number
  original_name: string
  status: string
  dry_run: boolean
  rows_total: number
  rows_ok: number
  rows_failed: number
  has_report: boolean
  created_at: string | null
}

defineProps<{
  imports: ImportRun[]
  columns: string[]
}>()

const fileInput = ref<HTMLInputElement | null>(null)

const form = useForm<{ file: File | null; dry_run: boolean }>({
  file: null,
  dry_run: false,
})

const onFile = (event: Event) => {
  const target = event.target as HTMLInputElement
  form.file = target.files?.[0] ?? null
}

const submit = () =>
  form.post(route('admin.products.import.store'), {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => {
      form.reset('file')
      if (fileInput.value) fileInput.value.value = ''
    },
  })

const STATUS_LABELS: Record<string, string> = {
  pending: 'Čeká',
  running: 'Probíhá',
  done: 'Hotovo',
  failed: 'Selhalo',
}
</script>

<template>
  <AdminLayout title="Import a export produktů">
    <div class="mx-auto max-w-4xl space-y-8 p-6">
      <header class="flex items-start justify-between gap-4">
        <div>
          <h1 class="text-2xl font-semibold text-gray-900">Import a export produktů</h1>
          <p class="mt-1 text-sm text-gray-600">
            Stáhni si katalog, uprav ho v tabulkovém editoru a nahraj zpět. Řádek se stejným SKU
            produkt aktualizuje, prázdné SKU zakládá nový.
          </p>
        </div>

        <a
          :href="route('admin.products.export')"
          class="shrink-0 rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800"
        >
          Stáhnout katalog (CSV)
        </a>
      </header>

      <section class="rounded-lg border border-gray-200 bg-white p-6">
        <h2 class="text-lg font-medium text-gray-900">Nahrát soubor</h2>

        <form class="mt-4 space-y-4" @submit.prevent="submit">
          <div>
            <label for="import-file" class="block text-sm font-medium text-gray-700">Soubor CSV</label>
            <input
              id="import-file"
              ref="fileInput"
              type="file"
              accept=".csv,text/csv,text/plain"
              class="mt-1 w-full text-sm"
              aria-describedby="import-file-hint"
              :aria-invalid="form.errors.file ? 'true' : undefined"
              @change="onFile"
            />
            <p id="import-file-hint" class="mt-1 text-sm text-gray-600">
              Oddělovač středník, kódování UTF-8, ceny v korunách s desetinnou čárkou.
            </p>
            <p v-if="form.errors.file" class="mt-1 text-sm text-red-700" role="alert">
              {{ form.errors.file }}
            </p>
          </div>

          <div class="flex items-start gap-2">
            <input id="import-dry-run" v-model="form.dry_run" type="checkbox" class="mt-1" />
            <label for="import-dry-run" class="text-sm text-gray-700">
              Jen zkontrolovat — nic se neuloží, dostaneš stejný protokol jako u ostrého běhu.
            </label>
          </div>

          <button
            type="submit"
            class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:opacity-50"
            :disabled="form.processing || !form.file"
          >
            Spustit import
          </button>
        </form>
      </section>

      <section class="rounded-lg border border-gray-200 bg-white p-6">
        <h2 class="text-lg font-medium text-gray-900">Poslední běhy</h2>

        <p v-if="imports.length === 0" class="mt-2 text-sm text-gray-600">Zatím žádný import neproběhl.</p>

        <table v-else class="mt-4 w-full text-left text-sm">
          <caption class="sr-only">Historie importů</caption>
          <thead>
            <tr class="text-gray-500">
              <th scope="col" class="py-2 pr-2 font-medium">Soubor</th>
              <th scope="col" class="px-2 py-2 font-medium">Kdy</th>
              <th scope="col" class="px-2 py-2 font-medium">Stav</th>
              <th scope="col" class="px-2 py-2 font-medium">Řádky</th>
              <th scope="col" class="py-2 pl-2 font-medium">Protokol</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="run in imports" :key="run.id" class="border-t border-gray-100">
              <th scope="row" class="py-2 pr-2 text-left font-normal text-gray-900">
                {{ run.original_name }}
                <span v-if="run.dry_run" class="ml-1 text-xs text-gray-500">(jen kontrola)</span>
              </th>
              <td class="px-2 py-2 text-gray-600">{{ run.created_at }}</td>
              <td class="px-2 py-2 text-gray-600">{{ STATUS_LABELS[run.status] ?? run.status }}</td>
              <td class="px-2 py-2 text-gray-600">
                {{ run.rows_ok }} z {{ run.rows_total }}
                <span v-if="run.rows_failed > 0" class="text-red-700">
                  · {{ run.rows_failed }} s chybou
                </span>
              </td>
              <td class="py-2 pl-2">
                <a
                  v-if="run.has_report"
                  :href="route('admin.products.import.report', run.id)"
                  class="text-gray-900 underline"
                >
                  Stáhnout chyby
                </a>
                <span v-else class="text-gray-400">—</span>
              </td>
            </tr>
          </tbody>
        </table>
      </section>

      <section class="rounded-lg border border-gray-200 bg-white p-6">
        <h2 class="text-lg font-medium text-gray-900">Sloupce souboru</h2>
        <p class="mt-1 text-sm text-gray-600">
          Na pořadí nezáleží, rozhoduje název v hlavičce. Prázdná buňka u existujícího produktu
          znamená „neměnit".
        </p>
        <p class="mt-3 font-mono text-xs text-gray-700">{{ columns.join(' · ') }}</p>
      </section>

      <p class="text-sm">
        <Link :href="route('admin.products.index')" class="text-gray-900 underline">Zpět na produkty</Link>
      </p>
    </div>
  </AdminLayout>
</template>
