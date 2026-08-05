<script setup lang="ts">
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import DataTable, { type Column } from '@/Components/Ui/DataTable.vue'
import ConfirmDialog from '@/Components/Ui/ConfirmDialog.vue'

type Page = {
  id: number
  slug: string
  title: string
  is_published: boolean
}

defineProps<{ pages: Page[]; canEdit: boolean }>()

const columns: Column[] = [
  { key: 'title', label: 'Název' },
  { key: 'slug', label: 'URL' },
  { key: 'state', label: 'Stav' },
  { key: 'actions', label: 'Akce' },
]

const pendingDelete = ref<Page | null>(null)

const confirmDelete = () => {
  if (!pendingDelete.value) return

  router.delete(route('admin.pages.destroy', pendingDelete.value.id), {
    onFinish: () => (pendingDelete.value = null),
  })
}
</script>

<template>
  <AdminLayout title="Stránky">
    <template #header>
      <h1 class="text-xl font-semibold text-gray-900">Stránky</h1>
      <p class="mt-1 text-sm text-gray-600">
        Statické stránky e-shopu — obchodní podmínky, ochrana údajů, kontakt.
      </p>
    </template>

    <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
      <p class="font-medium">Za obsah stránek odpovídáte vy</p>
      <p class="mt-1">
        Obchodní podmínky, ochrana osobních údajů a kontakt jsou předvyplněné vzorem. Vzor
        <strong>není právní radou</strong> — projděte ho, doplňte části označené
        <strong>[DOPLŇTE …]</strong> a teprve pak stránku publikujte.
      </p>
    </div>

    <div v-if="canEdit" class="mb-4">
      <Link
        :href="route('admin.pages.create')"
        class="inline-flex rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500"
      >
        Nová stránka
      </Link>
    </div>

    <DataTable :columns="columns" :rows="pages" caption="Seznam statických stránek e-shopu">
      <template #empty>Zatím tu není žádná stránka.</template>
      <template #cell-title="{ row }">{{ (row as Page).title }}</template>
      <template #cell-slug="{ row }">
        <code class="text-xs">/{{ (row as Page).slug }}</code>
      </template>
      <template #cell-state="{ row }">
        <!-- Text, not just a colour: state has to survive being read aloud. -->
        {{ (row as Page).is_published ? 'Publikováno' : 'Koncept' }}
      </template>
      <template #cell-actions="{ row }">
        <span v-if="canEdit" class="flex gap-3">
          <Link
            :href="route('admin.pages.edit', (row as Page).id)"
            class="text-sm text-indigo-600 underline hover:text-indigo-800"
          >
            Upravit
          </Link>
          <button
            type="button"
            class="text-sm text-red-600 underline hover:text-red-800"
            @click="pendingDelete = row as Page"
          >
            Smazat
          </button>
        </span>
      </template>
    </DataTable>

    <ConfirmDialog
      :show="pendingDelete !== null"
      title="Smazat stránku"
      :message="`Opravdu chcete smazat stránku „${pendingDelete?.title}“? Tuto akci nelze vrátit zpět.`"
      confirm-label="Smazat"
      danger
      @confirm="confirmDelete"
      @cancel="pendingDelete = null"
    />
  </AdminLayout>
</template>
