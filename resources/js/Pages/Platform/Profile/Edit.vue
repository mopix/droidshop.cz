<script setup lang="ts">
import { computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { ShieldCheck, ShieldAlert } from 'lucide-vue-next'
import PlatformLayout from '@/Layouts/PlatformLayout.vue'

const props = defineProps<{
  admin: {
    name: string
    email: string
    twoFactorConfirmedAt: string | null
    lastLoginAt: string | null
  }
  recoveryCodes: string[] | null
}>()

const profile = useForm({
  name: props.admin.name,
  email: props.admin.email,
  current_password: '',
})

const password = useForm({
  current_password: '',
  password: '',
  password_confirmation: '',
})

const codes = useForm({ current_password: '' })

/**
 * The current password is only asked for when the address actually changes —
 * see UpdatePlatformProfileRequest for why it is asked for at all.
 */
const emailChanged = computed(() => profile.email !== props.admin.email)

const twoFactorOn = computed(() => props.admin.twoFactorConfirmedAt !== null)

const formatted = (iso: string | null): string =>
  iso === null ? '—' : new Date(iso).toLocaleString('cs-CZ')

const submitProfile = () =>
  profile.patch(route('platform.profile.update'), {
    preserveScroll: true,
    onSuccess: () => profile.reset('current_password'),
  })

const submitPassword = () =>
  password.put(route('platform.profile.password'), {
    preserveScroll: true,
    onSuccess: () => password.reset(),
  })

const submitCodes = () =>
  codes.post(route('platform.profile.recoveryCodes'), {
    preserveScroll: true,
    onSuccess: () => codes.reset(),
  })
</script>

<template>
  <PlatformLayout title="Profil">
    <template #header>
      <h1 class="text-xl font-semibold text-gray-900">Můj účet</h1>
    </template>

    <div class="max-w-3xl space-y-6">
      <!--
        Shown once, immediately after generating. The codes are stored hashed,
        so this is the only moment they can be read.
      -->
      <section
        v-if="recoveryCodes"
        class="rounded-lg border border-amber-300 bg-amber-50 p-4"
        role="status"
      >
        <h2 class="text-sm font-semibold text-amber-900">Záložní kódy</h2>
        <p class="mt-1 text-sm text-amber-900">
          Uložte si je teď — znovu se nezobrazí. Každý kód lze použít jednou, když nemáte
          po ruce ověřovací aplikaci.
        </p>
        <ul class="mt-3 grid gap-1 font-mono text-sm text-amber-900 sm:grid-cols-2">
          <li v-for="code in recoveryCodes" :key="code">{{ code }}</li>
        </ul>
      </section>

      <section class="rounded-lg border border-gray-200 bg-white p-6">
        <h2 class="text-lg font-medium text-gray-900">Údaje účtu</h2>
        <p class="mt-1 text-sm text-gray-600">Jméno a e-mail správce platformy.</p>

        <form class="mt-6 space-y-4" @submit.prevent="submitProfile">
          <div>
            <label for="name" class="block text-sm font-medium text-gray-700">Jméno</label>
            <input
              id="name"
              v-model="profile.name"
              type="text"
              required
              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            />
            <p v-if="profile.errors.name" class="mt-1 text-sm text-red-600">{{ profile.errors.name }}</p>
          </div>

          <div>
            <label for="email" class="block text-sm font-medium text-gray-700">E-mail</label>
            <input
              id="email"
              v-model="profile.email"
              type="email"
              required
              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            />
            <p v-if="profile.errors.email" class="mt-1 text-sm text-red-600">{{ profile.errors.email }}</p>
          </div>

          <div v-if="emailChanged">
            <label for="profile_current_password" class="block text-sm font-medium text-gray-700">
              Současné heslo
            </label>
            <input
              id="profile_current_password"
              v-model="profile.current_password"
              type="password"
              autocomplete="current-password"
              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            />
            <p class="mt-1 text-sm text-gray-600">
              Změna e-mailu mění i to, kam chodí obnova hesla — proto ji potvrzujeme heslem.
            </p>
            <p v-if="profile.errors.current_password" class="mt-1 text-sm text-red-600">
              {{ profile.errors.current_password }}
            </p>
          </div>

          <button
            type="submit"
            :disabled="profile.processing"
            class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:opacity-50"
          >
            Uložit
          </button>
        </form>
      </section>

      <section class="rounded-lg border border-gray-200 bg-white p-6">
        <h2 class="text-lg font-medium text-gray-900">Změna hesla</h2>
        <p class="mt-1 text-sm text-gray-600">
          Používejte dostatečně dlouhé a náhodné heslo, ať je účet v bezpečí.
        </p>

        <form class="mt-6 space-y-4" @submit.prevent="submitPassword">
          <div>
            <label for="current_password" class="block text-sm font-medium text-gray-700">Současné heslo</label>
            <input
              id="current_password"
              v-model="password.current_password"
              type="password"
              autocomplete="current-password"
              required
              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            />
            <p v-if="password.errors.current_password" class="mt-1 text-sm text-red-600">
              {{ password.errors.current_password }}
            </p>
          </div>

          <div>
            <label for="password" class="block text-sm font-medium text-gray-700">Nové heslo</label>
            <input
              id="password"
              v-model="password.password"
              type="password"
              autocomplete="new-password"
              required
              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            />
            <p v-if="password.errors.password" class="mt-1 text-sm text-red-600">{{ password.errors.password }}</p>
          </div>

          <div>
            <label for="password_confirmation" class="block text-sm font-medium text-gray-700">
              Heslo pro kontrolu
            </label>
            <input
              id="password_confirmation"
              v-model="password.password_confirmation"
              type="password"
              autocomplete="new-password"
              required
              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            />
          </div>

          <button
            type="submit"
            :disabled="password.processing"
            class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:opacity-50"
          >
            Uložit
          </button>
        </form>
      </section>

      <section class="rounded-lg border border-gray-200 bg-white p-6">
        <h2 class="text-lg font-medium text-gray-900">Dvoufaktorové ověření</h2>

        <p class="mt-3 flex items-center gap-2 text-sm">
          <ShieldCheck v-if="twoFactorOn" class="h-5 w-5 text-emerald-600" aria-hidden="true" />
          <ShieldAlert v-else class="h-5 w-5 text-amber-600" aria-hidden="true" />
          <span v-if="twoFactorOn" class="text-gray-700">
            Aktivní od {{ formatted(admin.twoFactorConfirmedAt) }}
          </span>
          <span v-else class="text-gray-700">Není nastavené</span>
        </p>

        <p class="mt-1 text-sm text-gray-600">Poslední přihlášení: {{ formatted(admin.lastLoginAt) }}</p>

        <form v-if="twoFactorOn" class="mt-6 space-y-4" @submit.prevent="submitCodes">
          <div>
            <label for="codes_current_password" class="block text-sm font-medium text-gray-700">
              Současné heslo
            </label>
            <input
              id="codes_current_password"
              v-model="codes.current_password"
              type="password"
              autocomplete="current-password"
              required
              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            />
            <p v-if="codes.errors.current_password" class="mt-1 text-sm text-red-600">
              {{ codes.errors.current_password }}
            </p>
          </div>

          <p class="text-sm text-gray-600">
            Vygenerováním nových kódů přestanou platit ty staré.
          </p>

          <button
            type="submit"
            :disabled="codes.processing"
            class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
          >
            Vygenerovat nové záložní kódy
          </button>
        </form>
      </section>
    </div>
  </PlatformLayout>
</template>
