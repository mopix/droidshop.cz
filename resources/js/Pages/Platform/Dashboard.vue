<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3'

defineProps<{
  admin: { name: string; email: string }
}>()

const page = usePage()
const recoveryCodes = (page.props.flash as { recoveryCodes?: string[] } | undefined)?.recoveryCodes

const logout = () => router.post('/superadmin/logout')
</script>

<template>
  <Head title="Správa platformy">
    <meta name="robots" content="noindex, nofollow" />
  </Head>

  <main class="platform">
    <header class="platform__bar">
      <strong>DroidShop — správa platformy</strong>
      <span>{{ admin.name }} ({{ admin.email }}) · <button @click="logout">Odhlásit</button></span>
    </header>

    <section v-if="recoveryCodes" class="platform__recovery">
      <h2>Obnovovací kódy</h2>
      <p>Uložte si je na bezpečné místo. Zobrazují se jen jednou.</p>
      <ul>
        <li v-for="code in recoveryCodes" :key="code"><code>{{ code }}</code></li>
      </ul>
    </section>

    <section class="platform__body">
      <p>Vítejte. Správa tenantů a metriky přibudou v další vlně.</p>
    </section>
  </main>
</template>

<style scoped>
.platform {
  min-height: 100vh;
  font-family: system-ui, sans-serif;
  background: #f8fafc;
}
.platform__bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1rem 1.5rem;
  background: #0f172a;
  color: #fff;
}
.platform__bar button {
  background: transparent;
  color: #fff;
  border: 1px solid #475569;
  border-radius: 0.375rem;
  padding: 0.25rem 0.5rem;
  cursor: pointer;
}
.platform__recovery {
  margin: 1.5rem;
  padding: 1rem 1.5rem;
  border: 1px solid #f59e0b;
  background: #fffbeb;
  border-radius: 0.5rem;
}
.platform__recovery ul {
  columns: 2;
  font-size: 1.05rem;
}
.platform__body {
  padding: 1.5rem;
}
</style>
