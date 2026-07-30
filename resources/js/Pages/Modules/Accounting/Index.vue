<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'

defineProps<{
  formats: { key: string; label: string }[]
  maxDocuments: number
}>()
</script>

<template>
  <AdminLayout title="Účetní export">
    <h1 class="text-lg font-semibold text-gray-900">Účetní export</h1>
    <p class="mt-1 text-sm text-gray-600">
      Doklady za období ve formátu, který naimportuje účetní program.
    </p>
  </AdminLayout>
</template>
