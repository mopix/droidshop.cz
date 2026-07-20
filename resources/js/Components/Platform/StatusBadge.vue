<script setup lang="ts">
import { computed } from 'vue'

/** Mirrors App\Core\Enums\TenantStatus. */
export type TenantStatus =
  | 'trial'
  | 'active'
  | 'past_due'
  | 'suspended'
  | 'pending_deletion'
  | 'deleted'

const props = defineProps<{
  status: TenantStatus | string
  /** Server-side label (TenantStatus::label()); the map below is only a fallback. */
  label?: string
}>()

// Colour is never the only carrier of meaning — the text label always ships
// with it. Palettes keep >= 4.5:1 contrast on their own background.
const styles: Record<string, string> = {
  trial: 'bg-sky-50 text-sky-900 ring-sky-600/40',
  active: 'bg-emerald-50 text-emerald-900 ring-emerald-700/40',
  past_due: 'bg-amber-50 text-amber-900 ring-amber-700/40',
  suspended: 'bg-orange-50 text-orange-900 ring-orange-700/40',
  pending_deletion: 'bg-red-50 text-red-900 ring-red-700/40',
  deleted: 'bg-gray-100 text-gray-800 ring-gray-500/40',
}

const fallbackLabels: Record<string, string> = {
  trial: 'Zkušební období',
  active: 'Aktivní',
  past_due: 'Po splatnosti',
  suspended: 'Pozastaveno',
  pending_deletion: 'Čeká na smazání',
  deleted: 'Smazáno',
}

const badgeClass = computed(() => styles[props.status] ?? styles.deleted)
const text = computed(() => props.label ?? fallbackLabels[props.status] ?? props.status)
</script>

<template>
  <span
    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset"
    :class="badgeClass"
  >
    <span class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true" />
    {{ text }}
  </span>
</template>
