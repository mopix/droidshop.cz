<script setup lang="ts">
export type Column = {
  key: string
  label: string
  align?: 'left' | 'center' | 'right'
}

type Row = Record<string, any>

const props = withDefaults(
  defineProps<{
    columns: Column[]
    rows: Row[]
    /** Table description for screen readers — required, rendered visually hidden. */
    caption: string
    /** Row property used as :key; falls back to the array index. */
    rowKey?: string
  }>(),
  { rowKey: 'id' },
)

const alignClass = (column: Column) =>
  ({
    left: 'text-left',
    center: 'text-center',
    right: 'text-right',
  })[column.align ?? 'left']

const keyFor = (row: Row, index: number) => row[props.rowKey] ?? index
</script>

<template>
  <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white shadow-sm">
    <table class="min-w-full divide-y divide-gray-200 text-sm">
      <caption class="sr-only">{{ caption }}</caption>

      <thead class="bg-gray-50">
        <tr>
          <th
            v-for="column in columns"
            :key="column.key"
            scope="col"
            class="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-700"
            :class="alignClass(column)"
          >
            <slot :name="`head-${column.key}`" :column="column">{{ column.label }}</slot>
          </th>
        </tr>
      </thead>

      <tbody class="divide-y divide-gray-200">
        <tr v-if="rows.length === 0">
          <td :colspan="columns.length" class="px-4 py-10 text-center text-gray-600">
            <slot name="empty">Žádné záznamy.</slot>
          </td>
        </tr>

        <template v-else>
          <tr v-for="(row, index) in rows" :key="keyFor(row, index)" class="hover:bg-gray-50">
            <td
              v-for="column in columns"
              :key="column.key"
              class="px-4 py-3 text-gray-900"
              :class="alignClass(column)"
            >
              <slot
                :name="`cell-${column.key}`"
                :row="row"
                :value="row[column.key]"
                :index="index"
                :column="column"
              >
                {{ row[column.key] }}
              </slot>
            </td>
          </tr>
        </template>
      </tbody>
    </table>
  </div>
</template>
