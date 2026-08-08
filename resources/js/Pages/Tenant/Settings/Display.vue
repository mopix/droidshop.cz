<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import SettingsPage from '@/Components/Settings/SettingsPage.vue'
import SettingsGrid from '@/Components/Settings/SettingsGrid.vue'
import SettingsCard from '@/Components/Settings/SettingsCard.vue'
import TextField from '@/Components/Ui/TextField.vue'
import CheckboxField from '@/Components/Ui/CheckboxField.vue'

const props = defineProps<{
  display: {
    hide_empty_categories: boolean
    empty_search_text: string | null
    show_footer_contact: boolean
  }
  defaultEmptySearchText: string
  lock: {
    locked: boolean
    has_password: boolean
  }
}>()

const form = useForm({
  hide_empty_categories: props.display.hide_empty_categories,
  empty_search_text: props.display.empty_search_text ?? '',
  show_footer_contact: props.display.show_footer_contact,
  locked: props.lock.locked,
  lock_password: '',
})

const submit = () => form.patch(route('admin.display.update'), { preserveScroll: true })
</script>

<template>
  <AdminLayout title="Zobrazení">
    <SettingsPage
      title="Zobrazení"
      description="Drobnosti v chování e-shopu, které vidí zákazníci."
    >
      <form class="space-y-6" @submit.prevent="submit">
        <SettingsGrid>
          <SettingsCard legend="Katalog">
            <CheckboxField
              v-model="form.hide_empty_categories"
              label="Skrývat prázdné kategorie"
              hint="Kategorie zmizí z nabídky, jen když v ní ani v žádné její podkategorii není publikovaný produkt."
            />
          </SettingsCard>

          <SettingsCard legend="Vyhledávání">
            <TextField
              v-model="form.empty_search_text"
              label="Text, když hledání nic nenajde"
              :hint="`Prázdné = použije se: „${defaultEmptySearchText}“`"
              :maxlength="255"
              :error="form.errors.empty_search_text"
            />
          </SettingsCard>

          <SettingsCard legend="Patička">
            <CheckboxField
              v-model="form.show_footer_contact"
              label="Zobrazovat kontakty v patičce"
              hint="Vypíše, co máte vyplněné v Nastavení → Kontakty. Nevyplněné údaje se nezobrazí tak jako tak."
            />
          </SettingsCard>

          <SettingsCard legend="Zaheslování e-shopu">
            <CheckboxField
              v-model="form.locked"
              label="Zamknout e-shop heslem"
              hint="Návštěvník uvidí místo e-shopu formulář s heslem. Vy jako přihlášený správce vidíte e-shop dál."
            />

            <TextField
              v-model="form.lock_password"
              label="Heslo"
              type="password"
              autocomplete="new-password"
              :hint="
                lock.has_password
                  ? 'Heslo je uložené. Vyplňte, jen když ho chcete změnit.'
                  : 'Aspoň 4 znaky.'
              "
              :error="form.errors.lock_password"
            />
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
