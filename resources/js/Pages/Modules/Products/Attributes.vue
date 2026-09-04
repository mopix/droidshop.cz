<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import ConfirmDialog from '@/Components/Ui/ConfirmDialog.vue'

type Value = { id: number; value: string; slug: string }
type Attribute = {
  id: number
  code: string
  name: string
  is_filterable: boolean
  values: Value[]
}

defineProps<{ attributes: Attribute[] }>()

const addForm = useForm({ name: '', is_filterable: true })

const submitAdd = () =>
  addForm.post(route('admin.products.attributes.store'), {
    preserveScroll: true,
    onSuccess: () => addForm.reset(),
  })

// One value form per attribute, keyed by id: a single shared form would put
// the text somebody typed under one heading into another.
const valueDrafts = ref<Record<number, string>>({})

const addValue = (attribute: Attribute) => {
  const value = (valueDrafts.value[attribute.id] ?? '').trim()

  if (value === '') return

  router.post(
    route('admin.products.attributes.values.store', attribute.id),
    { value },
    {
      preserveScroll: true,
      onSuccess: () => (valueDrafts.value[attribute.id] = ''),
    },
  )
}

const toggleFilterable = (attribute: Attribute) =>
  router.patch(
    route('admin.products.attributes.update', attribute.id),
    { name: attribute.name, is_filterable: !attribute.is_filterable },
    { preserveScroll: true },
  )

const deletingAttribute = ref<Attribute | null>(null)
const deletingValue = ref<Value | null>(null)

const confirmDeleteAttribute = () => {
  const attribute = deletingAttribute.value

  if (!attribute) return

  router.delete(route('admin.products.attributes.destroy', attribute.id), {
    preserveScroll: true,
    onFinish: () => (deletingAttribute.value = null),
  })
}

const confirmDeleteValue = () => {
  const value = deletingValue.value

  if (!value) return

  router.delete(route('admin.products.attributes.values.destroy', value.id), {
    preserveScroll: true,
    onFinish: () => (deletingValue.value = null),
  })
}
</script>

<template>
  <AdminLayout title="Vlastnosti produktů">
    <template #header>
      <div>
        <h1 class="text-xl font-semibold text-gray-900">Vlastnosti produktů</h1>
        <p class="mt-1 text-sm text-gray-600">
          Barva, umístění, kolekce — podle čeho zákazník filtruje zboží ve výpisu.
        </p>
      </div>
    </template>

    <form class="mb-8 rounded-lg border border-gray-200 bg-white p-4 shadow-sm" @submit.prevent="submitAdd">
      <div class="flex flex-wrap items-end gap-4">
        <div class="grow">
          <label for="attribute-name" class="block text-sm font-medium text-gray-700">Název vlastnosti</label>
          <input
            id="attribute-name"
            v-model="addForm.name"
            type="text"
            required
            placeholder="Barva"
            class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
          />
          <p v-if="addForm.errors.name" class="mt-1 text-sm text-red-700">{{ addForm.errors.name }}</p>
        </div>

        <button
          type="submit"
          :disabled="addForm.processing"
          class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
        >
          Přidat vlastnost
        </button>
      </div>
    </form>

    <p v-if="attributes.length === 0" class="text-sm text-gray-600">
      Zatím žádná vlastnost. Přidejte první — třeba „Barva“.
    </p>

    <ul class="space-y-4">
      <li v-for="attribute in attributes" :key="attribute.id" class="rounded-lg border border-gray-200 bg-white shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 p-4">
          <div>
            <h2 class="font-semibold text-gray-900">{{ attribute.name }}</h2>
            <p class="text-xs text-gray-600">
              Kód <code>{{ attribute.code }}</code> — používá se v adrese filtru a nemění se.
            </p>
          </div>

          <div class="flex items-center gap-3">
            <label class="flex items-center gap-2 text-sm text-gray-700">
              <input
                type="checkbox"
                :checked="attribute.is_filterable"
                class="h-4 w-4 rounded border-gray-300 text-gray-900 focus:ring-gray-900"
                @change="toggleFilterable(attribute)"
              />
              Nabízet ve filtru
            </label>

            <button
              type="button"
              class="rounded-md px-3 py-1.5 text-sm font-medium text-red-800 hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-800"
              @click="deletingAttribute = attribute"
            >
              Smazat
            </button>
          </div>
        </div>

        <div class="p-4">
          <ul class="mb-3 flex flex-wrap gap-2">
            <li
              v-for="value in attribute.values"
              :key="value.id"
              class="flex items-center gap-2 rounded-full border border-gray-300 px-3 py-1 text-sm"
            >
              <span>{{ value.value }}</span>
              <button
                type="button"
                class="text-red-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-red-700"
                @click="deletingValue = value"
              >
                <span aria-hidden="true">×</span>
                <span class="sr-only">Smazat hodnotu {{ value.value }}</span>
              </button>
            </li>
          </ul>

          <div class="flex flex-wrap items-end gap-2">
            <div class="grow">
              <label :for="`value-${attribute.id}`" class="block text-sm font-medium text-gray-700">
                Nová hodnota
              </label>
              <input
                :id="`value-${attribute.id}`"
                v-model="valueDrafts[attribute.id]"
                type="text"
                placeholder="Modrá"
                class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
                @keydown.enter.prevent="addValue(attribute)"
              />
            </div>

            <button
              type="button"
              class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-800 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
              @click="addValue(attribute)"
            >
              Přidat hodnotu
            </button>
          </div>
        </div>
      </li>
    </ul>

    <ConfirmDialog
      :show="deletingAttribute !== null"
      title="Smazat vlastnost"
      :message="`Opravdu smazat vlastnost ${deletingAttribute?.name ?? ''} i s jejími hodnotami? Vlastnost, kterou používá nějaký produkt, smazat nelze.`"
      confirm-label="Smazat"
      danger
      @cancel="deletingAttribute = null"
      @confirm="confirmDeleteAttribute"
    />

    <ConfirmDialog
      :show="deletingValue !== null"
      title="Smazat hodnotu"
      :message="`Opravdu smazat hodnotu ${deletingValue?.value ?? ''}? Hodnotu, kterou používá nějaký produkt, smazat nelze.`"
      confirm-label="Smazat"
      danger
      @cancel="deletingValue = null"
      @confirm="confirmDeleteValue"
    />
  </AdminLayout>
</template>
