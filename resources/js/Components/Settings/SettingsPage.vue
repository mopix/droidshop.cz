<script setup lang="ts">
/**
 * The shell every settings screen sits in.
 *
 * Extracted so the eight screens under NASTAVENÍ look like one another and
 * like the rest of the admin: full width, same heading, same spacing. They
 * each used to carry their own `mx-auto max-w-2xl`, which left a narrow
 * column floating in the middle of a full-width admin — and each one drifted
 * a little from the next.
 *
 * The intro text keeps a reading-width cap of its own. Full width is right
 * for a form laid out in columns; it is wrong for a paragraph, which becomes
 * unreadable past roughly 75 characters a line (WCAG 1.4.8).
 */
defineProps<{
  title: string
  description?: string
}>()
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-wrap items-start justify-between gap-4">
      <div class="max-w-3xl">
        <h1 class="text-lg font-semibold text-gray-900">{{ title }}</h1>
        <p v-if="description" class="mt-1 text-sm text-gray-600">{{ description }}</p>
        <slot name="intro" />
      </div>

      <div v-if="$slots.actions" class="shrink-0">
        <slot name="actions" />
      </div>
    </header>

    <slot />
  </div>
</template>
