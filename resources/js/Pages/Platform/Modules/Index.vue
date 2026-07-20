<script setup lang="ts">
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import PlatformLayout from '@/Layouts/PlatformLayout.vue'
import DataTable, { type Column } from '@/Components/Ui/DataTable.vue'
import ConfirmDialog from '@/Components/Ui/ConfirmDialog.vue'

type ModuleRow = {
  key: string
  name: string
  version: string | null
  core: boolean
  level: 'base' | 'premium' | string
  enabled_globally: boolean
  tenants: number
}

defineProps<{
  modules: ModuleRow[]
}>()

const columns: Column[] = [
  { key: 'name', label: 'Název' },
  { key: 'key', label: 'Klíč' },
  { key: 'version', label: 'Verze' },
  { key: 'core', label: 'Typ' },
  { key: 'level', label: 'Úroveň tarifu' },
  { key: 'tenants', label: 'E-shopů', align: 'right' },
  { key: 'enabled_globally', label: 'Globální stav' },
  { key: 'actions', label: 'Kill switch' },
]

const levelLabels: Record<string, string> = {
  base: 'Základní',
  premium: 'Premium',
}

const levelLabel = (level: string): string => levelLabels[level] ?? level

const form = useForm<{ enabled: boolean; reason: string }>({ enabled: false, reason: '' })

// The module the dialog is about, plus the direction of the flip.
const target = ref<ModuleRow | null>(null)
const targetEnabled = ref(false)

const open = (module: ModuleRow, enabled: boolean) => {
  form.clearErrors()
  target.value = module
  targetEnabled.value = enabled
}

const close = () => {
  target.value = null
}

const dialogTitle = computed(() =>
  targetEnabled.value ? 'Vrátit modul do provozu' : 'Stáhnout modul z provozu',
)

/**
 * The number of affected shops is the whole point of the confirmation: the
 * kill switch overrides per-tenant activation and, for a core module, the
 * core status too.
 */
const dialogMessage = computed(() => {
  const module = target.value

  if (!module) return ''

  if (targetEnabled.value) {
    return `Modul „${module.name}“ bude opět dostupný všem e-shopům, které ho mají zapnutý.`
  }

  const shops = `Dotkne se ${module.tenants} e-shopů, které modul aktuálně používají.`

  return module.core
    ? `Modul „${module.name}“ je součástí jádra. Kill switch přebíjí i jádro — dotčené e-shopy přijdou o základní funkčnost. ${shops}`
    : `Modul „${module.name}“ přestane fungovat na celé platformě. ${shops}`
})

const submit = (url: string, reason: string) => {
  form.enabled = targetEnabled.value
  form.reason = targetEnabled.value ? '' : reason

  form.patch(url, {
    preserveScroll: true,
    onSuccess: close,
  })
}
</script>

<template>
  <PlatformLayout title="Moduly">
    <template #header>
      <h1 class="text-xl font-semibold text-gray-900">Moduly</h1>
      <p class="mt-1 text-sm text-gray-600">
        Nasazené moduly a jejich dostupnost na celé platformě.
      </p>
    </template>

    <DataTable
      :columns="columns"
      :rows="modules"
      row-key="key"
      caption="Nasazené moduly, jejich úroveň tarifu, počet e-shopů a globální stav"
    >
      <template #cell-name="{ row }">
        <span class="font-medium text-gray-900">{{ row.name }}</span>
      </template>

      <template #cell-key="{ row }">
        <code class="font-mono text-xs text-gray-800">{{ row.key }}</code>
      </template>

      <template #cell-version="{ row }">{{ row.version ?? '—' }}</template>

      <template #cell-core="{ row }">{{ row.core ? 'Jádro' : 'Volitelný' }}</template>

      <template #cell-level="{ row }">{{ levelLabel(row.level) }}</template>

      <template #cell-enabled_globally="{ row }">
        <span
          class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset"
          :class="
            row.enabled_globally
              ? 'bg-emerald-50 text-emerald-900 ring-emerald-700/40'
              : 'bg-red-50 text-red-900 ring-red-700/40'
          "
        >
          <span class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true" />
          {{ row.enabled_globally ? 'V provozu' : 'Staženo z provozu' }}
        </span>
      </template>

      <template #cell-actions="{ row }">
        <button
          v-if="row.enabled_globally"
          type="button"
          class="inline-flex items-center rounded-md border border-red-300 bg-white px-3 py-1.5 text-sm font-medium text-red-800 hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-700 focus-visible:ring-offset-2"
          @click="open(row, false)"
        >
          Stáhnout z provozu<span class="sr-only"> modul {{ row.name }}</span>
        </button>

        <button
          v-else
          type="button"
          class="inline-flex items-center rounded-md border border-transparent bg-slate-900 px-3 py-1.5 text-sm font-semibold text-white hover:bg-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
          @click="open(row, true)"
        >
          Vrátit do provozu<span class="sr-only"> modul {{ row.name }}</span>
        </button>
      </template>

      <template #empty>Žádné nasazené moduly.</template>
    </DataTable>

    <ConfirmDialog
      :show="target !== null"
      :title="dialogTitle"
      :message="dialogMessage"
      :confirm-label="targetEnabled ? 'Vrátit do provozu' : 'Stáhnout z provozu'"
      :danger="!targetEnabled"
      :require-reason="!targetEnabled"
      reason-label="Důvod stažení"
      :reason-error="form.errors.reason"
      :processing="form.processing"
      @cancel="close"
      @confirm="
        (reason) => target && submit(route('platform.modules.global-state', target.key), reason)
      "
    />
  </PlatformLayout>
</template>
