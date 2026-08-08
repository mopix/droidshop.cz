<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import TextField from '@/Components/Ui/TextField.vue'

const props = defineProps<{
  contacts: Record<string, string | null>
}>()

const form = useForm({
  contact_email: props.contacts.contact_email ?? '',
  contact_phone: props.contacts.contact_phone ?? '',
  contact_street: props.contacts.contact_street ?? '',
  contact_city: props.contacts.contact_city ?? '',
  contact_zip: props.contacts.contact_zip ?? '',
  contact_country: props.contacts.contact_country ?? '',
  opening_hours: props.contacts.opening_hours ?? '',
  facebook_url: props.contacts.facebook_url ?? '',
  instagram_url: props.contacts.instagram_url ?? '',
  x_url: props.contacts.x_url ?? '',
  youtube_url: props.contacts.youtube_url ?? '',
  tiktok_url: props.contacts.tiktok_url ?? '',
})

const submit = () => form.patch(route('admin.contacts.update'), { preserveScroll: true })
</script>

<template>
  <AdminLayout title="Kontakty">
    <div class="mx-auto max-w-2xl">
      <h1 class="text-lg font-semibold text-gray-900">Kontakty</h1>
      <p class="mt-1 text-sm text-gray-600">
        Kam se vám může zákazník ozvat. Co vyplníte, objeví se v patičce e-shopu — co necháte
        prázdné, se nezobrazí.
      </p>

      <form class="mt-6 space-y-6" @submit.prevent="submit">
        <fieldset class="space-y-4 rounded-md border border-gray-200 p-4">
          <legend class="px-1 text-sm font-medium text-gray-700">Spojení</legend>

          <TextField
            v-model="form.contact_email"
            label="E-mail"
            type="email"
            :error="form.errors.contact_email"
            placeholder="info@vasobchod.cz"
          />
          <TextField
            v-model="form.contact_phone"
            label="Telefon"
            type="tel"
            :error="form.errors.contact_phone"
            placeholder="+420 777 123 456"
          />
          <TextField
            v-model="form.opening_hours"
            label="Otevírací doba"
            hint="Volný text, například „Po–Pá 9–17“."
            :error="form.errors.opening_hours"
          />
        </fieldset>

        <fieldset class="space-y-4 rounded-md border border-gray-200 p-4">
          <legend class="px-1 text-sm font-medium text-gray-700">Adresa</legend>

          <TextField
            v-model="form.contact_street"
            label="Ulice a číslo"
            :error="form.errors.contact_street"
          />
          <div class="grid gap-4 sm:grid-cols-3">
            <TextField v-model="form.contact_zip" label="PSČ" :error="form.errors.contact_zip" />
            <TextField v-model="form.contact_city" label="Město" :error="form.errors.contact_city" />
            <TextField
              v-model="form.contact_country"
              label="Země"
              hint="Dvě písmena, např. CZ."
              :maxlength="2"
              :error="form.errors.contact_country"
            />
          </div>
        </fieldset>

        <fieldset class="space-y-4 rounded-md border border-gray-200 p-4">
          <legend class="px-1 text-sm font-medium text-gray-700">Sociální sítě</legend>

          <TextField
            v-model="form.facebook_url"
            label="Facebook"
            type="url"
            placeholder="https://facebook.com/vasobchod"
            :error="form.errors.facebook_url"
          />
          <TextField
            v-model="form.instagram_url"
            label="Instagram"
            type="url"
            :error="form.errors.instagram_url"
          />
          <TextField v-model="form.x_url" label="X" type="url" :error="form.errors.x_url" />
          <TextField
            v-model="form.youtube_url"
            label="YouTube"
            type="url"
            :error="form.errors.youtube_url"
          />
          <TextField
            v-model="form.tiktok_url"
            label="TikTok"
            type="url"
            :error="form.errors.tiktok_url"
          />
        </fieldset>

        <button
          type="submit"
          :disabled="form.processing"
          class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:opacity-50"
        >
          Uložit
        </button>
      </form>
    </div>
  </AdminLayout>
</template>
