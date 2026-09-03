<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import DataTable, { type Column } from '@/Components/Ui/DataTable.vue'
import Pagination, { type PaginationLink, type PaginationMeta } from '@/Components/Ui/Pagination.vue'
import ConfirmDialog from '@/Components/Ui/ConfirmDialog.vue'

type ReviewRow = {
  id: number
  subject: 'product' | 'shop'
  product: string | null
  author_name: string
  author_email: string
  rating: number
  title: string | null
  body: string | null
  status: 'pending' | 'published' | 'rejected'
  rejection_reason: string | null
  reply_body: string | null
  created_at: string | null
  published_at: string | null
}

const props = defineProps<{
  reviews: { data: ReviewRow[]; links: PaginationLink[]; meta?: PaginationMeta }
  filters: { status: string }
  counts: Record<string, number>
}>()

const TABS: { value: string; label: string }[] = [
  { value: 'pending', label: 'Čeká na schválení' },
  { value: 'published', label: 'Zveřejněné' },
  { value: 'rejected', label: 'Zamítnuté' },
]

// The sentence the moderator sees above every reason field. Rejecting a
// review for being unfavourable is an unfair commercial practice under the
// Omnibus rules, so the rule is stated where the decision is made, not
// buried in the documentation.
const REASON_HINT =
  'Zamítnout lze vulgaritu, osobní údaje nebo obsah, který se netýká zboží. Nízké hodnocení důvodem není.'

const columns: Column[] = [
  { key: 'rating', label: 'Hodnocení' },
  { key: 'subject', label: 'Předmět' },
  { key: 'author', label: 'Autor' },
  { key: 'text', label: 'Text' },
  { key: 'created', label: 'Přijato' },
  { key: 'actions', label: 'Akce', align: 'right' },
]

const dateLabel = (value: string | null) =>
  value ? new Intl.DateTimeFormat('cs-CZ', { dateStyle: 'medium' }).format(new Date(value)) : '—'

const stars = (rating: number) => '★'.repeat(rating) + '☆'.repeat(5 - rating)

const subjectLabel = (row: ReviewRow) =>
  row.subject === 'shop' ? 'Hodnocení obchodu' : (row.product ?? `Produkt (smazaný)`)

const switchTo = (status: string) => {
  router.get(route('admin.reviews.index'), { status }, { preserveScroll: true, preserveState: true })
}

// The server sends validation errors back under the field the request
// validates, so the dialog can show them without its own state.
const page = usePage()
const reasonError = computed(() => (page.props.errors as Record<string, string>)?.reason ?? '')
const replyError = computed(() => (page.props.errors as Record<string, string>)?.body ?? '')

const rejecting = ref<ReviewRow | null>(null)
const hiding = ref<ReviewRow | null>(null)
const replying = ref<ReviewRow | null>(null)

const publish = (row: ReviewRow) => {
  router.post(route('admin.reviews.publish', row.id), {}, { preserveScroll: true })
}

const submitReject = (reason: string) => {
  const row = rejecting.value

  if (!row) return

  router.post(route('admin.reviews.reject', row.id), { reason }, {
    preserveScroll: true,
    onFinish: () => (rejecting.value = null),
  })
}

const submitHide = (reason: string) => {
  const row = hiding.value

  if (!row) return

  router.post(route('admin.reviews.hide', row.id), { reason }, {
    preserveScroll: true,
    onFinish: () => (hiding.value = null),
  })
}

const submitReply = (body: string) => {
  const row = replying.value

  if (!row) return

  router.post(route('admin.reviews.reply', row.id), { body }, {
    preserveScroll: true,
    onFinish: () => (replying.value = null),
  })
}
</script>

<template>
  <AdminLayout title="Recenze">
    <template #header>
      <div>
        <h1 class="text-xl font-semibold text-gray-900">Recenze</h1>
        <p class="mt-1 text-sm text-gray-600">
          Recenze produktů a hodnocení obchodu od ověřených kupujících.
        </p>
      </div>
    </template>

    <nav class="mb-4 flex flex-wrap gap-2" aria-label="Stav recenzí">
      <button
        v-for="tab in TABS"
        :key="tab.value"
        type="button"
        :aria-current="props.filters.status === tab.value ? 'page' : undefined"
        class="rounded-md border px-3 py-1.5 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900"
        :class="
          props.filters.status === tab.value
            ? 'border-slate-900 bg-slate-900 text-white'
            : 'border-gray-300 bg-white text-gray-800 hover:bg-gray-50'
        "
        @click="switchTo(tab.value)"
      >
        {{ tab.label }}
        <span class="ml-1 text-xs">({{ props.counts[tab.value] ?? 0 }})</span>
      </button>
    </nav>

    <DataTable :columns="columns" :rows="props.reviews.data" row-key="id" caption="Recenze e-shopu">
      <template #empty>V tomto stavu tu není žádná recenze.</template>

      <template #cell-rating="{ row }">
        <span aria-hidden="true" class="text-amber-600">{{ stars((row as ReviewRow).rating) }}</span>
        <span class="sr-only">{{ (row as ReviewRow).rating }} z 5 hvězdiček</span>
      </template>

      <template #cell-subject="{ row }">{{ subjectLabel(row as ReviewRow) }}</template>

      <template #cell-author="{ row }">
        <span class="block font-medium text-gray-900">{{ (row as ReviewRow).author_name }}</span>
        <span class="block text-xs text-gray-700">{{ (row as ReviewRow).author_email }}</span>
      </template>

      <template #cell-text="{ row }">
        <span v-if="(row as ReviewRow).title" class="block font-medium text-gray-900">
          {{ (row as ReviewRow).title }}
        </span>
        <span class="block max-w-md text-sm text-gray-700">{{ (row as ReviewRow).body ?? '—' }}</span>
        <span v-if="(row as ReviewRow).reply_body" class="mt-1 block max-w-md text-sm text-gray-600">
          Odpověď obchodu: {{ (row as ReviewRow).reply_body }}
        </span>
        <span v-if="(row as ReviewRow).rejection_reason" class="mt-1 block text-xs text-gray-600">
          Důvod zamítnutí: {{ (row as ReviewRow).rejection_reason }}
        </span>
      </template>

      <template #cell-created="{ row }">{{ dateLabel((row as ReviewRow).created_at) }}</template>

      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <button
            v-if="(row as ReviewRow).status !== 'published'"
            type="button"
            class="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-800 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
            @click="publish(row as ReviewRow)"
          >
            Publikovat
          </button>

          <button
            type="button"
            class="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-800 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
            @click="replying = row as ReviewRow"
          >
            Odpovědět
          </button>

          <button
            v-if="(row as ReviewRow).status === 'pending'"
            type="button"
            class="rounded-md px-3 py-1.5 text-sm font-medium text-red-800 hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-800"
            @click="rejecting = row as ReviewRow"
          >
            Zamítnout
          </button>

          <button
            v-if="(row as ReviewRow).status === 'published'"
            type="button"
            class="rounded-md px-3 py-1.5 text-sm font-medium text-red-800 hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-800"
            @click="hiding = row as ReviewRow"
          >
            Skrýt
          </button>
        </div>
      </template>
    </DataTable>

    <Pagination :links="props.reviews.links" :meta="props.reviews.meta" />

    <ConfirmDialog
      :show="rejecting !== null"
      title="Zamítnout recenzi"
      :message="REASON_HINT"
      confirm-label="Zamítnout"
      reason-label="Důvod zamítnutí"
      :reason-error="reasonError"
      require-reason
      danger
      @cancel="rejecting = null"
      @confirm="submitReject"
    />

    <ConfirmDialog
      :show="hiding !== null"
      title="Skrýt zveřejněnou recenzi"
      :message="`Recenze zmizí z e-shopu a přestane se počítat do průměru. ${REASON_HINT}`"
      confirm-label="Skrýt"
      reason-label="Důvod skrytí"
      :reason-error="reasonError"
      require-reason
      danger
      @cancel="hiding = null"
      @confirm="submitHide"
    />

    <ConfirmDialog
      :show="replying !== null"
      title="Odpovědět na recenzi"
      message="Odpověď se zobrazí u recenze na e-shopu."
      confirm-label="Odeslat odpověď"
      reason-label="Odpověď"
      :reason-error="replyError"
      require-reason
      @cancel="replying = null"
      @confirm="submitReply"
    />
  </AdminLayout>
</template>
