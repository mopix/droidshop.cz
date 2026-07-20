<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import PlatformLayout from '@/Layouts/PlatformLayout.vue'
import DataTable, { type Column } from '@/Components/Platform/DataTable.vue'
import FilterBar from '@/Components/Platform/FilterBar.vue'
import Pagination from '@/Components/Platform/Pagination.vue'
import StatusBadge from '@/Components/Platform/StatusBadge.vue'
import InputLabel from '@/Components/InputLabel.vue'
import TextInput from '@/Components/TextInput.vue'

type TenantRow = {
  uuid: string
  name: string
  domain: string | null
  status: string
  status_label: string
  plan: string | null
  trial_ends_at: string | null
  created_at: string | null
}

type Paginator<T> = {
  data: T[]
  links: { url: string | null; label: string; active: boolean }[]
  current_page: number
  last_page: number
  from: number | null
  to: number | null
  total: number
}

const props = defineProps<{
  tenants: Paginator<TenantRow>
  filters: { search: string | null; status: string | null; plan: string | null }
  statuses: { value: string; label: string }[]
  plans: { key: string; name: string }[]
}>()

// Local copies so typing in the field does not fire a request per keystroke;
// the filter bar submits explicitly (Enter or the button).
const search = ref(props.filters.search ?? '')
const status = ref(props.filters.status ?? '')
const plan = ref(props.filters.plan ?? '')

const hasFilters = computed(
  () => search.value !== '' || status.value !== '' || plan.value !== '',
)

const columns: Column[] = [
  { key: 'name', label: 'Název' },
  { key: 'domain', label: 'Doména' },
  { key: 'status', label: 'Stav' },
  { key: 'plan', label: 'Tarif' },
  { key: 'trial_ends_at', label: 'Konec zkušebního období' },
  { key: 'created_at', label: 'Založeno' },
]

const processing = ref(false)

/**
 * Filters live in the URL: the listing stays shareable and the back button
 * behaves. replace keeps the history clean while typing through filters.
 */
const apply = (url: string) => {
  router.get(
    url,
    {
      search: search.value || undefined,
      status: status.value || undefined,
      plan: plan.value || undefined,
    },
    {
      preserveState: true,
      preserveScroll: true,
      replace: true,
      onStart: () => (processing.value = true),
      onFinish: () => (processing.value = false),
    },
  )
}

const reset = (url: string) => {
  search.value = ''
  status.value = ''
  plan.value = ''
  apply(url)
}

const formatDate = (value: string | null): string =>
  value ? new Date(value).toLocaleDateString('cs-CZ') : '—'
</script>

<template>
  <PlatformLayout title="Tenanti">
    <template #header>
      <h1 class="text-xl font-semibold text-gray-900">Tenanti</h1>
      <p class="mt-1 text-sm text-gray-600">
        Všechny e-shopy na platformě, jejich stav a tarif.
      </p>
    </template>

    <FilterBar
      :has-filters="hasFilters"
      :processing="processing"
      legend="Filtrování e-shopů"
      @submit="apply(route('platform.tenants.index'))"
      @reset="reset(route('platform.tenants.index'))"
    >
      <div class="w-full sm:w-64">
        <InputLabel for="filter-search" value="Hledat" />
        <TextInput
          id="filter-search"
          v-model="search"
          type="search"
          class="mt-1 block w-full"
          autocomplete="off"
          aria-describedby="filter-search-hint"
        />
        <p id="filter-search-hint" class="mt-1 text-xs text-gray-600">
          Název, doména, fakturační jméno nebo IČO.
        </p>
      </div>

      <div class="w-full sm:w-52">
        <InputLabel for="filter-status" value="Stav" />
        <select
          id="filter-status"
          v-model="status"
          class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-slate-900 focus:ring-slate-900"
        >
          <option value="">Všechny stavy</option>
          <option v-for="option in statuses" :key="option.value" :value="option.value">
            {{ option.label }}
          </option>
        </select>
      </div>

      <div class="w-full sm:w-52">
        <InputLabel for="filter-plan" value="Tarif" />
        <select
          id="filter-plan"
          v-model="plan"
          class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-slate-900 focus:ring-slate-900"
        >
          <option value="">Všechny tarify</option>
          <option v-for="option in plans" :key="option.key" :value="option.key">
            {{ option.name }}
          </option>
        </select>
      </div>
    </FilterBar>

    <DataTable
      :columns="columns"
      :rows="tenants.data"
      row-key="uuid"
      caption="Seznam e-shopů s doménou, stavem, tarifem a daty založení"
    >
      <template #cell-name="{ row }">
        <Link
          :href="route('platform.tenants.show', row.uuid)"
          class="font-medium text-slate-900 underline underline-offset-2 hover:text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
        >
          {{ row.name }}
        </Link>
      </template>

      <template #cell-domain="{ row }">
        <span v-if="row.domain">{{ row.domain }}</span>
        <span v-else class="text-gray-600">Bez domény</span>
      </template>

      <template #cell-status="{ row }">
        <StatusBadge :status="row.status" :label="row.status_label" />
      </template>

      <template #cell-plan="{ row }">
        <span v-if="row.plan">{{ row.plan }}</span>
        <span v-else class="text-gray-600">Bez tarifu</span>
      </template>

      <template #cell-trial_ends_at="{ row }">{{ formatDate(row.trial_ends_at) }}</template>

      <template #cell-created_at="{ row }">{{ formatDate(row.created_at) }}</template>

      <template #empty>Žádný e-shop neodpovídá zadaným filtrům.</template>
    </DataTable>

    <Pagination :links="tenants.links" :meta="tenants" />
  </PlatformLayout>
</template>
