<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'

const form = useForm({ code: '' })

const submit = () => form.post('/superadmin/2fa/challenge', {
  onFinish: () => form.reset('code'),
})
</script>

<template>
  <Head title="Ověření">
    <meta name="robots" content="noindex, nofollow" />
  </Head>

  <main class="platform-auth">
    <form @submit.prevent="submit" class="platform-auth__card">
      <h1>Dvoufázové ověření</h1>
      <p>Zadejte kód z autentizační aplikace, nebo jeden z obnovovacích kódů.</p>

      <label>
        Kód
        <input v-model="form.code" inputmode="numeric" autocomplete="one-time-code" required autofocus />
      </label>
      <p v-if="form.errors.code" class="platform-auth__error">{{ form.errors.code }}</p>

      <button type="submit" :disabled="form.processing">Ověřit</button>
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
  width: min(22rem, 90vw);
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  padding: 2rem;
  background: #fff;
  border-radius: 0.75rem;
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
