<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import PlatformLayout from '@/Layouts/PlatformLayout.vue'
import ConfirmDialog from '@/Components/Ui/ConfirmDialog.vue'

type ModuleRow = {
  key: string
  name: string
  level: string
  core: boolean
  enabled_globally: boolean
}

type Impact = {
  tenants: number
  activate: Record<string, string[]>
  deactivate: Record<string, string[]>
}

const props = defineProps<{
  plan: { id: number; key: string; name: string; level: string; tenants: number }
  modules: ModuleRow[]
  selected: string[]
}>()

const form = useForm<{ keys: string[]; reason: string }>({
  keys: [...props.selected],
  reason: '',
})

// Core modules run everywhere and are never in plan_modules — listed for
// orientation, but with no checkbox to imply otherwise.
const grantable = computed(() => props.modules.filter((module) => !module.core))
const coreModules = computed(() => props.modules.filter((module) => module.core))

const levelLabels: Record<string, string> = { base: 'Základní', premium: 'Premium' }
const levelLabel = (level: string): string => levelLabels[level] ?? level

const impact = ref<Impact | null>(null)
const loadingImpact = ref(false)
const impactError = ref<string | null>(null)
const confirming = ref(false)

const changed = computed(() => {
  const before = [...props.selected].sort().join('|')
  const after = [...form.keys].sort().join('|')

  return before !== after
})

const removesModules = computed(() => Object.keys(impact.value?.deactivate ?? {}).length > 0)

const countedKeys = (rows: Record<string, string[]>): string[] =>
  [...new Set(Object.values(rows).flat())].sort()

/**
 * The impact is read from the server, not guessed here: what a shop actually
 * loses depends on what it runs today, and only the server knows that.
 */
async function loadImpact() {
  loadingImpact.value = true
  impactError.value = null

  try {
    const query = form.keys.map((key) => `keys[]=${encodeURIComponent(key)}`).join('&')
    const response = await fetch(`${route('platform.plans.impact', props.plan.id)}?${query || 'keys[]='}`, {
      headers: { Accept: 'application/json' },
    })

    if (!response.ok) throw new Error(String(response.status))

    impact.value = (await response.json()) as Impact
  } catch {
    impactError.value = 'Dopad se nepodařilo spočítat. Zkuste to prosím znovu.'
  } finally {
    loadingImpact.value = false
  }
}

function submit(reason: string) {
  form.reason = reason
  form.patch(route('platform.plans.modules', props.plan.id), {
    preserveScroll: true,
    onSuccess: () => {
      confirming.value = false
      impact.value = null
    },
  })
}
</script>

<template>
  <PlatformLayout :title="`Tarif ${plan.name}`">
    <Link :href="route('platform.plans.index')" class="text-sm font-medium text-gray-600 underline hover:no-underline">
      Zpět na tarify
    </Link>

    <h1 class="mt-2 text-lg font-semibold text-gray-900">Tarif {{ plan.name }}</h1>
    <p class="mt-1 text-sm text-gray-600">
      Tarif provozuje {{ plan.tenants }} e-shopů. Uložení složení tarifu zapne nebo vypne moduly i jim.
    </p>

    <p v-if="form.errors.keys" class="mt-4 rounded-md border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-900">
      {{ form.errors.keys }}
    </p>

    <fieldset class="mt-6 rounded-md border border-gray-200 p-4">
      <legend class="px-1 text-sm font-medium text-gray-700">Moduly v tarifu</legend>

      <ul class="mt-2 space-y-2">
        <li v-for="module in grantable" :key="module.key" class="flex items-start gap-2">
          <input
            :id="`module-${module.key}`"
            v-model="form.keys"
            type="checkbox"
            :value="module.key"
            class="mt-1 h-4 w-4 rounded border-gray-300 text-gray-900 focus:ring-gray-900"
          />
          <label :for="`module-${module.key}`" class="text-sm text-gray-800">
            {{ module.name }}
            <span class="text-gray-500">({{ module.key }}, {{ levelLabel(module.level) }})</span>
            <span v-if="!module.enabled_globally" class="ml-1 text-amber-700">
              — stažen z provozu, nezapne se
            </span>
          </label>
        </li>
      </ul>
    </fieldset>

    <p v-if="coreModules.length" class="mt-3 text-sm text-gray-600">
      Moduly jádra běží vždy a do tarifu se nepřiřazují:
      {{ coreModules.map((module) => module.name).join(', ') }}.
    </p>

    <div class="mt-6 flex flex-wrap items-center gap-3">
      <button
        type="button"
        :disabled="loadingImpact"
        class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 disabled:opacity-50"
        @click="loadImpact"
      >
        Spočítat dopad
      </button>

      <button
        type="button"
        :disabled="!changed || form.processing"
        class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 disabled:opacity-50"
        @click="confirming = true"
      >
        Uložit složení tarifu
      </button>
    </div>

    <p v-if="impactError" role="alert" class="mt-4 text-sm text-red-700">{{ impactError }}</p>

    <div v-if="impact" role="status" class="mt-4 rounded-md border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-800">
      <p>Dotkne se {{ impact.tenants }} e-shopů.</p>
      <p v-if="countedKeys(impact.activate).length" class="mt-1">
        Zapne: {{ countedKeys(impact.activate).join(', ') }}
      </p>
      <p v-if="countedKeys(impact.deactivate).length" class="mt-1 text-amber-800">
        Vypne: {{ countedKeys(impact.deactivate).join(', ') }}
      </p>
      <p v-if="!countedKeys(impact.activate).length && !countedKeys(impact.deactivate).length" class="mt-1">
        Žádný běžící e-shop se nezmění.
      </p>
    </div>

    <ConfirmDialog
      :show="confirming"
      title="Uložit složení tarifu"
      :message="`Změna se propíše do ${plan.tenants} e-shopů na tomto tarifu. Odebrané moduly se jim vypnou.`"
      confirm-label="Uložit"
      :danger="removesModules"
      :require-reason="removesModules"
      reason-label="Důvod odebrání modulu"
      :reason-error="form.errors.reason"
      :processing="form.processing"
      @cancel="confirming = false"
      @confirm="(reason) => submit(reason)"
    />
  </PlatformLayout>
</template>
