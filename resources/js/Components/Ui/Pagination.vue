<script setup lang="ts">
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'

export type PaginationLink = {
  url: string | null
  label: string
  active: boolean
}

export type PaginationMeta = {
  current_page?: number
  last_page?: number
  from?: number | null
  to?: number | null
  total?: number
}

const props = withDefaults(
  defineProps<{
    links: PaginationLink[]
    meta?: PaginationMeta | null
  }>(),
  { meta: null },
)

// Laravel ships the prev/next labels as English HTML entities; translate them
// and strip the markup so nothing raw ends up in the DOM.
const labelFor = (label: string): string => {
  const plain = label.replace(/&laquo;|&raquo;|<[^>]*>/g, '').trim()

  if (/Previous|Předchozí/i.test(plain)) return 'Předchozí'
  if (/Next|Další/i.test(plain)) return 'Další'

  return plain.replace(/&hellip;/g, '…')
}

const isEllipsis = (label: string) => labelFor(label) === '...' || labelFor(label) === '…'

const summary = computed(() => {
  const meta = props.meta

  if (!meta || !meta.total) return null

  return `Zobrazeno ${meta.from ?? 0}–${meta.to ?? 0} z ${meta.total} záznamů`
})

const hasNavigation = computed(() => props.links.filter((link) => link.url !== null).length > 0)
</script>

<template>
  <div
    v-if="hasNavigation || summary"
    class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
  >
    <p v-if="summary" class="text-sm text-gray-700">{{ summary }}</p>
    <span v-else />

    <nav v-if="hasNavigation" aria-label="Stránkování">
      <ul class="flex flex-wrap items-center gap-1">
        <li v-for="(link, index) in links" :key="index">
          <!-- Disabled links (no url) must not be focusable or clickable. -->
          <span
            v-if="link.url === null"
            class="inline-block rounded-md px-3 py-2 text-sm text-gray-400"
            :aria-hidden="isEllipsis(link.label) ? 'true' : undefined"
          >
            {{ labelFor(link.label) }}
          </span>

          <Link
            v-else
            :href="link.url"
            :aria-current="link.active ? 'page' : undefined"
            :aria-label="
              /^\d+$/.test(labelFor(link.label)) ? `Stránka ${labelFor(link.label)}` : undefined
            "
            preserve-scroll
            class="inline-block rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 aria-[current=page]:border-slate-900 aria-[current=page]:bg-slate-900 aria-[current=page]:font-semibold aria-[current=page]:text-white"
          >
            {{ labelFor(link.label) }}
          </Link>
        </li>
      </ul>
    </nav>
  </div>
</template>
