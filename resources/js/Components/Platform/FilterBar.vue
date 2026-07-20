<script setup lang="ts">
withDefaults(
  defineProps<{
    /** Renders role="search" — use when the bar contains a fulltext field. */
    search?: boolean
    legend?: string
    submitLabel?: string
    resetLabel?: string
    /** Enables the reset button; pass true when any filter is applied. */
    hasFilters?: boolean
    processing?: boolean
  }>(),
  {
    search: true,
    legend: 'Filtry',
    submitLabel: 'Filtrovat',
    resetLabel: 'Zrušit filtry',
    hasFilters: false,
    processing: false,
  },
)

const emit = defineEmits<{
  (e: 'submit'): void
  (e: 'reset'): void
}>()
</script>

<template>
  <!-- Native form submit keeps Enter working inside any of the slotted fields. -->
  <form
    :role="search ? 'search' : undefined"
    :aria-label="legend"
    class="mb-4 rounded-lg border border-gray-200 bg-white p-4 shadow-sm"
    @submit.prevent="emit('submit')"
  >
    <div class="flex flex-wrap items-end gap-4">
      <slot />

      <div class="ml-auto flex items-end gap-2">
        <button
          type="submit"
          :disabled="processing"
          class="inline-flex items-center rounded-md border border-transparent bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 disabled:opacity-50"
        >
          {{ submitLabel }}
        </button>

        <button
          v-if="hasFilters"
          type="button"
          class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-800 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
          @click="emit('reset')"
        >
          {{ resetLabel }}
        </button>
      </div>
    </div>
  </form>
</template>
