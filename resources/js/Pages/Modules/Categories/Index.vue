<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import ConfirmDialog from '@/Components/Ui/ConfirmDialog.vue'
import CategoryBranch from './Partials/CategoryBranch.vue'
import type { CategoryNode } from './types'

const props = defineProps<{
  categories: CategoryNode[]
  maxDepth: number
}>()

const createForm = useForm({ name: '', parent_id: null as number | null })

const submitCreate = () =>
  createForm.post(route('admin.categories.store'), {
    preserveScroll: true,
    onSuccess: () => createForm.reset(),
  })

/** Flattened tree, used for the "move to" and "parent" selects. */
const flat = computed(() => {
  const out: { id: number; label: string; depth: number }[] = []

  const walk = (nodes: CategoryNode[]) => {
    for (const node of nodes) {
      out.push({ id: node.id, label: `${'— '.repeat(node.depth)}${node.name}`, depth: node.depth })
      walk(node.children)
    }
  }

  walk(props.categories)

  return out
})

const deleting = ref<CategoryNode | null>(null)
const moveTo = ref<number | null>(null)

const hasChildren = computed(() => (deleting.value?.children.length ?? 0) > 0)

const confirmDelete = () => {
  const category = deleting.value

  if (!category) return

  router.delete(route('admin.categories.destroy', category.slug), {
    data: { move_to: moveTo.value },
    preserveScroll: true,
    onFinish: () => {
      deleting.value = null
      moveTo.value = null
    },
  })
}

// Reordering is a pair of buttons rather than drag and drop: dragging is not
// reachable from the keyboard, and this screen has to be (WCAG 2.1.1).
const shift = (node: CategoryNode, siblings: CategoryNode[], direction: -1 | 1) => {
  const index = siblings.findIndex((c) => c.id === node.id)
  const target = index + direction

  if (target < 0 || target >= siblings.length) return

  const ids = siblings.map((c) => c.id)
  ids.splice(target, 0, ids.splice(index, 1)[0])

  router.post(
    route('admin.categories.reorder'),
    { parent_id: siblings[0].parent_id ?? null, ids },
    { preserveScroll: true },
  )
}
</script>

<template>
  <AdminLayout title="Kategorie">
    <template #header>
      <h1 class="text-xl font-semibold text-gray-900">Kategorie</h1>
      <p class="mt-1 text-sm text-gray-600">
        Strom kategorií e-shopu. Nejvýše {{ maxDepth }} úrovně.
      </p>
    </template>

    <form
      class="mb-6 flex flex-wrap items-end gap-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm"
      @submit.prevent="submitCreate"
    >
      <div>
        <label for="new-category-name" class="block text-sm font-medium text-gray-700">
          Název nové kategorie
        </label>
        <input
          id="new-category-name"
          v-model="createForm.name"
          type="text"
          required
          class="mt-1 rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
          :aria-describedby="createForm.errors.name ? 'new-category-name-error' : undefined"
          :aria-invalid="createForm.errors.name ? 'true' : undefined"
        />
        <p id="new-category-name-error" v-if="createForm.errors.name" class="mt-1 text-sm text-red-700">
          {{ createForm.errors.name }}
        </p>
      </div>

      <div>
        <label for="new-category-parent" class="block text-sm font-medium text-gray-700">
          Nadřazená kategorie
        </label>
        <select
          id="new-category-parent"
          v-model="createForm.parent_id"
          class="mt-1 rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
        >
          <option :value="null">— žádná (kořen) —</option>
          <option
            v-for="option in flat"
            :key="option.id"
            :value="option.id"
            :disabled="option.depth >= maxDepth - 1"
          >
            {{ option.label }}
          </option>
        </select>
      </div>

      <button
        type="submit"
        :disabled="createForm.processing"
        class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:bg-gray-400 disabled:text-gray-700"
      >
        Přidat
      </button>
    </form>

    <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
      <p v-if="categories.length === 0" class="py-8 text-center text-gray-600">
        Zatím tu není žádná kategorie.
      </p>

      <ul v-else class="space-y-1">
        <CategoryBranch
          v-for="node in categories"
          :key="node.id"
          :node="node"
          :siblings="categories"
          :options="flat"
          :max-depth="maxDepth"
          @shift="shift"
          @delete="deleting = $event"
        />
      </ul>
    </div>

    <ConfirmDialog
      :show="deleting !== null"
      title="Smazat kategorii"
      :message="`Opravdu smazat kategorii ${deleting?.name ?? ''}? Akci nelze vzít zpět.`"
      confirm-label="Smazat"
      danger
      @cancel="deleting = null"
      @confirm="confirmDelete"
    >
      <div v-if="hasChildren">
        <label for="move-to" class="block text-sm font-medium text-gray-700">
          Kam přesunout podkategorie
        </label>
        <select
          id="move-to"
          v-model="moveTo"
          required
          aria-required="true"
          class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
        >
          <option :value="null" disabled>— vyberte kategorii —</option>
          <option
            v-for="option in flat.filter((o) => o.id !== deleting?.id)"
            :key="option.id"
            :value="option.id"
          >
            {{ option.label }}
          </option>
        </select>
      </div>
    </ConfirmDialog>
  </AdminLayout>
</template>
