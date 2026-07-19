<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'

const form = useForm({
  email: '',
  password: '',
  remember: false,
})

const submit = () => {
  form.transform((data) => ({ ...data, remember: data.remember }))
    .post('/superadmin/login', {
      onFinish: () => form.reset('password'),
    })
}
</script>

<template>
  <Head title="Přihlášení správce platformy">
    <meta name="robots" content="noindex, nofollow" />
  </Head>

  <main class="platform-auth">
    <form @submit.prevent="submit" class="platform-auth__card">
      <h1>Správa platformy</h1>

      <label>
        E-mail
        <input v-model="form.email" type="email" autocomplete="username" required autofocus />
      </label>
      <p v-if="form.errors.email" class="platform-auth__error">{{ form.errors.email }}</p>

      <label>
        Heslo
        <input v-model="form.password" type="password" autocomplete="current-password" required />
      </label>

      <label class="platform-auth__remember">
        <input v-model="form.remember" type="checkbox" />
        Zůstat přihlášen
      </label>

      <button type="submit" :disabled="form.processing">Přihlásit se</button>
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
.platform-auth__card label {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  font-size: 0.875rem;
}
.platform-auth__card input[type='email'],
.platform-auth__card input[type='password'] {
  padding: 0.5rem;
  border: 1px solid #cbd5e1;
  border-radius: 0.375rem;
}
.platform-auth__remember {
  flex-direction: row !important;
  align-items: center;
  gap: 0.5rem;
}
.platform-auth__error {
  color: #dc2626;
  font-size: 0.8rem;
  margin: 0;
}
.platform-auth__card button {
  margin-top: 0.5rem;
  padding: 0.6rem;
  background: #2563eb;
  color: #fff;
  border: 0;
  border-radius: 0.375rem;
  cursor: pointer;
}
</style>
