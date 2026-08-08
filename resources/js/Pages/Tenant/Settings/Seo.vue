<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import TextField from '@/Components/Ui/TextField.vue'
import CheckboxField from '@/Components/Ui/CheckboxField.vue'
import ConfirmDialog from '@/Components/Ui/ConfirmDialog.vue'

const props = defineProps<{
  seo: {
    seo_title: string | null
    seo_description: string | null
    noindex: boolean
    og_image_url: string | null
  }
  shopName: string
}>()

const form = useForm({
  seo_title: props.seo.seo_title ?? '',
  seo_description: props.seo.seo_description ?? '',
  noindex: props.seo.noindex,
  og_image: null as File | null,
})

const imageInput = ref<HTMLInputElement | null>(null)
const confirmingRemoval = ref(false)

function onImageChange(event: Event) {
  form.og_image = (event.target as HTMLInputElement).files?.[0] ?? null
}

function submit() {
  // POST with _method spoofing, not router.patch: PHP does not parse a
  // multipart body on a native PATCH, so the whole payload would arrive empty
  // (the trap wave 2.3 hit with homepage block images).
  form
    .transform((data) => ({ ...data, _method: 'patch' }))
    .post(route('admin.seo.update'), {
      forceFormData: true,
      preserveScroll: true,
      onSuccess: () => {
        form.og_image = null
        if (imageInput.value) imageInput.value.value = ''
      },
    })
}

function removeImage() {
  confirmingRemoval.value = false
  router.delete(route('admin.seo.image.destroy'), { preserveScroll: true })
}

/** What a search engine will most likely print. */
const previewTitle = computed(() => form.seo_title.trim() || props.shopName)
const previewDescription = computed(
  () => form.seo_description.trim() || `Nakupujte v e-shopu ${props.shopName}.`,
)
</script>

<template>
  <AdminLayout title="SEO">
    <div class="mx-auto max-w-2xl">
      <h1 class="text-lg font-semibold text-gray-900">Vyhledávače a sdílení</h1>
      <p class="mt-1 text-sm text-gray-600">
        Co o vašem e-shopu uvidí Google a co se ukáže, když někdo sdílí odkaz na sociální síti.
      </p>

      <form class="mt-6 space-y-6" enctype="multipart/form-data" @submit.prevent="submit">
        <fieldset class="space-y-4 rounded-md border border-gray-200 p-4">
          <legend class="px-1 text-sm font-medium text-gray-700">Úvodní stránka</legend>

          <TextField
            v-model="form.seo_title"
            label="Titulek"
            hint="Zhruba 60 znaků. Prázdné = použije se název obchodu."
            :error="form.errors.seo_title"
            :maxlength="255"
          />

          <TextField
            v-model="form.seo_description"
            label="Popis"
            hint="Zhruba 160 znaků. Prázdné = doplní se automaticky."
            :rows="3"
            :maxlength="500"
            :error="form.errors.seo_description"
          />

          <div class="rounded-md border border-gray-200 bg-gray-50 p-3">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-600">Náhled</p>
            <p class="mt-1 truncate text-base text-blue-800">{{ previewTitle }}</p>
            <p class="line-clamp-2 text-sm text-gray-700">{{ previewDescription }}</p>
          </div>
        </fieldset>

        <fieldset class="space-y-4 rounded-md border border-gray-200 p-4">
          <legend class="px-1 text-sm font-medium text-gray-700">Obrázek pro sdílení</legend>

          <div v-if="seo.og_image_url" class="flex flex-wrap items-center gap-4">
            <img
              :src="seo.og_image_url"
              alt="Obrázek pro sdílení"
              class="h-24 w-auto rounded border border-gray-200 bg-white"
            />
            <button
              type="button"
              class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100"
              @click="confirmingRemoval = true"
            >
              Odebrat
            </button>
          </div>

          <div>
            <label for="og-image" class="block text-sm font-medium text-gray-700">
              {{ seo.og_image_url ? 'Nahradit obrázek' : 'Nahrát obrázek' }}
            </label>
            <input
              id="og-image"
              ref="imageInput"
              type="file"
              accept="image/png,image/jpeg,image/webp"
              class="mt-1 block w-full text-sm text-gray-700 file:mr-4 file:rounded-md file:border-0 file:bg-gray-900 file:px-4 file:py-2 file:text-sm file:font-medium file:text-white"
              @change="onImageChange"
            />
            <p class="mt-1 text-sm text-gray-600">
              PNG, JPG nebo WebP, ideálně 1200 × 630 px, max 1 MB.
            </p>
            <p v-if="form.errors.og_image" class="mt-1 text-sm text-red-700">
              {{ form.errors.og_image }}
            </p>
          </div>
        </fieldset>

        <fieldset class="space-y-4 rounded-md border border-gray-200 p-4">
          <legend class="px-1 text-sm font-medium text-gray-700">Indexování</legend>

          <CheckboxField
            v-model="form.noindex"
            label="Nepovolit vyhledávačům indexovat e-shop"
            hint="Vhodné, dokud e-shop připravujete. Zapnuté znamená, že se e-shop nebude objevovat ve výsledcích vyhledávání."
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

    <ConfirmDialog
      :show="confirmingRemoval"
      title="Odebrat obrázek?"
      message="Odkazy sdílené na sociálních sítích se pak zobrazí bez obrázku."
      confirm-label="Odebrat"
      danger
      @confirm="removeImage"
      @cancel="confirmingRemoval = false"
    />
  </AdminLayout>
</template>
