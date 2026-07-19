<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'

defineProps<{
  secret: string
  qr: string
}>()

const form = useForm({ code: '' })

const submit = () => form.post('/superadmin/2fa/setup')
</script>

<template>
  <Head title="Nastavení dvoufázového ověření">
    <meta name="robots" content="noindex, nofollow" />
  </Head>

  <main class="platform-auth">
    <form @submit.prevent="submit" class="platform-auth__card">
      <h1>Zabezpečte účet</h1>
      <p>Dvoufázové ověření je pro správce platformy povinné. Naskenujte kód v aplikaci (Google Authenticator, 1Password…).</p>

      <p class="platform-auth__secret">Ruční klíč: <code>{{ secret }}</code></p>

      <label>
        Ověřovací kód
        <input v-model="form.code" inputmode="numeric" autocomplete="one-time-code" required autofocus />
      </label>
      <p v-if="form.errors.code" class="platform-auth__error">{{ form.errors.code }}</p>

      <button type="submit" :disabled="form.processing">Potvrdit a zapnout</button>
    </form>
  </main>
</template>

<style scoped>
.platform-auth {
  min-height: 100vh;
  display: grid;
  place-items: center;
  background: #0f172a;
  font-family: system-ui, sans-serif;
}
.platform-auth__card {
  width: min(26rem, 90vw);
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  padding: 2rem;
  background: #fff;
  border-radius: 0.75rem;
}
.platform-auth__secret code {
  font-size: 1rem;
  letter-spacing: 0.1em;
}
.platform-auth__card input {
  padding: 0.5rem;
  border: 1px solid #cbd5e1;
  border-radius: 0.375rem;
}
.platform-auth__error {
  color: #dc2626;
  font-size: 0.8rem;
  margin: 0;
}
.platform-auth__card button {
  padding: 0.6rem;
  background: #2563eb;
  color: #fff;
  border: 0;
  border-radius: 0.375rem;
  cursor: pointer;
}
</style>
