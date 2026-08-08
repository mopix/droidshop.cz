<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import SettingsPage from '@/Components/Settings/SettingsPage.vue'
import SettingsCard from '@/Components/Settings/SettingsCard.vue'

type Field = {
  key: string
  label: string
  type: 'boolean' | 'select' | 'textarea' | 'number' | 'text' | 'password'
  help: string | null
  options: Record<string, string>
  secret: boolean
}

const props = defineProps<{
  module: { key: string; name: string }
  fields: Field[]
  values: Record<string, unknown>
}>()

// The form mirrors the schema, not whatever the server happened to have stored:
// a key with no row yet still needs a slot, or v-model would write onto a
// property Inertia never sends.
const form = useForm({
  values: Object.fromEntries(
    props.fields.map((field) => [field.key, props.values[field.key] ?? defaultFor(field)]),
  ) as Record<string, unknown>,
})

function defaultFor(field: Field): unknown {
  if (field.type === 'boolean') return false
  if (field.type === 'select') return Object.keys(field.options)[0] ?? ''

  return ''
}

function errorFor(key: string): string | undefined {
  return form.errors[`values.${key}` as keyof typeof form.errors] as string | undefined
}

// A stored credential never comes back from the server — the screen only
// learns that one exists, so an empty box means 'unchanged', not 'erase'.
function hasStoredSecret(field: Field): boolean {
  return field.secret && props.values[`${field.key}_stored`] === true
}

function describedBy(field: Field): string | undefined {
  const ids = [errorFor(field.key) ? `${field.key}-error` : null, field.help ? `${field.key}-help` : null]

  return ids.filter(Boolean).join(' ') || undefined
}

function inputTypeFor(field: Field): string {
  if (field.type === 'password') return 'password'
  if (field.type === 'number') return 'number'

  return 'text'
}

function submit() {
  form.patch(route('admin.settings.modules.update', props.module.key), { preserveScroll: true })
}
</script>

<template>
  <AdminLayout :title="`Nastavení — ${module.name}`">
    <SettingsPage
      :title="`Nastavení — ${module.name}`"
      description="Chování modulu ve vašem e-shopu. Změny platí ihned po uložení."
    >
      <template #actions>
        <Link
          :href="route('admin.settings.modules.index')"
          class="text-sm font-medium text-gray-600 underline hover:no-underline"
        >
          Zpět na nastavení modulů
        </Link>
      </template>

      <p v-if="form.errors.values" class="rounded-md border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-900">
        {{ form.errors.values }}
      </p>

      <form class="space-y-6" @submit.prevent="submit">
        <SettingsCard :legend="module.name">
          <!--
            Two columns from `sm` up. A module rarely has more than a handful
            of settings, and a single column of inputs stretched across a
            full-width admin is a very long line to aim at.
          -->
          <div class="grid gap-4 sm:grid-cols-2">
          <div v-for="field in fields" :key="field.key">
          <template v-if="field.type === 'boolean'">
            <div class="flex items-start gap-2">
              <input
                :id="field.key"
                v-model="form.values[field.key]"
                type="checkbox"
                class="mt-1 h-4 w-4 rounded border-gray-300 text-gray-900 focus:ring-gray-900"
                :aria-invalid="errorFor(field.key) ? 'true' : undefined"
                :aria-describedby="describedBy(field)"
              />
              <label :for="field.key" class="text-sm font-medium text-gray-700">{{ field.label }}</label>
            </div>
          </template>

          <template v-else>
            <label :for="field.key" class="block text-sm font-medium text-gray-700">{{ field.label }}</label>

            <select
              v-if="field.type === 'select'"
              :id="field.key"
              v-model="form.values[field.key]"
              class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
              :aria-invalid="errorFor(field.key) ? 'true' : undefined"
              :aria-describedby="describedBy(field)"
            >
              <option v-for="(label, value) in field.options" :key="value" :value="value">{{ label }}</option>
            </select>

            <textarea
              v-else-if="field.type === 'textarea'"
              :id="field.key"
              v-model="form.values[field.key]"
              rows="4"
              class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
              :aria-invalid="errorFor(field.key) ? 'true' : undefined"
              :aria-describedby="describedBy(field)"
            />

            <input
              v-else
              :id="field.key"
              v-model="form.values[field.key]"
              :type="inputTypeFor(field)"
              class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
              :aria-invalid="errorFor(field.key) ? 'true' : undefined"
              :aria-describedby="describedBy(field)"
            />
          </template>

          <p v-if="hasStoredSecret(field)" class="mt-1 text-sm text-gray-600">
            Uloženo. Ponechte prázdné, pokud nechcete měnit.
          </p>
          <p v-if="field.help" :id="`${field.key}-help`" class="mt-1 text-sm text-gray-600">{{ field.help }}</p>
          <p v-if="errorFor(field.key)" :id="`${field.key}-error`" class="mt-1 text-sm text-red-700">
            {{ errorFor(field.key) }}
          </p>
          </div>
          </div>
        </SettingsCard>

        <button
          type="submit"
          :disabled="form.processing"
          class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 disabled:opacity-50"
        >
          Uložit
        </button>
      </form>
    </SettingsPage>
  </AdminLayout>
</template>
