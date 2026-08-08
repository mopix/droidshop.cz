<script setup lang="ts">
/**
 * Two columns of settings cards from `lg` up, one below it.
 *
 * `items-start` so a short card does not stretch to the height of the tall one
 * beside it. One column below `lg`, because two columns of form fields on a
 * tablet leave each one too narrow to type in comfortably.
 */
</script>

<template>
  <div class="grid items-start gap-6 lg:grid-cols-2">
    <slot />
  </div>
</template>
