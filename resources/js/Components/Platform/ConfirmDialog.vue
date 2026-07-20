<script setup lang="ts">
import { computed, nextTick, ref, useId, watch } from 'vue'
import Modal from '@/Components/Modal.vue'
import InputError from '@/Components/InputError.vue'
import InputLabel from '@/Components/InputLabel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import DangerButton from '@/Components/DangerButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

const props = withDefaults(
  defineProps<{
    show: boolean
    title: string
    message?: string
    confirmLabel?: string
    cancelLabel?: string
    danger?: boolean
    requireReason?: boolean
    reasonLabel?: string
    /** Server-side validation error for the reason field. */
    reasonError?: string
    processing?: boolean
  }>(),
  {
    message: '',
    confirmLabel: 'Potvrdit',
    cancelLabel: 'Zrušit',
    danger: false,
    requireReason: false,
    reasonLabel: 'Důvod',
    reasonError: '',
    processing: false,
  },
)

const emit = defineEmits<{
  (e: 'confirm', reason: string): void
  (e: 'cancel'): void
}>()

const uid = useId()
const titleId = `confirm-title-${uid}`
const messageId = `confirm-message-${uid}`
const reasonId = `confirm-reason-${uid}`
const reasonErrorId = `confirm-reason-error-${uid}`

const reason = ref('')
const root = ref<HTMLElement | null>(null)
// Wrapper around the optional default slot (extra fields such as a target
// status or plan). Focus goes there first when it holds anything focusable.
const extras = ref<HTMLElement | null>(null)
const textarea = ref<HTMLTextAreaElement | null>(null)
const confirmButton = ref<{ $el?: HTMLElement } | null>(null)
// Element that opened the dialog — focus goes back there on close.
const trigger = ref<HTMLElement | null>(null)

const canConfirm = computed(
  () => !props.processing && (!props.requireReason || reason.value.trim().length > 0),
)

const focusFirst = () => {
  const extra = extras.value?.querySelector<HTMLElement>(
    'select, input, textarea, button, [href], [tabindex]:not([tabindex="-1"])',
  )

  if (extra) {
    extra.focus()

    return
  }

  if (props.requireReason) {
    textarea.value?.focus()

    return
  }

  const el = confirmButton.value?.$el ?? (confirmButton.value as unknown as HTMLElement | null)
  el?.focus?.()
}

watch(
  () => props.show,
  async (show) => {
    if (show) {
      trigger.value = document.activeElement as HTMLElement | null
      reason.value = ''
      await nextTick()
      focusFirst()

      // Breeze's Modal renders a bare <dialog>; give it an accessible name.
      const dialog = root.value?.closest('dialog')
      dialog?.setAttribute('aria-labelledby', titleId)
      if (props.message) dialog?.setAttribute('aria-describedby', messageId)

      return
    }

    trigger.value?.focus?.()
  },
)

const cancel = () => emit('cancel')

const confirm = () => {
  if (!canConfirm.value) return

  emit('confirm', reason.value.trim())
}
</script>

<template>
  <!-- Modal closes on Esc and on backdrop click; both map to cancel. -->
  <Modal :show="show" max-width="lg" @close="cancel">
    <div ref="root" class="p-6">
      <h2 :id="titleId" class="text-lg font-semibold text-gray-900">{{ title }}</h2>

      <p v-if="message" :id="messageId" class="mt-2 text-sm text-gray-700">{{ message }}</p>

      <div v-if="$slots.default" ref="extras" class="mt-4">
        <slot />
      </div>

      <div v-if="requireReason" class="mt-4">
        <InputLabel :for="reasonId">
          {{ reasonLabel }} <span aria-hidden="true">*</span>
          <span class="sr-only">(povinné)</span>
        </InputLabel>

        <textarea
          :id="reasonId"
          ref="textarea"
          v-model="reason"
          rows="3"
          required
          aria-required="true"
          :aria-invalid="reasonError ? 'true' : undefined"
          :aria-describedby="reasonError ? reasonErrorId : undefined"
          class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-slate-900 focus:ring-slate-900"
        />

        <InputError :id="reasonErrorId" class="mt-1" :message="reasonError" />

        <p v-if="!reason.trim()" class="mt-1 text-sm text-gray-600">
          Bez uvedení důvodu nelze akci potvrdit.
        </p>
      </div>

      <div class="mt-6 flex justify-end gap-3">
        <SecondaryButton type="button" @click="cancel">{{ cancelLabel }}</SecondaryButton>

        <DangerButton
          v-if="danger"
          ref="confirmButton"
          type="button"
          :disabled="!canConfirm"
          class="disabled:opacity-50"
          @click="confirm"
        >
          {{ confirmLabel }}
        </DangerButton>

        <PrimaryButton
          v-else
          ref="confirmButton"
          type="button"
          :disabled="!canConfirm"
          class="disabled:opacity-50"
          @click="confirm"
        >
          {{ confirmLabel }}
        </PrimaryButton>
      </div>
    </div>
  </Modal>
</template>
