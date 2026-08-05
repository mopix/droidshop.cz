<script setup lang="ts">
import { computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

type Page = {
  id: number
  slug: string
  title: string
  body: string | null
  is_published: boolean
  seo_title: string | null
  seo_description: string | null
}

const props = defineProps<{ page: Page | null }>()

const isEdit = computed(() => props.page !== null)

const form = useForm({
  title: props.page?.title ?? '',
  slug: props.page?.slug ?? '',
  body: props.page?.body ?? '',
  is_published: props.page?.is_published ?? false,
  seo_title: props.page?.seo_title ?? '',
  seo_description: props.page?.seo_description ?? '',
})

const submit = () => {
  if (props.page) {
    form.put(route('admin.pages.update', props.page.id))
    return
  }

  form.post(route('admin.pages.store'))
}

// Warns before publishing a template that still carries its placeholders.
// Not a hard block: a tenant may have a legitimate reason to publish work in
// progress, and an admin screen that refuses to save reads as a bug.
const hasPlaceholders = computed(() => form.body.includes('[DOPLŇTE'))
</script>

<template>
  <AdminLayout :title="isEdit ? 'Upravit stránku' : 'Nová stránka'">
    <template #header>
      <h1 class="text-xl font-semibold text-gray-900">
        {{ isEdit ? 'Upravit stránku' : 'Nová stránka' }}
      </h1>
    </template>

    <form class="max-w-3xl space-y-6" @submit.prevent="submit">
      <div>
        <label for="title" class="block text-sm font-medium text-gray-700">Název</label>
        <input
          id="title"
          v-model="form.title"
          type="text"
          required
          class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
        />
        <p v-if="form.errors.title" class="mt-1 text-sm text-red-600">{{ form.errors.title }}</p>
      </div>

      <div>
        <label for="slug" class="block text-sm font-medium text-gray-700">Adresa stránky</label>
        <div class="mt-1 flex items-center gap-1">
          <span class="text-sm text-gray-500">/</span>
          <input
            id="slug"
            v-model="form.slug"
            type="text"
            required
            class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
          />
        </div>
        <p class="mt-1 text-sm text-gray-500">
          Změníte-li adresu, stará bude trvale přesměrovaná na novou.
        </p>
        <p v-if="form.errors.slug" class="mt-1 text-sm text-red-600">{{ form.errors.slug }}</p>
      </div>

      <div>
        <label for="body" class="block text-sm font-medium text-gray-700">Obsah</label>
        <textarea
          id="body"
          v-model="form.body"
          rows="24"
          class="mt-1 block w-full rounded-md border-gray-300 font-mono text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
        ></textarea>
        <p class="mt-1 text-sm text-gray-500">
          Můžete použít HTML značky <code>&lt;h2&gt;</code>, <code>&lt;p&gt;</code>,
          <code>&lt;strong&gt;</code>, <code>&lt;ul&gt;</code>, <code>&lt;li&gt;</code> a
          <code>&lt;a&gt;</code>. Ostatní se při uložení odstraní.
        </p>
        <p v-if="form.errors.body" class="mt-1 text-sm text-red-600">{{ form.errors.body }}</p>
      </div>

      <div class="rounded-md border border-gray-200 p-4">
        <p class="text-sm font-medium text-gray-700">Vyhledávače</p>

        <div class="mt-3">
          <label for="seo_title" class="block text-sm text-gray-700">SEO titulek</label>
          <input
            id="seo_title"
            v-model="form.seo_title"
            type="text"
            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
          />
        </div>

        <div class="mt-3">
          <label for="seo_description" class="block text-sm text-gray-700">SEO popis</label>
          <textarea
            id="seo_description"
            v-model="form.seo_description"
            rows="2"
            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
          ></textarea>
        </div>
      </div>

      <div>
        <label class="flex items-start gap-2 text-sm text-gray-700">
          <input
            v-model="form.is_published"
            type="checkbox"
            class="mt-1 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
          />
          <span>Publikovat — stránka bude veřejně dostupná na e-shopu</span>
        </label>

        <p
          v-if="form.is_published && hasPlaceholders"
          class="mt-2 rounded-md bg-amber-50 p-3 text-sm text-amber-800"
        >
          V obsahu jsou ještě nedoplněné části označené <strong>[DOPLŇTE …]</strong>. Zveřejněná
          stránka je bude ukazovat vašim zákazníkům.
        </p>
      </div>

      <div class="flex items-center gap-3">
        <button
          type="submit"
          :disabled="form.processing"
          class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
        >
          Uložit
        </button>

        <Link
          :href="route('admin.pages.index')"
          class="text-sm text-gray-600 underline hover:text-gray-900"
        >
          Zpět na seznam
        </Link>
      </div>
    </form>
  </AdminLayout>
</template>
