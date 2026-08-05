<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import ConfirmDialog from '@/Components/Ui/ConfirmDialog.vue'

const props = defineProps<{
  appearance: {
    primary_color: string
    accent_color: string
    logo_url: string | null
    favicon_url: string | null
    contrast_ratio: number
  }
}>()

const form = useForm({
  primary_color: props.appearance.primary_color,
  accent_color: props.appearance.accent_color,
  logo: null as File | null,
  favicon: null as File | null,
})

const logoInput = ref<HTMLInputElement | null>(null)
const faviconInput = ref<HTMLInputElement | null>(null)

function onLogoChange(event: Event) {
  form.logo = (event.target as HTMLInputElement).files?.[0] ?? null
}

function onFaviconChange(event: Event) {
  form.favicon = (event.target as HTMLInputElement).files?.[0] ?? null
}

function submit() {
  form.post(route('admin.appearance.update'), {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => {
      form.logo = null
      form.favicon = null
      if (logoInput.value) logoInput.value.value = ''
      if (faviconInput.value) faviconInput.value.value = ''
    },
  })
}

// Same WCAG relative-luminance formula as the server's App\Core\Theme\Contrast,
// recomputed client-side so the warning reacts as the color is being edited —
// the contrast_ratio prop only reflects the color that was saved last.
const HEX_PATTERN = /^#[0-9a-fA-F]{6}$/

function luminance(hex: string): number {
  const value = hex.slice(1)
  const channel = (offset: number) => parseInt(value.slice(offset, offset + 2), 16) / 255
  const linearize = (c: number) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4)

  return (
    0.2126 * linearize(channel(0)) +
    0.7152 * linearize(channel(2)) +
    0.0722 * linearize(channel(4))
  )
}

function contrastRatio(hexA: string, hexB: string): number {
  const a = luminance(hexA)
  const b = luminance(hexB)
  const lighter = Math.max(a, b)
  const darker = Math.min(a, b)

  return (lighter + 0.05) / (darker + 0.05)
}

const primaryContrast = computed(() => {
  if (!HEX_PATTERN.test(form.primary_color)) return props.appearance.contrast_ratio

  return Math.round(contrastRatio(form.primary_color, '#ffffff') * 100) / 100
})

const isLowContrast = computed(() => primaryContrast.value < 4.5)

const confirmingLogoRemoval = ref(false)
const confirmingFaviconRemoval = ref(false)
const removingLogo = ref(false)
const removingFavicon = ref(false)

function removeLogo() {
  removingLogo.value = true
  router.delete(route('admin.appearance.logo.destroy'), {
    preserveScroll: true,
    onFinish: () => {
      removingLogo.value = false
      confirmingLogoRemoval.value = false
    },
  })
}

function removeFavicon() {
  removingFavicon.value = true
  router.delete(route('admin.appearance.favicon.destroy'), {
    preserveScroll: true,
    onFinish: () => {
      removingFavicon.value = false
      confirmingFaviconRemoval.value = false
    },
  })
}

const logoDescribedBy = computed(() =>
  [form.errors.logo ? 'logo-error' : null, 'logo-hint'].filter(Boolean).join(' '),
)
const faviconDescribedBy = computed(() =>
  [form.errors.favicon ? 'favicon-error' : null, 'favicon-hint'].filter(Boolean).join(' '),
)

// Reversible, non-destructive (nothing is lost — the cache just rebuilds on
// the next request), so unlike logo/favicon removal this does not get a
// confirmation dialog.
const flushingCache = ref(false)

function flushCache() {
  flushingCache.value = true
  router.post(
    route('admin.appearance.cache.flush'),
    {},
    {
      preserveScroll: true,
      onFinish: () => {
        flushingCache.value = false
      },
    },
  )
}
</script>

<template>
  <AdminLayout title="Vzhled">
    <div class="mx-auto max-w-2xl">
      <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 class="text-lg font-semibold text-gray-900">Vzhled</h1>
          <p class="mt-1 text-sm text-gray-600">
            Barvy a logo vašeho e-shopu. Změny se projeví na storefrontu ihned po uložení.
          </p>
        </div>

        <a
          href="/"
          target="_blank"
          rel="noopener noreferrer"
          class="shrink-0 rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
        >
          Zobrazit e-shop
          <span class="sr-only">(otevře se v novém okně)</span>
        </a>
      </div>

      <form class="mt-6 space-y-6" enctype="multipart/form-data" @submit.prevent="submit">
        <fieldset class="rounded-md border border-gray-200 p-4">
          <legend class="px-1 text-sm font-medium text-gray-700">Barvy</legend>

          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label for="primary-color-hex" class="block text-sm font-medium text-gray-700">
                Primární barva
              </label>
              <div class="mt-1 flex items-center gap-2">
                <input
                  id="primary-color-picker"
                  v-model="form.primary_color"
                  type="color"
                  aria-label="Vybrat primární barvu z palety"
                  class="h-10 w-14 shrink-0 cursor-pointer rounded border border-gray-300 p-1"
                />
                <input
                  id="primary-color-hex"
                  v-model="form.primary_color"
                  type="text"
                  required
                  maxlength="7"
                  pattern="#[0-9a-fA-F]{6}"
                  placeholder="#0f172a"
                  class="w-full rounded-md border-gray-300 font-mono shadow-sm focus:border-gray-900 focus:ring-gray-900"
                  :aria-invalid="form.errors.primary_color ? 'true' : undefined"
                  :aria-describedby="form.errors.primary_color ? 'primary-color-error' : undefined"
                />
              </div>
              <p v-if="form.errors.primary_color" id="primary-color-error" class="mt-1 text-sm text-red-700">
                {{ form.errors.primary_color }}
              </p>
            </div>

            <div>
              <label for="accent-color-hex" class="block text-sm font-medium text-gray-700">
                Akcentní barva
              </label>
              <div class="mt-1 flex items-center gap-2">
                <input
                  id="accent-color-picker"
                  v-model="form.accent_color"
                  type="color"
                  aria-label="Vybrat akcentní barvu z palety"
                  class="h-10 w-14 shrink-0 cursor-pointer rounded border border-gray-300 p-1"
                />
                <input
                  id="accent-color-hex"
                  v-model="form.accent_color"
                  type="text"
                  required
                  maxlength="7"
                  pattern="#[0-9a-fA-F]{6}"
                  placeholder="#2563eb"
                  class="w-full rounded-md border-gray-300 font-mono shadow-sm focus:border-gray-900 focus:ring-gray-900"
                  :aria-invalid="form.errors.accent_color ? 'true' : undefined"
                  :aria-describedby="form.errors.accent_color ? 'accent-color-error' : undefined"
                />
              </div>
              <p v-if="form.errors.accent_color" id="accent-color-error" class="mt-1 text-sm text-red-700">
                {{ form.errors.accent_color }}
              </p>
            </div>
          </div>

          <p
            v-if="isLowContrast"
            role="alert"
            class="mt-4 rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900"
          >
            <strong>Nízký kontrast.</strong> Text na tlačítkách může být špatně čitelný (poměr
            {{ primaryContrast.toFixed(2) }}:1 vůči bílé, doporučeno alespoň 4.5:1).
          </p>
        </fieldset>

        <fieldset class="rounded-md border border-gray-200 p-4">
          <legend class="px-1 text-sm font-medium text-gray-700">Logo</legend>

          <div v-if="appearance.logo_url" class="flex items-center gap-4">
            <img
              :src="appearance.logo_url"
              alt="Aktuální logo e-shopu"
              class="h-16 w-auto rounded border border-gray-200 bg-white p-2"
            />
            <button
              type="button"
              class="rounded-md border border-red-300 px-3 py-1.5 text-sm font-semibold text-red-700 hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-700 focus-visible:ring-offset-2"
              @click="confirmingLogoRemoval = true"
            >
              Odebrat logo
            </button>
          </div>

          <div class="mt-3">
            <label for="logo-input" class="block text-sm font-medium text-gray-700">
              Nahrát nové logo
            </label>
            <input
              id="logo-input"
              ref="logoInput"
              type="file"
              accept="image/png,image/jpeg,image/webp"
              class="mt-1 block w-full text-sm text-gray-700 file:mr-4 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-gray-700 hover:file:bg-gray-200"
              :aria-invalid="form.errors.logo ? 'true' : undefined"
              :aria-describedby="logoDescribedBy"
              @change="onLogoChange"
            />
            <p id="logo-hint" class="mt-1 text-sm text-gray-600">Obrázek, max 512 kB.</p>
            <p v-if="form.errors.logo" id="logo-error" class="mt-1 text-sm text-red-700">
              {{ form.errors.logo }}
            </p>
          </div>
        </fieldset>

        <fieldset class="rounded-md border border-gray-200 p-4">
          <legend class="px-1 text-sm font-medium text-gray-700">Favicon</legend>

          <div v-if="appearance.favicon_url" class="flex items-center gap-4">
            <img
              :src="appearance.favicon_url"
              alt="Aktuální favicon e-shopu"
              class="h-8 w-8 rounded border border-gray-200 bg-white p-1"
            />
            <button
              type="button"
              class="rounded-md border border-red-300 px-3 py-1.5 text-sm font-semibold text-red-700 hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-700 focus-visible:ring-offset-2"
              @click="confirmingFaviconRemoval = true"
            >
              Odebrat favicon
            </button>
          </div>

          <div class="mt-3">
            <label for="favicon-input" class="block text-sm font-medium text-gray-700">
              Nahrát nový favicon
            </label>
            <input
              id="favicon-input"
              ref="faviconInput"
              type="file"
              accept="image/png,image/x-icon"
              class="mt-1 block w-full text-sm text-gray-700 file:mr-4 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-gray-700 hover:file:bg-gray-200"
              :aria-invalid="form.errors.favicon ? 'true' : undefined"
              :aria-describedby="faviconDescribedBy"
              @change="onFaviconChange"
            />
            <p id="favicon-hint" class="mt-1 text-sm text-gray-600">Obrázek, max 128 kB.</p>
            <p v-if="form.errors.favicon" id="favicon-error" class="mt-1 text-sm text-red-700">
              {{ form.errors.favicon }}
            </p>
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

      <section class="mt-8 border-t border-gray-200 pt-6">
        <h2 class="text-base font-semibold text-gray-900">Cache e-shopu</h2>
        <p class="mt-1 text-sm text-gray-600">
          Storefront se zobrazuje z uložené podoby, aby byl rychlý. Změny se projeví samy;
          tohle tlačítko je pro případ, kdy vidíte zastaralý obsah a nechcete čekat.
        </p>
        <button
          type="button"
          :disabled="flushingCache"
          class="mt-3 rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:text-gray-400"
          @click="flushCache"
        >
          Vymazat cache e-shopu
        </button>
      </section>
    </div>

    <ConfirmDialog
      :show="confirmingLogoRemoval"
      title="Odebrat logo"
      message="Opravdu odebrat logo? Na storefrontu se místo něj zobrazí název e-shopu."
      confirm-label="Odebrat"
      danger
      :processing="removingLogo"
      @cancel="confirmingLogoRemoval = false"
      @confirm="removeLogo"
    />

    <ConfirmDialog
      :show="confirmingFaviconRemoval"
      title="Odebrat favicon"
      message="Opravdu odebrat favicon? Prohlížeč použije výchozí ikonu."
      confirm-label="Odebrat"
      danger
      :processing="removingFavicon"
      @cancel="confirmingFaviconRemoval = false"
      @confirm="removeFavicon"
    />
  </AdminLayout>
</template>
