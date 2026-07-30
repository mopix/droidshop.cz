<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import PlatformLayout from '@/Layouts/PlatformLayout.vue'

defineProps<{
  plans: { id: number; key: string; name: string; level: string; tenants: number; modules: number }[]
}>()
</script>

<template>
  <PlatformLayout title="Tarify">
    <h1 class="text-lg font-semibold text-gray-900">Tarify</h1>
    <p class="mt-1 text-sm text-gray-600">
      Které moduly tarif zahrnuje. Změna se propíše i do e-shopů, které tarif už provozují.
    </p>

    <div class="mt-6 overflow-x-auto rounded-md border border-gray-200">
      <table class="min-w-full divide-y divide-gray-200 text-sm">
        <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-600">
          <tr>
            <th scope="col" class="px-4 py-2">Tarif</th>
            <th scope="col" class="px-4 py-2">Úroveň</th>
            <th scope="col" class="px-4 py-2">E-shopů</th>
            <th scope="col" class="px-4 py-2">Modulů</th>
            <th scope="col" class="px-4 py-2"><span class="sr-only">Akce</span></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-200 bg-white">
          <tr v-for="plan in plans" :key="plan.id">
            <td class="px-4 py-2 font-medium text-gray-900">{{ plan.name }}</td>
            <td class="px-4 py-2 text-gray-700">{{ plan.level }}</td>
            <td class="px-4 py-2 text-gray-700">{{ plan.tenants }}</td>
            <td class="px-4 py-2 text-gray-700">{{ plan.modules }}</td>
            <td class="px-4 py-2 text-right">
              <Link
                :href="route('platform.plans.show', plan.id)"
                class="font-medium text-gray-900 underline hover:no-underline"
              >
                Upravit moduly
              </Link>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </PlatformLayout>
</template>
