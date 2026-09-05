<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import ConfirmDialog from '@/Components/Ui/ConfirmDialog.vue'
import SettingsPage from '@/Components/Settings/SettingsPage.vue'
import SettingsGrid from '@/Components/Settings/SettingsGrid.vue'
import SettingsCard from '@/Components/Settings/SettingsCard.vue'

type ThemeOption = {
  key: string
  name: string
  description: string
  preview: string | null
  tokens: Record<string, string>
}

const props = defineProps<{
  appearance: {
    primary_color: string
    accent_color: string
    logo_url: string | null
    favicon_url: string | null
    contrast_ratio: number
    template: string
  }
  themes: ThemeOption[]
}>()

const form = useForm({
  template: props.appearance.template,
  primary_color: props.appearance.primary_color,
  accent_color: props.appearance.accent_color,
  logo: null as File | null,
  favicon: null as File | null,
})

// The tile draws a small mock from the theme's own tokens rather than showing
// a screenshot. A shop that has not been built yet has nothing to photograph,
// and a stale screenshot of an older version of a theme is worse than none.
function mockStyle(theme: ThemeOption) {
  return {
    backgroundColor: theme.tokens['surface-muted'] ?? '#f8fafc',
    borderColor: theme.tokens.line ?? '#e2e8f0',
    fontFamily: theme.tokens['font-body'] ?? 'inherit',
  }
}

function mockCardStyle(theme: ThemeOption) {
  const card = theme.tokens.card ?? 'elevated'

  return {
    backgroundColor: theme.tokens.surface ?? '#ffffff',
    borderRadius: theme.tokens.radius ?? '0.75rem',
    border: card === 'plain' ? 'none' : `1px solid ${theme.tokens.line ?? '#e2e8f0'}`,
    boxShadow: card === 'elevated' ? '0 1px 2px rgb(0 0 0 / 0.08)' : 'none',
  }
}

function mockHeadingStyle(theme: ThemeOption) {
  return {
    color: theme.tokens.ink ?? '#0f172a',
    fontFamily: theme.tokens['font-heading'] ?? 'inherit',
    fontWeight: theme.tokens['heading-weight'] ?? '600',
    letterSpacing: theme.tokens['heading-tracking'] ?? 'normal',
    textTransform: (theme.tokens['heading-transform'] ?? 'none') as 'none' | 'uppercase',
  }
}

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
    <SettingsPage
      title="Vzhled"
      description="Barvy a logo vašeho e-shopu. Změny se projeví na storefrontu ihned po uložení."
    >
      <template #actions>
        <a
          href="/"
          target="_blank"
          rel="noopener noreferrer"
          class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
        >
          Zobrazit e-shop
          <span class="sr-only">(otevře se v novém okně)</span>
        </a>
      </template>

      <form class="space-y-6" enctype="multipart/form-data" @submit.prevent="submit">
        <SettingsCard legend="Šablona">
          <p class="mb-4 text-sm text-gray-600">
            Šablona určuje rozvržení e-shopu — hlavičku, výpis zboží i detail produktu.
            Barvy níže platí ve všech šablonách.
          </p>

          <div role="radiogroup" aria-label="Šablona storefrontu" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <label
              v-for="theme in themes"
              :key="theme.key"
              class="relative flex cursor-pointer flex-col rounded-lg border-2 p-3 focus-within:ring-2 focus-within:ring-gray-900 focus-within:ring-offset-2"
              :class="
                form.template === theme.key
                  ? 'border-gray-900 bg-gray-50'
                  : 'border-gray-200 hover:border-gray-400'
              "
            >
              <input
                v-model="form.template"
                type="radio"
                name="template"
                :value="theme.key"
                class="sr-only"
              />

              <img
                v-if="theme.preview"
                :src="theme.preview"
                alt=""
                class="mb-3 aspect-[4/3] w-full rounded object-cover"
              />
              <div
                v-else
                aria-hidden="true"
                class="mb-3 flex aspect-[4/3] w-full flex-col gap-2 overflow-hidden rounded border p-3"
                :style="mockStyle(theme)"
              >
                <div class="text-[0.6rem] leading-none" :style="mockHeadingStyle(theme)">NADPIS</div>
                <div class="grid flex-1 grid-cols-3 gap-2">
                  <div v-for="n in 3" :key="n" class="flex flex-col gap-1 p-1" :style="mockCardStyle(theme)">
                    <div class="flex-1 rounded-sm bg-gray-200"></div>
                    <div class="h-1 w-2/3 rounded-sm bg-gray-300"></div>
                  </div>
                </div>
              </div>

              <span class="flex items-center gap-2 text-sm font-semibold text-gray-900">
                {{ theme.name }}
                <span
                  v-if="form.template === theme.key"
                  class="rounded-full bg-gray-900 px-2 py-0.5 text-xs font-medium text-white"
                >
                  Vybráno
                </span>
              </span>
              <span class="mt-1 text-sm text-gray-600">{{ theme.description }}</span>
            </label>
          </div>

          <p v-if="form.errors.template" role="alert" class="mt-2 text-sm text-red-700">
            {{ form.errors.template }}
          </p>
        </SettingsCard>

        <SettingsGrid>
          <SettingsCard legend="Barvy">
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
          </SettingsCard>

          <SettingsCard legend="Logo">

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
          </SettingsCard>

          <SettingsCard legend="Favicon">

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
          </SettingsCard>
        </SettingsGrid>

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

      <section class="border-t border-gray-200 pt-6">
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
    </SettingsPage>

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
