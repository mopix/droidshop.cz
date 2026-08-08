<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import SettingsPage from '@/Components/Settings/SettingsPage.vue'
import SettingsGrid from '@/Components/Settings/SettingsGrid.vue'
import SettingsCard from '@/Components/Settings/SettingsCard.vue'
import TextField from '@/Components/Ui/TextField.vue'

const props = defineProps<{
  shop: {
    name: string
    tagline: string | null
    timezone: string
    date_format: string
    time_format: string
  }
  timezones: { value: string; label: string }[]
  dateFormats: { value: string; label: string }[]
  timeFormats: { value: string; label: string }[]
}>()

const form = useForm({
  name: props.shop.name,
  tagline: props.shop.tagline ?? '',
  timezone: props.shop.timezone,
  date_format: props.shop.date_format,
  time_format: props.shop.time_format,
})

const submit = () => form.patch(route('admin.shop.update'), { preserveScroll: true })
</script>

<template>
  <AdminLayout title="Obchod">
    <SettingsPage
      title="Obchod"
      description="Jak se obchod jmenuje a v jakém čase pracuje. Název a slogan vidí zákazníci v hlavičce e-shopu."
    >
      <form class="space-y-6" @submit.prevent="submit">
        <SettingsGrid>
          <SettingsCard legend="Název">
            <TextField
              v-model="form.name"
              label="Název obchodu"
              :error="form.errors.name"
              required
              :maxlength="255"
            />

            <TextField
              v-model="form.tagline"
              label="Slogan"
              hint="Krátká věta pod názvem. Nechte prázdné, pokud ji nechcete."
              :error="form.errors.tagline"
              :maxlength="255"
              placeholder="Nářadí, které vydrží"
            />
          </SettingsCard>

          <SettingsCard legend="Čas">
            <TextField
              v-model="form.timezone"
              label="Časové pásmo"
              hint="Podle něj se počítají časy objednávek a dokladů."
              :options="timezones"
              :error="form.errors.timezone"
            />

            <div class="grid gap-4 sm:grid-cols-2">
              <TextField
                v-model="form.date_format"
                label="Formát data"
                :options="dateFormats"
                :error="form.errors.date_format"
              />
              <TextField
                v-model="form.time_format"
                label="Formát času"
                :options="timeFormats"
                :error="form.errors.time_format"
              />
            </div>
          </SettingsCard>
        </SettingsGrid>

        <button
          type="submit"
          :disabled="form.processing"
          class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:opacity-50"
        >
          Uložit
        </button>
      </form>
    </SettingsPage>
  </AdminLayout>
</template>
