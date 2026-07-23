<script setup lang="ts">
import { computed, nextTick, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import ConfirmDialog from '@/Components/Ui/ConfirmDialog.vue'

interface CustomDomain {
  domain: string
  ssl_status: 'none' | 'pending' | 'issued' | 'error'
  verified: boolean
  verification_error: string | null
  last_checked_at: string | null
}

interface Instructions {
  txt_host: string
  txt_value: string
  cname_host: string
  cname_value: string
  a_value: string | null
}

const props = defineProps<{
  subdomain: string | null
  custom: CustomDomain | null
  instructions: Instructions | null
}>()

const form = useForm({ domain: '' })

function submit() {
  form.post(route('admin.domain.store'), { preserveScroll: true })
}

const verifying = ref(false)

function verifyNow() {
  verifying.value = true
  router.post(route('admin.domain.verify'), {}, {
    preserveScroll: true,
    onFinish: () => (verifying.value = false),
  })
}

const confirmingRemoval = ref(false)

function removeDomain() {
  router.delete(route('admin.domain.destroy'), {
    preserveScroll: true,
    onFinish: () => (confirmingRemoval.value = false),
  })
}

const status = computed(() => {
  const custom = props.custom

  if (!custom) return null

  if (custom.ssl_status === 'issued') {
    return { label: 'Aktivní (certifikát vydán)', tone: 'success' as const }
  }

  if (custom.ssl_status === 'error') {
    return { label: 'Chyba', tone: 'danger' as const }
  }

  if (custom.verified) {
    return { label: 'Ověřeno, čeká na certifikát', tone: 'info' as const }
  }

  return { label: 'Čeká na DNS', tone: 'warning' as const }
})

const statusClasses: Record<'success' | 'danger' | 'info' | 'warning', string> = {
  success: 'border-emerald-300 bg-emerald-50 text-emerald-900',
  danger: 'border-red-300 bg-red-50 text-red-900',
  info: 'border-blue-300 bg-blue-50 text-blue-900',
  warning: 'border-amber-300 bg-amber-50 text-amber-900',
}

const copyFeedback = ref('')

async function copy(value: string, label: string) {
  // Cleared then re-set on the next tick so the live region's text content
  // actually changes even when the same field is copied twice in a row —
  // an unchanged aria-live node is not guaranteed to be re-announced.
  copyFeedback.value = ''
  await nextTick()

  try {
    await navigator.clipboard.writeText(value)
    copyFeedback.value = `${label} zkopírováno do schránky.`
  } catch {
    // Clipboard API can be unavailable (older browser, insecure context,
    // permission denial) — the value is always visible as plain text
    // regardless, so copying is enhancement only, never the only way.
    copyFeedback.value = `${label} se nepodařilo zkopírovat automaticky, zkopírujte hodnotu ručně.`
  }
}
</script>

<template>
  <AdminLayout title="Vlastní doména">
    <div class="mx-auto max-w-2xl">
      <h1 class="text-lg font-semibold text-gray-900">Vlastní doména</h1>
      <p class="mt-1 text-sm text-gray-600">
        E-shop běží na subdoméně <strong>{{ subdomain }}</strong>. Můžete si nastavit vlastní doménu
        (např. muj-eshop.cz) — subdoména bude fungovat dál, dokud pro vlastní doménu nebude vydaný
        certifikát.
      </p>

      <!-- No custom domain yet: add form. -->
      <form v-if="!custom" class="mt-6 space-y-4" @submit.prevent="submit">
        <div>
          <label for="domain" class="block text-sm font-medium text-gray-700">Doména</label>
          <input
            id="domain"
            v-model="form.domain"
            type="text"
            required
            placeholder="muj-eshop.cz"
            autocomplete="off"
            class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
            :aria-invalid="form.errors.domain ? 'true' : undefined"
            :aria-describedby="form.errors.domain ? 'domain-error' : undefined"
          />
          <p v-if="form.errors.domain" id="domain-error" class="mt-1 text-sm text-red-700">
            {{ form.errors.domain }}
          </p>
        </div>

        <div class="flex justify-end">
          <button
            type="submit"
            :disabled="form.processing"
            class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:bg-gray-400 disabled:text-gray-700"
          >
            Přidat doménu
          </button>
        </div>
      </form>

      <!-- Custom domain exists: status, DNS instructions, actions. -->
      <div v-else class="mt-6 space-y-6">
        <div>
          <p class="text-sm font-medium text-gray-700">Doména</p>
          <p class="text-base text-gray-900">{{ custom.domain }}</p>
        </div>

        <div
          v-if="status"
          class="rounded-md border px-4 py-3 text-sm"
          :class="statusClasses[status.tone]"
          role="status"
        >
          <p class="font-semibold">Stav: {{ status.label }}</p>
          <p v-if="custom.verification_error" class="mt-1">{{ custom.verification_error }}</p>
        </div>

        <div v-if="instructions" class="rounded-md border border-gray-200 p-4">
          <h2 class="text-sm font-semibold text-gray-900">Nastavení DNS</h2>
          <p class="mt-1 text-sm text-gray-600">
            U poskytovatele domény nastavte následující záznamy. Ověření může po nastavení trvat i
            několik hodin (šíření DNS).
          </p>

          <dl class="mt-4 space-y-4 text-sm">
            <div class="rounded-md bg-gray-50 p-3">
              <dt class="font-medium text-gray-700">TXT záznam (ověření vlastnictví)</dt>
              <dd class="mt-1 space-y-1">
                <div class="flex flex-wrap items-center gap-2">
                  <span class="text-gray-600">Název:</span>
                  <code class="rounded bg-white px-2 py-1 font-mono text-gray-900">{{ instructions.txt_host }}</code>
                  <button
                    type="button"
                    aria-label="Kopírovat název TXT záznamu"
                    class="rounded border border-gray-300 px-2 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
                    @click="copy(instructions.txt_host, 'Název TXT záznamu')"
                  >
                    Kopírovat
                  </button>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                  <span class="text-gray-600">Hodnota:</span>
                  <code class="rounded bg-white px-2 py-1 font-mono text-gray-900">{{ instructions.txt_value }}</code>
                  <button
                    type="button"
                    aria-label="Kopírovat hodnotu TXT záznamu"
                    class="rounded border border-gray-300 px-2 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
                    @click="copy(instructions.txt_value, 'Hodnota TXT záznamu')"
                  >
                    Kopírovat
                  </button>
                </div>
              </dd>
            </div>

            <div class="rounded-md bg-gray-50 p-3">
              <dt class="font-medium text-gray-700">CNAME záznam (směrování)</dt>
              <dd class="mt-1 space-y-1">
                <div class="flex flex-wrap items-center gap-2">
                  <span class="text-gray-600">Název:</span>
                  <code class="rounded bg-white px-2 py-1 font-mono text-gray-900">{{ instructions.cname_host }}</code>
                  <button
                    type="button"
                    aria-label="Kopírovat název CNAME záznamu"
                    class="rounded border border-gray-300 px-2 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
                    @click="copy(instructions.cname_host, 'Název CNAME záznamu')"
                  >
                    Kopírovat
                  </button>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                  <span class="text-gray-600">Hodnota:</span>
                  <code class="rounded bg-white px-2 py-1 font-mono text-gray-900">{{ instructions.cname_value }}</code>
                  <button
                    type="button"
                    aria-label="Kopírovat hodnotu CNAME záznamu"
                    class="rounded border border-gray-300 px-2 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
                    @click="copy(instructions.cname_value, 'Hodnota CNAME záznamu')"
                  >
                    Kopírovat
                  </button>
                </div>
              </dd>
            </div>

            <div v-if="instructions.a_value" class="rounded-md bg-gray-50 p-3">
              <dt class="font-medium text-gray-700">
                A záznam (alternativa pro doménu bez www, pokud CNAME nelze nastavit)
              </dt>
              <dd class="mt-1 flex flex-wrap items-center gap-2">
                <span class="text-gray-600">Hodnota:</span>
                <code class="rounded bg-white px-2 py-1 font-mono text-gray-900">{{ instructions.a_value }}</code>
                <button
                  type="button"
                  aria-label="Kopírovat hodnotu A záznamu"
                  class="rounded border border-gray-300 px-2 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
                  @click="copy(instructions.a_value, 'Hodnota A záznamu')"
                >
                  Kopírovat
                </button>
              </dd>
            </div>
          </dl>

          <p v-if="copyFeedback" role="status" aria-live="polite" class="mt-3 text-sm text-gray-700">
            {{ copyFeedback }}
          </p>
        </div>

        <div class="flex flex-wrap justify-between gap-3">
          <button
            type="button"
            :disabled="verifying"
            class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:bg-gray-400 disabled:text-gray-700"
            @click="verifyNow"
          >
            {{ verifying ? 'Ověřuji…' : 'Ověřit teď' }}
          </button>

          <button
            type="button"
            class="rounded-md border border-red-300 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-700 focus-visible:ring-offset-2"
            @click="confirmingRemoval = true"
          >
            Odebrat doménu
          </button>
        </div>
      </div>
    </div>

    <ConfirmDialog
      :show="confirmingRemoval"
      title="Odebrat doménu"
      :message="`Opravdu odebrat doménu ${custom?.domain ?? ''}? E-shop bude nadále dostupný na subdoméně ${subdomain}.`"
      confirm-label="Odebrat"
      danger
      @cancel="confirmingRemoval = false"
      @confirm="removeDomain"
    />
  </AdminLayout>
</template>
