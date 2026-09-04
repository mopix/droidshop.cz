<script setup lang="ts">
/**
 * The list chrome shared by every item-shaped homepage block — slider slides,
 * benefit items, product tabs, banners.
 *
 * One component rather than four copies of the same markup: each copy is a
 * place where the ordering buttons lose their accessible names, or where the
 * bounds stop matching the server's. Reordering is buttons, never dragging —
 * dragging is not reachable from a keyboard (WCAG 2.1.1), the same reasoning
 * as the category tree and the product images.
 */
const props = defineProps<{
  items: Record<string, unknown>[]
  label: string
  itemLabel: string
  min: number
  max: number
}>()

const emit = defineEmits<{
  (e: 'add'): void
  (e: 'remove', index: number): void
  (e: 'move', index: number, direction: -1 | 1): void
}>()
</script>

<template>
  <fieldset class="sm:col-span-2">
    <legend class="text-sm font-semibold text-gray-900">{{ label }}</legend>
    <p class="mt-1 text-sm text-gray-600">Počet položek {{ min }}–{{ max }}.</p>

    <ol class="mt-3 space-y-4">
      <li
        v-for="(item, index) in props.items"
        :key="index"
        class="rounded-md border border-gray-200 p-3"
      >
        <div class="mb-3 flex items-center justify-between gap-2">
          <span class="text-sm font-medium text-gray-700">{{ itemLabel }} {{ index + 1 }}</span>

          <div class="flex gap-1">
            <button
              type="button"
              :disabled="index === 0"
              class="rounded border border-gray-300 px-2 py-1 text-sm text-gray-700 hover:bg-gray-100 disabled:opacity-40 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
              @click="emit('move', index, -1)"
            >
              <span aria-hidden="true">↑</span>
              <span class="sr-only">Posunout {{ itemLabel.toLowerCase() }} {{ index + 1 }} nahoru</span>
            </button>

            <button
              type="button"
              :disabled="index === props.items.length - 1"
              class="rounded border border-gray-300 px-2 py-1 text-sm text-gray-700 hover:bg-gray-100 disabled:opacity-40 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
              @click="emit('move', index, 1)"
            >
              <span aria-hidden="true">↓</span>
              <span class="sr-only">Posunout {{ itemLabel.toLowerCase() }} {{ index + 1 }} dolů</span>
            </button>

            <button
              type="button"
              :disabled="props.items.length <= min"
              class="rounded border border-red-300 px-2 py-1 text-sm text-red-700 hover:bg-red-50 disabled:opacity-40 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-700"
              @click="emit('remove', index)"
            >
              Odebrat<span class="sr-only"> {{ itemLabel.toLowerCase() }} {{ index + 1 }}</span>
            </button>
          </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
          <slot :item="item" :index="index" />
        </div>
      </li>
    </ol>

    <button
      type="button"
      :disabled="props.items.length >= max"
      class="mt-3 rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-800 hover:bg-gray-100 disabled:opacity-40 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
      @click="emit('add')"
    >
      Přidat {{ itemLabel.toLowerCase() }}
    </button>
  </fieldset>
</template>
