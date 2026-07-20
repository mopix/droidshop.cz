<script setup lang="ts">
import { computed, ref } from 'vue'
import axios from 'axios'
import { Link, router, useForm, usePage } from '@inertiajs/vue3'
import PlatformLayout from '@/Layouts/PlatformLayout.vue'
import ConfirmDialog from '@/Components/Platform/ConfirmDialog.vue'
import DataTable, { type Column } from '@/Components/Platform/DataTable.vue'
import StatusBadge from '@/Components/Platform/StatusBadge.vue'
import InputError from '@/Components/InputError.vue'
import InputLabel from '@/Components/InputLabel.vue'

type Tenant = {
  /** Numeric key — impersonation addresses the tenant by id, not uuid. */
  id: number
  uuid: string
  name: string
  status: string
  status_label: string
  plan_id: number | null
  plan: { id: number; key: string; name: string } | null
  trial_ends_at: string | null
  suspended_at: string | null
  deletion_requested_at: string | null
  billing_name: string | null
  billing_ico: string | null
  billing_dic: string | null
  country: string | null
  currency: string | null
  created_at: string | null
  allows_storefront: boolean
}

type TenantUser = {
  id: number
  name: string
  email: string
  role: string
  joined_at: string | null
}

type TenantModule = {
  key: string
  name: string
  version: string | null
  core: boolean
  enabled: boolean
  enabled_globally: boolean
  in_plan: boolean
}

type LimitUsage = {
  key: string
  used: number
  cap: number | null
  outcome: 'allow' | 'warn' | 'block'
}

const props = defineProps<{
  tenant: Tenant
  domains: { domain: string; type: string; is_primary: boolean; ssl_status: string | null }[]
  users: TenantUser[]
  modules: TenantModule[]
  limits: LimitUsage[]
  audit: { action: string; meta: Record<string, unknown> | null; ip: string | null; created_at: string | null }[]
  statuses: { value: string; label: string }[]
  plans: { id: number; key: string; name: string }[]
}>()

/* ------------------------------------------------------------------ helpers */

const formatDateTime = (value: string | null): string =>
  value ? new Date(value.replace(' ', 'T')).toLocaleString('cs-CZ') : '—'

const statusLabel = (value: string): string =>
  props.statuses.find((option) => option.value === value)?.label ?? value

/**
 * Mirrors TenantStatus::allowedTransitions(). Kept in sync by hand on purpose:
 * offering an impossible transition would only produce a server-side 422, and
 * the server stays the authority either way.
 */
const transitions: Record<string, string[]> = {
  trial: ['active', 'suspended', 'pending_deletion'],
  active: ['past_due', 'suspended', 'pending_deletion'],
  past_due: ['active', 'suspended', 'pending_deletion'],
  suspended: ['active', 'pending_deletion'],
  pending_deletion: ['suspended'],
  deleted: [],
}

/** Statuses whose consequences the audit trail has to explain (requiresReason()). */
const reasonRequiredFor = ['suspended', 'pending_deletion']

const limitLabels: Record<string, string> = {
  products: 'Produkty',
  storage_mb: 'Úložiště (MB)',
  emails_month: 'E-maily za měsíc',
}

const limitLabel = (key: string): string => limitLabels[key] ?? key

/* ------------------------------------------------------------- status change */

const statusOptions = computed(() =>
  (transitions[props.tenant.status] ?? []).map((value) => ({
    value,
    label: statusLabel(value),
  })),
)

const statusForm = useForm<{ status: string; reason: string }>({ status: '', reason: '' })
const statusDialog = ref(false)

const openStatusDialog = () => {
  statusForm.clearErrors()
  statusForm.status = statusOptions.value[0]?.value ?? ''
  statusDialog.value = true
}

const statusNeedsReason = computed(() => reasonRequiredFor.includes(statusForm.status))

const statusWarning = computed(() => {
  if (statusForm.status === 'suspended') {
    return 'Pozastavením se okamžitě vypne veřejný e-shop. Zákazníci nenakoupí, admin zůstane jen pro čtení.'
  }

  if (statusForm.status === 'pending_deletion') {
    return 'Spustíte odpočet ke smazání e-shopu. Storefront zhasne ihned, data se po uplynutí lhůty smažou.'
  }

  return ''
})

const submitStatus = (url: string, reason: string) => {
  statusForm.reason = reason

  statusForm.patch(url, {
    preserveScroll: true,
    onSuccess: () => {
      statusDialog.value = false
    },
  })
}

/* --------------------------------------------------------------- plan change */

const planForm = useForm<{ plan_id: number | null }>({ plan_id: props.tenant.plan_id })
const planSelection = ref<string>(props.tenant.plan_id === null ? '' : String(props.tenant.plan_id))
const planDialog = ref(false)
const planImpact = ref<string[]>([])
const planImpactLoading = ref(false)
const planImpactError = ref('')

const selectedPlanId = computed<number | null>(() =>
  planSelection.value === '' ? null : Number(planSelection.value),
)

const selectedPlanName = computed(
  () => props.plans.find((plan) => plan.id === selectedPlanId.value)?.name ?? 'bez tarifu',
)

/**
 * Ask the server what the change would cost before showing the confirmation:
 * a downgrade silently switching modules off is exactly the surprise this
 * screen exists to prevent.
 */
const openPlanDialog = async (impactUrl: string) => {
  planForm.clearErrors()
  planImpact.value = []
  planImpactError.value = ''
  planImpactLoading.value = true
  planDialog.value = true

  try {
    const { data } = await axios.get(impactUrl, {
      params: { plan_id: selectedPlanId.value ?? '' },
    })

    planImpact.value = (data?.modules_lost ?? []) as string[]
  } catch {
    planImpactError.value = 'Náhled dopadu se nepodařilo načíst. Změna tarifu může vypnout moduly.'
  } finally {
    planImpactLoading.value = false
  }
}

const submitPlan = (url: string) => {
  planForm.plan_id = selectedPlanId.value

  planForm.patch(url, {
    preserveScroll: true,
    onSuccess: () => {
      planDialog.value = false
    },
  })
}

/* ------------------------------------------------------------------- modules */

const moduleForm = useForm<{ module: string }>({ module: '' })
const moduleToDisable = ref<TenantModule | null>(null)

const enableModule = (url: string, module: TenantModule) => {
  moduleForm.module = module.key
  moduleForm.post(url, { preserveScroll: true })
}

const disableModule = (url: string) => {
  router.delete(url, {
    preserveScroll: true,
    onFinish: () => {
      moduleToDisable.value = null
    },
  })
}

// Activation errors arrive on moduleForm, deactivation ones (router.delete)
// land in the shared error bag — both come from ModuleRegistry's refusals.
const page = usePage()

const moduleError = computed(
  () =>
    moduleForm.errors.module ??
    ((page.props.errors as Record<string, string> | undefined)?.module ?? ''),
)

const moduleBlockedReason = (module: TenantModule): string => {
  if (!module.enabled_globally) return 'Modul je stažen z provozu na celé platformě.'
  if (!module.in_plan) return 'Modul není součástí tarifu tohoto e-shopu.'

  return ''
}

/* --------------------------------------------------------------- impersonate */

const impersonationTarget = ref<TenantUser | null>(null)
const impersonating = ref(false)

const impersonationWarning = computed(() => {
  if (props.tenant.allows_storefront) return ''

  return `E-shop je ve stavu „${props.tenant.status_label}" — storefront je vypnutý a admin nepřijímá zápisy. Přihlášení proběhne, ale uvidíte jen omezené rozhraní.`
})

/**
 * The controller answers with Inertia::location() pointing at the tenant's own
 * domain, so Inertia hands the URL to the browser instead of trying to follow a
 * cross-origin redirect over XHR.
 */
const impersonate = () => {
  const user = impersonationTarget.value

  if (!user) return

  impersonating.value = true

  router.post(
    route('platform.impersonate'),
    { tenant_id: props.tenant.id, user_id: user.id },
    {
      onFinish: () => {
        impersonating.value = false
      },
    },
  )
}

/* -------------------------------------------------------------------- tables */

const domainColumns: Column[] = [
  { key: 'domain', label: 'Doména' },
  { key: 'type', label: 'Typ' },
  { key: 'is_primary', label: 'Hlavní' },
  { key: 'ssl_status', label: 'SSL' },
]

const userColumns: Column[] = [
  { key: 'name', label: 'Jméno' },
  { key: 'email', label: 'E-mail' },
  { key: 'role', label: 'Role' },
  { key: 'joined_at', label: 'Přidán' },
  { key: 'actions', label: 'Akce' },
]

const auditColumns: Column[] = [
  { key: 'created_at', label: 'Kdy' },
  { key: 'action', label: 'Akce' },
  { key: 'meta', label: 'Detail' },
  { key: 'ip', label: 'IP' },
]

const metaText = (meta: Record<string, unknown> | null): string =>
  meta && Object.keys(meta).length > 0 ? JSON.stringify(meta) : '—'

/* --------------------------------------------------------------------- limits */

const limitPercent = (limit: LimitUsage): number => {
  if (!limit.cap) return 0

  return Math.min(100, Math.round((limit.used / limit.cap) * 100))
}

const limitBarClass = (limit: LimitUsage): string =>
  ({
    allow: 'bg-emerald-600',
    warn: 'bg-amber-500',
    block: 'bg-red-600',
  })[limit.outcome]

const limitStateLabel = (limit: LimitUsage): string =>
  ({
    allow: 'V pořádku',
    warn: 'Blíží se limitu',
    block: 'Limit vyčerpán',
  })[limit.outcome]
</script>

<template>
  <PlatformLayout :title="tenant.name">
    <template #header>
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
          <p class="text-sm text-gray-600">
            <Link
              :href="route('platform.tenants.index')"
              class="underline underline-offset-2 hover:text-gray-900"
            >
              Tenanti
            </Link>
          </p>
          <h1 class="mt-1 text-xl font-semibold text-gray-900">{{ tenant.name }}</h1>
        </div>

        <StatusBadge :status="tenant.status" :label="tenant.status_label" />
      </div>
    </template>

    <div class="grid gap-6 lg:grid-cols-2">
      <!-- Basics -->
      <section
        class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm"
        aria-labelledby="basics-heading"
      >
        <h2 id="basics-heading" class="text-base font-semibold text-gray-900">Základní údaje</h2>

        <dl class="mt-4 grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
          <div>
            <dt class="text-gray-600">Název</dt>
            <dd class="font-medium text-gray-900">{{ tenant.name }}</dd>
          </div>
          <div>
            <dt class="text-gray-600">UUID</dt>
            <dd class="font-mono text-xs text-gray-900">{{ tenant.uuid }}</dd>
          </div>
          <div>
            <dt class="text-gray-600">Stav</dt>
            <dd class="mt-0.5">
              <StatusBadge :status="tenant.status" :label="tenant.status_label" />
            </dd>
          </div>
          <div>
            <dt class="text-gray-600">Tarif</dt>
            <dd class="font-medium text-gray-900">{{ tenant.plan?.name ?? 'Bez tarifu' }}</dd>
          </div>
          <div>
            <dt class="text-gray-600">Fakturační jméno</dt>
            <dd class="text-gray-900">{{ tenant.billing_name ?? '—' }}</dd>
          </div>
          <div>
            <dt class="text-gray-600">IČO / DIČ</dt>
            <dd class="text-gray-900">
              {{ tenant.billing_ico ?? '—' }} / {{ tenant.billing_dic ?? '—' }}
            </dd>
          </div>
          <div>
            <dt class="text-gray-600">Země / měna</dt>
            <dd class="text-gray-900">{{ tenant.country ?? '—' }} / {{ tenant.currency ?? '—' }}</dd>
          </div>
          <div>
            <dt class="text-gray-600">Založeno</dt>
            <dd class="text-gray-900">{{ formatDateTime(tenant.created_at) }}</dd>
          </div>
          <div>
            <dt class="text-gray-600">Konec zkušebního období</dt>
            <dd class="text-gray-900">{{ formatDateTime(tenant.trial_ends_at) }}</dd>
          </div>
          <div>
            <dt class="text-gray-600">Pozastaveno</dt>
            <dd class="text-gray-900">{{ formatDateTime(tenant.suspended_at) }}</dd>
          </div>
          <div>
            <dt class="text-gray-600">Žádost o smazání</dt>
            <dd class="text-gray-900">{{ formatDateTime(tenant.deletion_requested_at) }}</dd>
          </div>
          <div>
            <dt class="text-gray-600">Storefront</dt>
            <dd class="text-gray-900">
              {{ tenant.allows_storefront ? 'Běží' : 'Vypnutý' }}
            </dd>
          </div>
        </dl>

        <InputError class="mt-4" :message="statusForm.errors.status" />

        <div class="mt-5 flex flex-wrap gap-3">
          <button
            type="button"
            :disabled="statusOptions.length === 0"
            :aria-describedby="statusOptions.length === 0 ? 'status-no-transitions' : undefined"
            class="inline-flex items-center rounded-md border border-transparent bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 disabled:border-gray-300 disabled:bg-gray-50 disabled:text-gray-500 disabled:hover:bg-gray-50"
            @click="openStatusDialog"
          >
            Změnit stav
          </button>

          <p
            v-if="statusOptions.length === 0"
            id="status-no-transitions"
            class="self-center text-sm text-gray-600"
          >
            Z tohoto stavu už nevede žádný ruční přechod.
          </p>
        </div>
      </section>

      <!-- Plan -->
      <section
        class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm"
        aria-labelledby="plan-heading"
      >
        <h2 id="plan-heading" class="text-base font-semibold text-gray-900">Tarif</h2>
        <p class="mt-1 text-sm text-gray-600">
          Snížení tarifu může vypnout moduly. Před potvrzením zobrazíme, čeho se to týká.
        </p>

        <div class="mt-4">
          <InputLabel for="plan-select" value="Tarif e-shopu" />
          <select
            id="plan-select"
            v-model="planSelection"
            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-slate-900 focus:ring-slate-900 sm:w-72"
          >
            <option value="">Bez tarifu (jen jádro)</option>
            <option v-for="plan in plans" :key="plan.id" :value="String(plan.id)">
              {{ plan.name }}
            </option>
          </select>
        </div>

        <InputError class="mt-3" :message="planForm.errors.plan_id" />

        <button
          type="button"
          :disabled="selectedPlanId === tenant.plan_id || planForm.processing"
          class="mt-4 inline-flex items-center rounded-md border border-transparent bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 disabled:border-gray-300 disabled:bg-gray-50 disabled:text-gray-500 disabled:hover:bg-gray-50"
          @click="openPlanDialog(route('platform.tenants.plan-impact', tenant.uuid))"
        >
          Změnit tarif
        </button>

        <p v-if="selectedPlanId === tenant.plan_id" class="mt-2 text-sm text-gray-600">
          Vybraný tarif je shodný s aktuálním.
        </p>
      </section>

      <!-- Limits -->
      <section
        class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm lg:col-span-2"
        aria-labelledby="limits-heading"
      >
        <h2 id="limits-heading" class="text-base font-semibold text-gray-900">Čerpání limitů</h2>

        <p v-if="limits.length === 0" class="mt-3 text-sm text-gray-600">
          Tarif nedefinuje žádné limity.
        </p>

        <ul v-else class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <li v-for="limit in limits" :key="limit.key">
            <div class="flex items-baseline justify-between gap-2">
              <span :id="`limit-label-${limit.key}`" class="text-sm font-medium text-gray-900">
                {{ limitLabel(limit.key) }}
              </span>
              <span class="text-sm tabular-nums text-gray-900">
                {{ limit.used }} / {{ limit.cap === null ? 'bez omezení' : limit.cap }}
              </span>
            </div>

            <div
              v-if="limit.cap !== null"
              class="mt-2 h-2 w-full overflow-hidden rounded-full bg-gray-200"
              role="progressbar"
              :aria-valuenow="limit.used"
              aria-valuemin="0"
              :aria-valuemax="limit.cap"
              :aria-valuetext="`${limit.used} z ${limit.cap} — ${limitStateLabel(limit)}`"
              :aria-labelledby="`limit-label-${limit.key}`"
            >
              <div
                class="h-full rounded-full"
                :class="limitBarClass(limit)"
                :style="{ width: limitPercent(limit) + '%' }"
              />
            </div>

            <p class="mt-1 text-xs text-gray-700">{{ limitStateLabel(limit) }}</p>
          </li>
        </ul>
      </section>

      <!-- Domains -->
      <section aria-labelledby="domains-heading">
        <h2 id="domains-heading" class="mb-3 text-base font-semibold text-gray-900">Domény</h2>

        <DataTable
          :columns="domainColumns"
          :rows="domains"
          row-key="domain"
          caption="Domény e-shopu"
        >
          <template #cell-is_primary="{ row }">{{ row.is_primary ? 'Ano' : 'Ne' }}</template>
          <template #cell-ssl_status="{ row }">{{ row.ssl_status ?? '—' }}</template>
          <template #empty>E-shop nemá žádnou doménu.</template>
        </DataTable>
      </section>

      <!-- Users -->
      <section aria-labelledby="users-heading">
        <h2 id="users-heading" class="mb-3 text-base font-semibold text-gray-900">Uživatelé</h2>

        <DataTable :columns="userColumns" :rows="users" row-key="email" caption="Uživatelé e-shopu">
          <template #cell-joined_at="{ row }">{{ formatDateTime(row.joined_at) }}</template>

          <template #cell-actions="{ row }">
            <button
              type="button"
              class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-900 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
              @click="impersonationTarget = row"
            >
              Přihlásit se jako
              <span class="sr-only">{{ row.name }}</span>
            </button>
          </template>

          <template #empty>K e-shopu není přiřazen žádný uživatel.</template>
        </DataTable>
      </section>

      <!-- Modules -->
      <section
        class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm lg:col-span-2"
        aria-labelledby="modules-heading"
      >
        <h2 id="modules-heading" class="text-base font-semibold text-gray-900">Moduly</h2>

        <InputError class="mt-3" :message="moduleError" />

        <ul class="mt-4 divide-y divide-gray-200">
          <li
            v-for="module in modules"
            :key="module.key"
            class="flex flex-wrap items-center justify-between gap-3 py-3"
          >
            <div>
              <p class="text-sm font-medium text-gray-900">
                {{ module.name }}
                <span class="font-normal text-gray-600">({{ module.key }})</span>
              </p>
              <p class="text-xs text-gray-600">
                Verze {{ module.version ?? '—' }} ·
                {{ module.enabled ? 'Zapnutý' : 'Vypnutý' }}
                <template v-if="!module.enabled_globally">
                  · <span class="font-semibold text-red-800">Stažen z provozu</span>
                </template>
              </p>
            </div>

            <p v-if="module.core" class="text-sm font-medium text-gray-700">Součást jádra</p>

            <button
              v-else-if="module.enabled"
              type="button"
              class="inline-flex items-center rounded-md border border-red-300 bg-white px-3 py-1.5 text-sm font-medium text-red-800 hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-700 focus-visible:ring-offset-2"
              @click="moduleToDisable = module"
            >
              Vypnout<span class="sr-only"> modul {{ module.name }}</span>
            </button>

            <div v-else class="flex flex-wrap items-center justify-end gap-2">
              <p v-if="moduleBlockedReason(module)" class="text-sm text-gray-700">
                {{ moduleBlockedReason(module) }}
              </p>

              <button
                type="button"
                :disabled="!module.in_plan || !module.enabled_globally || moduleForm.processing"
                :aria-describedby="moduleBlockedReason(module) ? `module-reason-${module.key}` : undefined"
                class="inline-flex items-center rounded-md border border-transparent bg-slate-900 px-3 py-1.5 text-sm font-semibold text-white hover:bg-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 disabled:border-gray-300 disabled:bg-gray-50 disabled:text-gray-500 disabled:hover:bg-gray-50"
                @click="enableModule(route('platform.tenants.modules.store', tenant.uuid), module)"
              >
                Zapnout<span class="sr-only"> modul {{ module.name }}</span>
              </button>

              <span
                v-if="moduleBlockedReason(module)"
                :id="`module-reason-${module.key}`"
                class="sr-only"
              >
                {{ moduleBlockedReason(module) }}
              </span>
            </div>
          </li>
        </ul>
      </section>

      <!-- Audit -->
      <section class="lg:col-span-2" aria-labelledby="audit-heading">
        <h2 id="audit-heading" class="mb-3 text-base font-semibold text-gray-900">
          Auditní záznam
          <span class="font-normal text-gray-600">(posledních {{ audit.length }})</span>
        </h2>

        <DataTable :columns="auditColumns" :rows="audit" caption="Poslední auditní záznamy e-shopu">
          <template #cell-created_at="{ row }">{{ formatDateTime(row.created_at) }}</template>
          <template #cell-meta="{ row }">
            <span class="block max-w-md truncate font-mono text-xs" :title="metaText(row.meta)">
              {{ metaText(row.meta) }}
            </span>
          </template>
          <template #cell-ip="{ row }">{{ row.ip ?? '—' }}</template>
          <template #empty>Žádné auditní záznamy.</template>
        </DataTable>
      </section>
    </div>

    <!-- Status change -->
    <ConfirmDialog
      :show="statusDialog"
      title="Změna stavu e-shopu"
      :message="statusWarning || 'Vyberte cílový stav e-shopu.'"
      confirm-label="Změnit stav"
      :danger="statusNeedsReason"
      :require-reason="statusNeedsReason"
      reason-label="Důvod změny"
      :reason-error="statusForm.errors.reason"
      :processing="statusForm.processing"
      @cancel="statusDialog = false"
      @confirm="(reason) => submitStatus(route('platform.tenants.status', tenant.uuid), reason)"
    >
      <InputLabel for="status-select" value="Nový stav" />
      <select
        id="status-select"
        v-model="statusForm.status"
        class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-slate-900 focus:ring-slate-900"
      >
        <option v-for="option in statusOptions" :key="option.value" :value="option.value">
          {{ option.label }}
        </option>
      </select>

      <InputError class="mt-1" :message="statusForm.errors.status" />
    </ConfirmDialog>

    <!-- Plan change -->
    <ConfirmDialog
      :show="planDialog"
      title="Změna tarifu"
      :message="`Tarif e-shopu bude nastaven na: ${selectedPlanName}.`"
      confirm-label="Změnit tarif"
      :danger="planImpact.length > 0"
      :processing="planForm.processing || planImpactLoading"
      @cancel="planDialog = false"
      @confirm="submitPlan(route('platform.tenants.plan', tenant.uuid))"
    >
      <p v-if="planImpactLoading" class="text-sm text-gray-700" role="status">
        Zjišťuji dopad změny…
      </p>

      <p v-else-if="planImpactError" class="text-sm text-red-800" role="alert">
        {{ planImpactError }}
      </p>

      <div v-else-if="planImpact.length > 0" class="rounded-md border border-red-300 bg-red-50 p-3">
        <p class="text-sm font-semibold text-red-900">
          Změnou se vypnou tyto moduly ({{ planImpact.length }}):
        </p>
        <ul class="mt-2 list-disc pl-5 text-sm text-red-900">
          <li v-for="key in planImpact" :key="key">{{ key }}</li>
        </ul>
      </div>

      <p v-else class="text-sm text-gray-700">Žádný zapnutý modul se změnou tarifu nevypne.</p>
    </ConfirmDialog>

    <!-- Module deactivation -->
    <ConfirmDialog
      :show="moduleToDisable !== null"
      title="Vypnout modul"
      :message="
        moduleToDisable
          ? `Modul „${moduleToDisable.name}“ bude pro tento e-shop vypnutý. Funkce, které na něm závisí, přestanou být dostupné.`
          : ''
      "
      confirm-label="Vypnout modul"
      danger
      @cancel="moduleToDisable = null"
      @confirm="
        moduleToDisable &&
          disableModule(
            route('platform.tenants.modules.destroy', [tenant.uuid, moduleToDisable.key]),
          )
      "
    />

    <!-- Impersonation -->
    <ConfirmDialog
      :show="impersonationTarget !== null"
      title="Přihlásit se jako uživatel"
      :message="
        impersonationTarget
          ? `Budete jednat jménem uživatele ${impersonationTarget.name} (${impersonationTarget.email}). Akce se zapisuje do auditu.`
          : ''
      "
      confirm-label="Přihlásit se jako"
      :danger="!tenant.allows_storefront"
      :processing="impersonating"
      @cancel="impersonationTarget = null"
      @confirm="impersonate"
    >
      <p
        v-if="impersonationWarning"
        role="alert"
        class="rounded-md border border-amber-400 bg-amber-50 p-3 text-sm text-amber-900"
      >
        {{ impersonationWarning }}
      </p>
    </ConfirmDialog>
  </PlatformLayout>
</template>
