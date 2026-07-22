<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    shops: Array<{ uuid: string; name: string; status: string; host: string | null }>;
}>();
</script>

<template>
    <Head title="Moje e-shopy" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Moje e-shopy
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <div class="mb-4 flex items-center justify-between">
                            <h3 class="text-lg font-semibold">Vaše e-shopy</h3>
                            <Link
                                href="/onboarding"
                                class="rounded bg-black px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-black focus-visible:ring-offset-2"
                            >
                                + Založit e-shop
                            </Link>
                        </div>

                        <p v-if="shops.length === 0" class="text-gray-500">
                            Zatím nemáte žádný e-shop.
                        </p>

                        <ul v-else class="space-y-2">
                            <li
                                v-for="shop in shops"
                                :key="shop.uuid"
                                class="flex items-center justify-between rounded border border-gray-200 p-3"
                            >
                                <div>
                                    <span class="font-medium">{{ shop.name }}</span>
                                    <span class="ml-2 text-sm text-gray-500">{{ shop.host }}</span>
                                    <span class="ml-2 text-xs uppercase tracking-wide text-gray-400">{{ shop.status }}</span>
                                </div>
                                <a
                                    v-if="shop.host"
                                    :href="`https://${shop.host}/admin`"
                                    class="text-sm text-blue-600 underline hover:text-blue-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-600 focus-visible:ring-offset-2"
                                >
                                    Spravovat
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
