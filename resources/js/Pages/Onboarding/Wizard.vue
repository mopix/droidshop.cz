<script setup lang="ts">
import { ref, watch } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import ApplicationLogo from '@/Components/ApplicationLogo.vue'
import InputLabel from '@/Components/InputLabel.vue'
import InputError from '@/Components/InputError.vue'
import TextInput from '@/Components/TextInput.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

interface Plan {
  id: number
  key: string
  name: string
  price_month: number
  price_year: number
  limits: Record<string, number>
}

const props = defineProps<{
  plans: Plan[]
}>()

const step = ref<1 | 2>(1)

const form = useForm({
  shop_name: '',
  subdomain: '',
  plan_id: props.plans[0]?.id ?? null,
})

type AvailabilityReason = 'idle' | 'checking' | 'ok' | 'invalid' | 'reserved' | 'taken' | 'error'
type AvailabilityResponse = { available: boolean; reason: Exclude<AvailabilityReason, 'idle' | 'checking' | 'error'> }

const availability = ref<AvailabilityReason>('idle')
let checkTimer: ReturnType<typeof setTimeout> | null = null

watch(
  () => form.subdomain,
  (slug) => {
    availability.value = 'idle'
    if (checkTimer) clearTimeout(checkTimer)
    if (!slug) return

    availability.value = 'checking'
    checkTimer = setTimeout(async () => {
      try {
        const response = await fetch(route('onboarding.subdomain.check', { slug }), {
          headers: { Accept: 'application/json' },
          cache: 'no-store',
        })
        if (slug !== form.subdomain) return
        if (!response.ok) {
          availability.value = 'error'
          return
        }
        const data = (await response.json()) as AvailabilityResponse
        if (slug !== form.subdomain) return
        availability.value = data.reason
      } catch {
        if (slug !== form.subdomain) return
        availability.value = 'error'
      }
    }, 350)
  },
)

const money = (haleru: number) =>
  new Intl.NumberFormat('cs-CZ', { style: 'currency', currency: 'CZK' }).format(haleru / 100)

const goToStep2 = () => {
  step.value = 2
}

const goToStep1 = () => {
  step.value = 1
}

const submit = () => {
  if (step.value !== 2) return
  form.post(route('onboarding.store'))
}
</script>

<template>
  <Head title="Vytvořit e-shop">
    <meta name="robots" content="noindex, nofollow" />
  </Head>

  <div class="min-h-screen bg-gray-100">
    <header class="border-b border-gray-200 bg-white">
      <div class="mx-auto flex max-w-3xl items-center justify-between px-4 py-3 sm:px-6">
        <ApplicationLogo class="h-8 w-8 fill-current text-gray-700" />
        <button
          type="button"
          class="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
          @click="router.post(route('logout'))"
        >
          Odhlásit
        </button>
      </div>
    </header>

    <main class="mx-auto max-w-xl px-4 py-10 sm:px-6">
      <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
        <h1 class="text-xl font-semibold text-gray-900">Vytvořit e-shop</h1>
        <p class="mt-1 text-sm text-gray-600">14 dní zdarma, zrušit lze kdykoli.</p>

        <ol class="mt-6 mb-6 flex gap-4 text-sm" aria-label="Kroky průvodce">
          <li
            :aria-current="step === 1 ? 'step' : undefined"
            :class="step === 1 ? 'font-semibold text-gray-900' : 'text-gray-500'"
          >
            1. Obchod
          </li>
          <li
            :aria-current="step === 2 ? 'step' : undefined"
            :class="step === 2 ? 'font-semibold text-gray-900' : 'text-gray-500'"
          >
            2. Tarif
          </li>
        </ol>

        <form @submit.prevent="submit">
          <section v-show="step === 1">
            <div>
              <InputLabel for="shop_name">Název e-shopu</InputLabel>
              <TextInput
                id="shop_name"
                v-model="form.shop_name"
                type="text"
                required
                autofocus
                class="mt-1 w-full"
              />
              <InputError class="mt-1" :message="form.errors.shop_name" />
            </div>

            <div class="mt-4">
              <InputLabel for="subdomain">Subdoména</InputLabel>
              <div class="mt-1 flex items-stretch">
                <TextInput
                  id="subdomain"
                  v-model="form.subdomain"
                  type="text"
                  required
                  class="w-full rounded-r-none"
                  aria-describedby="subdomain-status"
                />
                <span class="inline-flex items-center rounded-r-md border border-l-0 border-gray-300 bg-gray-50 px-3 text-sm text-gray-500">
                  .droidshop.cz
                </span>
              </div>

              <p id="subdomain-status" class="mt-1 text-sm" role="status">
                <span v-if="availability === 'checking'" class="text-gray-500">Ověřuji dostupnost…</span>
                <span v-else-if="availability === 'ok'" class="text-emerald-700">Subdoména je dostupná.</span>
                <span v-else-if="availability === 'taken'" class="text-red-600">Tato subdoména je již obsazená.</span>
                <span v-else-if="availability === 'reserved'" class="text-red-600">Tato subdoména je rezervovaná.</span>
                <span v-else-if="availability === 'invalid'" class="text-red-600">Neplatný formát subdomény.</span>
                <span v-else-if="availability === 'error'" class="text-red-600">Ověření selhalo, upravte pole a zkuste znovu.</span>
              </p>
              <InputError class="mt-1" :message="form.errors.subdomain" />
            </div>

            <PrimaryButton
              type="button"
              class="mt-6 w-full justify-center"
              :disabled="!form.shop_name || availability !== 'ok'"
              @click="goToStep2"
            >
              Pokračovat
            </PrimaryButton>
          </section>

          <section v-show="step === 2">
            <fieldset>
              <legend class="text-sm font-semibold text-gray-900">Vyberte tarif</legend>

              <label
                v-for="plan in plans"
                :key="plan.id"
                class="mt-3 flex cursor-pointer items-center gap-3 rounded-md border border-gray-200 p-3 has-[:checked]:border-gray-900 has-[:checked]:ring-1 has-[:checked]:ring-gray-900"
              >
                <input
                  v-model="form.plan_id"
                  type="radio"
                  name="plan_id"
                  :value="plan.id"
                  class="border-gray-300 text-gray-900 focus:ring-gray-900"
                />
                <span class="flex-1 text-sm font-medium text-gray-900">{{ plan.name }}</span>
                <span class="text-sm text-gray-600">{{ money(plan.price_month) }} / měs</span>
              </label>

              <InputError class="mt-2" :message="form.errors.plan_id" />
            </fieldset>

            <div class="mt-6 flex gap-3">
              <SecondaryButton type="button" @click="goToStep1">Zpět</SecondaryButton>
              <PrimaryButton
                type="submit"
                class="flex-1 justify-center"
                :disabled="form.processing || !form.plan_id || step !== 2"
              >
                Vytvořit e-shop
              </PrimaryButton>
            </div>
          </section>
        </form>
      </div>
    </main>
  </div>
</template>
