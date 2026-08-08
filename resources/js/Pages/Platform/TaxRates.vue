<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import PlatformLayout from '@/Layouts/PlatformLayout.vue'
import ConfirmDialog from '@/Components/Ui/ConfirmDialog.vue'

type Rate = {
  id: number
  code: string
  name: string
  percent: number
  is_default: boolean
  position: number
  in_use: boolean
}

defineProps<{ rates: Rate[] }>()

const editingId = ref<number | null>(null)
const removing = ref<Rate | null>(null)

const blank = { code: '', name: '', percent: 0, is_default: false, position: 0 }

const form = useForm({ ...blank })

function startNew() {
  editingId.value = null
  form.defaults({ ...blank })
  form.reset()
  form.clearErrors()
}

function startEdit(rate: Rate) {
  editingId.value = rate.id
  form.code = rate.code
  form.name = rate.name
  form.percent = rate.percent
  form.is_default = rate.is_default
  form.position = rate.position
  form.clearErrors()
}

function submit() {
  if (editingId.value === null) {
    form.post(route('platform.tax-rates.store'), { preserveScroll: true, onSuccess: startNew })

    return
  }

  form.patch(route('platform.tax-rates.update', editingId.value), { preserveScroll: true })
}

function remove() {
  const rate = removing.value
  removing.value = null

  if (rate) {
    router.delete(route('platform.tax-rates.destroy', rate.id), { preserveScroll: true })
  }
}

const percent = (value: number) =>
  `${value.toLocaleString('cs-CZ', { maximumFractionDigits: 2 })} %`
</script>

<template>
  <PlatformLayout title="Sazby DPH">
    <template #header>
      <h1 class="text-xl font-semibold text-gray-900">Sazby DPH</h1>
    </template>

    <div class="space-y-6">
      <p class="max-w-3xl text-sm text-gray-600">
        Sazby platí pro všechny e-shopy na platformě — e-shop si u produktu vybírá z tohoto
        seznamu. Zatím jen Česká republika. Změna procenta se projeví na nových objednávkách;
        už vystavené doklady nesou vlastní snímek sazby a nemění se.
      </p>

      <p
        v-if="$page.props.errors.rate"
        role="alert"
        class="rounded-md border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-900"
      >
        {{ $page.props.errors.rate }}
      </p>

      <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
        <table class="w-full text-sm">
          <thead class="border-b border-gray-200 bg-gray-50 text-left text-gray-600">
            <tr>
              <th scope="col" class="px-4 py-3 font-medium">Kód</th>
              <th scope="col" class="px-4 py-3 font-medium">Název</th>
              <th scope="col" class="px-4 py-3 text-right font-medium">Sazba</th>
              <th scope="col" class="px-4 py-3 font-medium">Výchozí</th>
              <th scope="col" class="px-4 py-3 font-medium">Používá se</th>
              <th scope="col" class="px-4 py-3 text-right font-medium">Akce</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200">
            <tr v-for="rate in rates" :key="rate.id">
              <td class="px-4 py-3 font-mono text-gray-900">{{ rate.code }}</td>
              <td class="px-4 py-3 text-gray-900">{{ rate.name }}</td>
              <td class="px-4 py-3 text-right text-gray-900">{{ percent(rate.percent) }}</td>
              <td class="px-4 py-3 text-gray-700">{{ rate.is_default ? 'Ano' : '—' }}</td>
              <td class="px-4 py-3 text-gray-700">{{ rate.in_use ? 'Ano' : 'Ne' }}</td>
              <td class="px-4 py-3 text-right">
                <button
                  type="button"
                  class="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-100"
                  @click="startEdit(rate)"
                >
                  Upravit
                </button>
                <button
                  v-if="!rate.in_use && !rate.is_default"
                  type="button"
                  class="ml-2 rounded-md border border-red-300 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50"
                  @click="removing = rate"
                >
                  Smazat
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <form class="max-w-3xl space-y-4 rounded-lg border border-gray-200 bg-white p-5" @submit.prevent="submit">
        <h2 class="text-base font-semibold text-gray-900">
          {{ editingId === null ? 'Nová sazba' : 'Úprava sazby' }}
        </h2>

        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <label for="rate-code" class="block text-sm font-medium text-gray-700">Kód</label>
            <input
              id="rate-code"
              v-model="form.code"
              type="text"
              required
              maxlength="32"
              class="mt-1 block w-full rounded-md border-gray-300 font-mono shadow-sm focus:border-gray-900 focus:ring-gray-900"
            />
            <p class="mt-1 text-sm text-gray-600">Neměnný identifikátor, např. <code>standard</code>.</p>
            <p v-if="form.errors.code" class="mt-1 text-sm text-red-700">{{ form.errors.code }}</p>
          </div>

          <div>
            <label for="rate-name" class="block text-sm font-medium text-gray-700">Název</label>
            <input
              id="rate-name"
              v-model="form.name"
              type="text"
              required
              maxlength="255"
              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
            />
            <p v-if="form.errors.name" class="mt-1 text-sm text-red-700">{{ form.errors.name }}</p>
          </div>

          <div>
            <label for="rate-percent" class="block text-sm font-medium text-gray-700">Sazba (%)</label>
            <input
              id="rate-percent"
              v-model.number="form.percent"
              type="number"
              step="0.1"
              min="0"
              max="100"
              required
              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
            />
            <p v-if="form.errors.percent" class="mt-1 text-sm text-red-700">{{ form.errors.percent }}</p>
          </div>

          <div>
            <label for="rate-position" class="block text-sm font-medium text-gray-700">Pořadí</label>
            <input
              id="rate-position"
              v-model.number="form.position"
              type="number"
              min="0"
              max="9999"
              required
              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
            />
            <p v-if="form.errors.position" class="mt-1 text-sm text-red-700">{{ form.errors.position }}</p>
          </div>
        </div>

        <div class="flex gap-3">
          <input
            id="rate-default"
            v-model="form.is_default"
            type="checkbox"
            class="mt-1 h-4 w-4 rounded border-gray-300 text-gray-900 focus:ring-gray-900"
          />
          <div>
            <label for="rate-default" class="text-sm font-medium text-gray-700">Výchozí sazba</label>
            <p class="text-sm text-gray-600">Nabídne se u nového produktu. Výchozí je vždy právě jedna.</p>
          </div>
        </div>

        <div class="flex gap-3">
          <button
            type="submit"
            :disabled="form.processing"
            class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:opacity-50"
          >
            {{ editingId === null ? 'Přidat sazbu' : 'Uložit změny' }}
          </button>

          <button
            v-if="editingId !== null"
            type="button"
            class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100"
            @click="startNew"
          >
            Zrušit úpravu
          </button>
        </div>
      </form>
    </div>

    <ConfirmDialog
      :show="removing !== null"
      title="Smazat sazbu"
      :message="`Opravdu smazat sazbu ${removing?.name ?? ''}? Smazat lze jen sazbu, kterou nikdo nepoužívá.`"
      confirm-label="Smazat"
      danger
      @cancel="removing = null"
      @confirm="remove"
    />
  </PlatformLayout>
</template>
