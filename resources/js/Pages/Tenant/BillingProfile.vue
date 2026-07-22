<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

interface Address {
  street: string
  city: string
  zip: string
}

const props = defineProps<{
  profile: {
    billing_name: string | null
    billing_ico: string | null
    billing_dic: string | null
    vat_payer: boolean
    billing_address: Address
  }
}>()

const form = useForm({
  billing_name: props.profile.billing_name ?? '',
  billing_ico: props.profile.billing_ico ?? '',
  billing_dic: props.profile.billing_dic ?? '',
  vat_payer: props.profile.vat_payer,
  billing_address: { ...props.profile.billing_address },
})

function submit() {
  form.patch('/admin/nastaveni/fakturace', { preserveScroll: true })
}
</script>

<template>
  <AdminLayout title="Fakturační údaje">
    <div class="mx-auto max-w-xl">
      <h1 class="text-lg font-semibold text-gray-900">Fakturační údaje</h1>
      <p class="mt-1 text-sm text-gray-600">
        Tyto údaje se použijí jako dodavatel na vystavených dokladech (faktura, dobropis, zálohová
        faktura).
      </p>

      <form class="mt-6 space-y-4" @submit.prevent="submit">
        <div>
          <label for="billing-name" class="block text-sm font-medium text-gray-700">Název / jméno</label>
          <input
            id="billing-name"
            v-model="form.billing_name"
            type="text"
            required
            maxlength="255"
            class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
            :aria-invalid="form.errors.billing_name ? 'true' : undefined"
            :aria-describedby="form.errors.billing_name ? 'billing-name-error' : undefined"
          />
          <p v-if="form.errors.billing_name" id="billing-name-error" class="mt-1 text-sm text-red-700">
            {{ form.errors.billing_name }}
          </p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <label for="billing-ico" class="block text-sm font-medium text-gray-700">IČO</label>
            <input
              id="billing-ico"
              v-model="form.billing_ico"
              type="text"
              maxlength="16"
              class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
              :aria-invalid="form.errors.billing_ico ? 'true' : undefined"
            />
            <p v-if="form.errors.billing_ico" class="mt-1 text-sm text-red-700">
              {{ form.errors.billing_ico }}
            </p>
          </div>

          <div>
            <label for="billing-dic" class="block text-sm font-medium text-gray-700">DIČ</label>
            <input
              id="billing-dic"
              v-model="form.billing_dic"
              type="text"
              maxlength="16"
              class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
              :aria-invalid="form.errors.billing_dic ? 'true' : undefined"
            />
            <p v-if="form.errors.billing_dic" class="mt-1 text-sm text-red-700">
              {{ form.errors.billing_dic }}
            </p>
          </div>
        </div>

        <label class="flex items-center gap-2 text-sm text-gray-800">
          <input
            v-model="form.vat_payer"
            type="checkbox"
            class="rounded border-gray-300 text-gray-900 focus:ring-gray-900"
          />
          Jsem plátce DPH
        </label>

        <fieldset class="rounded-md border border-gray-200 p-4">
          <legend class="px-1 text-sm font-medium text-gray-700">Fakturační adresa</legend>

          <div class="space-y-3">
            <div>
              <label for="billing-street" class="block text-sm font-medium text-gray-700">
                Ulice a č.p.
              </label>
              <input
                id="billing-street"
                v-model="form.billing_address.street"
                type="text"
                required
                maxlength="255"
                class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
                :aria-invalid="form.errors['billing_address.street'] ? 'true' : undefined"
              />
              <p v-if="form.errors['billing_address.street']" class="mt-1 text-sm text-red-700">
                {{ form.errors['billing_address.street'] }}
              </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
              <div>
                <label for="billing-city" class="block text-sm font-medium text-gray-700">Město</label>
                <input
                  id="billing-city"
                  v-model="form.billing_address.city"
                  type="text"
                  required
                  maxlength="255"
                  class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
                  :aria-invalid="form.errors['billing_address.city'] ? 'true' : undefined"
                />
                <p v-if="form.errors['billing_address.city']" class="mt-1 text-sm text-red-700">
                  {{ form.errors['billing_address.city'] }}
                </p>
              </div>

              <div>
                <label for="billing-zip" class="block text-sm font-medium text-gray-700">PSČ</label>
                <input
                  id="billing-zip"
                  v-model="form.billing_address.zip"
                  type="text"
                  required
                  maxlength="16"
                  class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
                  :aria-invalid="form.errors['billing_address.zip'] ? 'true' : undefined"
                />
                <p v-if="form.errors['billing_address.zip']" class="mt-1 text-sm text-red-700">
                  {{ form.errors['billing_address.zip'] }}
                </p>
              </div>
            </div>
          </div>
        </fieldset>

        <div class="flex justify-end">
          <button
            type="submit"
            :disabled="form.processing"
            class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:bg-gray-400 disabled:text-gray-700"
          >
            Uložit
          </button>
        </div>
      </form>
    </div>
  </AdminLayout>
</template>
