<script setup lang="ts">
/**
 * A labelled text input with its error message wired up.
 *
 * Extracted for the settings screens (wave 3.6), which add four forms and
 * around forty fields between them. Repeating the markup would mean repeating
 * the three things that are easy to get wrong and invisible when they are:
 * the label's `for`, the input's `aria-describedby`, and `aria-invalid`.
 */
import { computed, useId } from 'vue'

const props = withDefaults(
  defineProps<{
    label: string
    modelValue: string | null
    error?: string
    hint?: string
    type?: string
    /** Rendered as a <select> when given. */
    options?: { value: string; label: string }[]
    /** Rendered as a <textarea> with this many rows. */
    rows?: number
    placeholder?: string
    autocomplete?: string
    required?: boolean
    maxlength?: number
  }>(),
  { type: 'text' },
)

const emit = defineEmits<{ (e: 'update:modelValue', value: string): void }>()

const id = useId()
const errorId = computed(() => `${id}-error`)
const hintId = computed(() => `${id}-hint`)

const describedBy = computed(() => {
  const parts: string[] = []
  if (props.hint) parts.push(hintId.value)
  if (props.error) parts.push(errorId.value)

  return parts.length === 0 ? undefined : parts.join(' ')
})

const value = computed({
  get: () => props.modelValue ?? '',
  set: (next: string) => emit('update:modelValue', next),
})

const inputClass =
  'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900'
</script>

<template>
  <div>
    <label :for="id" class="block text-sm font-medium text-gray-700">
      {{ label }}
    </label>

    <select
      v-if="options"
      :id="id"
      v-model="value"
      :class="inputClass"
      :aria-describedby="describedBy"
      :aria-invalid="error ? 'true' : undefined"
    >
      <option v-for="option in options" :key="option.value" :value="option.value">
        {{ option.label }}
      </option>
    </select>

    <textarea
      v-else-if="rows"
      :id="id"
      v-model="value"
      :rows="rows"
      :maxlength="maxlength"
      :placeholder="placeholder"
      :class="inputClass"
      :aria-describedby="describedBy"
      :aria-invalid="error ? 'true' : undefined"
    />

    <input
      v-else
      :id="id"
      v-model="value"
      :type="type"
      :required="required"
      :maxlength="maxlength"
      :placeholder="placeholder"
      :autocomplete="autocomplete"
      :class="inputClass"
      :aria-describedby="describedBy"
      :aria-invalid="error ? 'true' : undefined"
    />

    <p v-if="hint" :id="hintId" class="mt-1 text-sm text-gray-600">{{ hint }}</p>
    <p v-if="error" :id="errorId" class="mt-1 text-sm text-red-700">{{ error }}</p>
  </div>
</template>
