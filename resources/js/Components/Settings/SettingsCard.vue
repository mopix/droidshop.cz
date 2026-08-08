<script setup lang="ts">
/**
 * One titled group of settings.
 *
 * A <fieldset> with a <legend>, not a div with a heading: these are groups of
 * form controls, and the legend is what a screen reader announces with each
 * control inside it.
 */
defineProps<{
  legend: string
}>()
</script>

<template>
  <fieldset class="rounded-lg border border-gray-200 bg-white p-5">
    <legend class="px-1 text-sm font-medium text-gray-700">{{ legend }}</legend>

    <div class="mt-2 space-y-4">
      <slot />
    </div>
  </fieldset>
</template>
