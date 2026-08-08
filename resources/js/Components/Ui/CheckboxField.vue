<script setup lang="ts">
/**
 * A checkbox with its label and explanation tied to it.
 *
 * The explanation sits in `aria-describedby` rather than merely next to the
 * box: several of these switches change what visitors of the shop can see,
 * and the label alone ("Skrývat prázdné kategorie") does not say so.
 */
import { computed, useId } from 'vue'

const props = defineProps<{
  label: string
  modelValue: boolean
  hint?: string
  error?: string
}>()

const emit = defineEmits<{ (e: 'update:modelValue', value: boolean): void }>()

const id = useId()
const hintId = computed(() => `${id}-hint`)
const errorId = computed(() => `${id}-error`)

const describedBy = computed(() => {
  const parts: string[] = []
  if (props.hint) parts.push(hintId.value)
  if (props.error) parts.push(errorId.value)

  return parts.length === 0 ? undefined : parts.join(' ')
})

const value = computed({
  get: () => props.modelValue,
  set: (next: boolean) => emit('update:modelValue', next),
})
</script>

<template>
  <div class="flex gap-3">
    <input
      :id="id"
      v-model="value"
      type="checkbox"
      class="mt-1 h-4 w-4 shrink-0 rounded border-gray-300 text-gray-900 focus:ring-gray-900"
      :aria-describedby="describedBy"
    />
    <div>
      <label :for="id" class="text-sm font-medium text-gray-700">{{ label }}</label>
      <p v-if="hint" :id="hintId" class="text-sm text-gray-600">{{ hint }}</p>
      <p v-if="error" :id="errorId" class="text-sm text-red-700">{{ error }}</p>
    </div>
  </div>
</template>
