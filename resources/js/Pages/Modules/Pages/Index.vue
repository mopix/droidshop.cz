<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import DataTable, { type Column } from '@/Components/Ui/DataTable.vue'

type Page = {
  id: number
  slug: string
  title: string
  is_published: boolean
}

defineProps<{ pages: Page[] }>()

const columns: Column[] = [
  { key: 'title', label: 'Název' },
  { key: 'slug', label: 'URL' },
  { key: 'state', label: 'Stav' },
]
</script>

<template>
  <AdminLayout title="Stránky">
    <template #header>
      <h1 class="text-xl font-semibold text-gray-900">Stránky</h1>
      <p class="mt-1 text-sm text-gray-600">
        Statické stránky e-shopu. Úpravy přijdou v další vlně.
      </p>
    </template>

    <DataTable
      :columns="columns"
      :rows="pages"
      caption="Seznam statických stránek e-shopu"
    >
      <template #empty>Zatím tu není žádná stránka.</template>
      <template #cell-title="{ row }">{{ (row as Page).title }}</template>
      <template #cell-slug="{ row }">
        <code class="text-xs">/stranka/{{ (row as Page).slug }}</code>
      </template>
      <template #cell-state="{ row }">
        <!-- Text, not just a colour: state has to survive being read aloud. -->
        {{ (row as Page).is_published ? 'Publikováno' : 'Koncept' }}
      </template>
    </DataTable>
  </AdminLayout>
</template>
