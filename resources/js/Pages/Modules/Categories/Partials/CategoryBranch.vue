<script setup lang="ts">
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import type { CategoryNode } from '../types'

const props = defineProps<{
  node: CategoryNode
  siblings: CategoryNode[]
  options: { id: number; label: string; depth: number }[]
  maxDepth: number
}>()

const emit = defineEmits<{
  (e: 'shift', node: CategoryNode, siblings: CategoryNode[], direction: -1 | 1): void
  (e: 'delete', node: CategoryNode): void
}>()

const open = ref(false)

const form = useForm({
  name: props.node.name,
  slug: props.node.slug,
  is_visible: props.node.is_visible,
})

const moveForm = useForm({ parent_id: props.node.parent_id })

// The slug field carries a permanent hint and, when the server refuses it, an
// error. A screen reader has to hear both, and neither id may be referenced
// while its element is not rendered.
const slugDescribedBy = computed(() =>
  [`slug-hint-${props.node.id}`, form.errors.slug ? `slug-error-${props.node.id}` : null]
    .filter(Boolean)
    .join(' '),
)

const save = () =>
  form.patch(route('admin.categories.update', props.node.slug), {
    preserveScroll: true,
    onSuccess: () => (open.value = false),
  })

const submitMove = () =>
  moveForm.post(route('admin.categories.move', props.node.slug), { preserveScroll: true })

const isFirst = props.siblings[0]?.id === props.node.id
const isLast = props.siblings[props.siblings.length - 1]?.id === props.node.id
</script>

<template>
  <li>
    <div class="flex flex-wrap items-center gap-2 rounded-md px-2 py-1.5 hover:bg-gray-50">
      <button
        type="button"
        class="rounded-md px-3 py-1.5 text-left text-sm font-medium text-gray-900 hover:bg-gray-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
        :aria-expanded="open"
        :aria-controls="`details-${node.id}`"
        @click="open = !open"
      >
        {{ node.name }}
      </button>

      <span v-if="!node.is_visible" class="text-xs font-medium text-gray-600">skrytá</span>

      <code class="text-xs text-gray-700">{{ node.url }}</code>

      <span class="ml-auto flex items-center gap-1">
        <!-- Buttons, not drag and drop: dragging cannot be done from the
             keyboard, and this screen has to work without a mouse. -->
        <button
          type="button"
          class="rounded-md px-2 py-1.5 text-sm text-gray-700 hover:bg-gray-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 disabled:cursor-not-allowed disabled:text-gray-400"
          :disabled="isFirst"
          @click="emit('shift', node, siblings, -1)"
        >
          <span aria-hidden="true">↑</span>
          <span class="sr-only">Posunout kategorii {{ node.name }} nahoru</span>
        </button>
        <button
          type="button"
          class="rounded-md px-2 py-1.5 text-sm text-gray-700 hover:bg-gray-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 disabled:cursor-not-allowed disabled:text-gray-400"
          :disabled="isLast"
          @click="emit('shift', node, siblings, 1)"
        >
          <span aria-hidden="true">↓</span>
          <span class="sr-only">Posunout kategorii {{ node.name }} dolů</span>
        </button>
        <button
          type="button"
          class="rounded-md px-3 py-1.5 text-sm font-medium text-red-800 hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-800"
          @click="emit('delete', node)"
        >
          Smazat<span class="sr-only"> kategorii {{ node.name }}</span>
        </button>
      </span>
    </div>

    <div
      v-if="open"
      :id="`details-${node.id}`"
      class="ml-4 mt-2 rounded-md border border-gray-200 bg-gray-50 p-4"
    >
      <form class="grid gap-3 sm:grid-cols-2" @submit.prevent="save">
        <div>
          <label :for="`name-${node.id}`" class="block text-sm font-medium text-gray-700">Název</label>
          <input
            :id="`name-${node.id}`"
            v-model="form.name"
            type="text"
            class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
            :aria-invalid="form.errors.name ? 'true' : undefined"
            :aria-describedby="form.errors.name ? `name-error-${node.id}` : undefined"
          />
          <p
            v-if="form.errors.name"
            :id="`name-error-${node.id}`"
            class="mt-1 text-sm text-red-700"
          >
            {{ form.errors.name }}
          </p>
        </div>

        <div>
          <label :for="`slug-${node.id}`" class="block text-sm font-medium text-gray-700">
            URL adresa
          </label>
          <input
            :id="`slug-${node.id}`"
            v-model="form.slug"
            type="text"
            class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
            :aria-invalid="form.errors.slug ? 'true' : undefined"
            :aria-describedby="slugDescribedBy"
          />
          <p :id="`slug-hint-${node.id}`" class="mt-1 text-sm text-gray-600">
            Změna URL nechá na staré adrese trvalé přesměrování.
          </p>
          <p
            v-if="form.errors.slug"
            :id="`slug-error-${node.id}`"
            class="mt-1 text-sm text-red-700"
          >
            {{ form.errors.slug }}
          </p>
        </div>

        <div class="flex items-center gap-2">
          <input
            :id="`visible-${node.id}`"
            v-model="form.is_visible"
            type="checkbox"
            class="rounded border-gray-300 text-gray-900 focus:ring-gray-900"
          />
          <label :for="`visible-${node.id}`" class="text-sm text-gray-700">
            Zobrazovat v e-shopu
          </label>
        </div>

        <div class="sm:col-span-2">
          <button
            type="submit"
            :disabled="form.processing"
            class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:bg-gray-400 disabled:text-gray-700"
          >
            Uložit
          </button>
        </div>
      </form>

      <form class="mt-4 flex flex-wrap items-end gap-3 border-t border-gray-200 pt-4" @submit.prevent="submitMove">
        <div>
          <label :for="`parent-${node.id}`" class="block text-sm font-medium text-gray-700">
            Nadřazená kategorie
          </label>
          <select
            :id="`parent-${node.id}`"
            v-model="moveForm.parent_id"
            class="mt-1 rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
            :aria-invalid="moveForm.errors.parent_id ? 'true' : undefined"
            :aria-describedby="moveForm.errors.parent_id ? `parent-error-${node.id}` : undefined"
          >
            <option :value="null">— žádná (kořen) —</option>
            <option
              v-for="option in options.filter((o) => o.id !== node.id)"
              :key="option.id"
              :value="option.id"
            >
              {{ option.label }}
            </option>
          </select>
          <p
            v-if="moveForm.errors.parent_id"
            :id="`parent-error-${node.id}`"
            class="mt-1 text-sm text-red-700"
          >
            {{ moveForm.errors.parent_id }}
          </p>
        </div>

        <button
          type="submit"
          :disabled="moveForm.processing"
          class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 disabled:cursor-not-allowed disabled:bg-gray-400 disabled:text-gray-700"
        >
          Přesunout
        </button>
      </form>
    </div>

    <ul v-if="node.children.length" class="ml-6 mt-1 space-y-1 border-l border-gray-200 pl-2">
      <CategoryBranch
        v-for="child in node.children"
        :key="child.id"
        :node="child"
        :siblings="node.children"
        :options="options"
        :max-depth="maxDepth"
        @shift="(...args) => emit('shift', ...args)"
        @delete="emit('delete', $event)"
      />
    </ul>
  </li>
</template>
