<script setup>
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div
        class="flex min-h-screen flex-col items-center bg-gray-100 pt-6 sm:justify-center sm:pt-0"
    >
        <!--
            The platform's own wordmark, not the Laravel logo Breeze ships
            with. This is the first screen a prospective tenant sees, and a
            framework's logo on it says nothing about whose product this is.
        -->
        <div>
            <Link
                href="/"
                class="text-2xl font-bold tracking-tight text-gray-900 hover:text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
            >
                DroidShop<span class="text-gray-400">.cz</span>
            </Link>
        </div>

        <div
            class="mt-6 w-full overflow-hidden bg-white px-6 py-4 shadow-md sm:max-w-md sm:rounded-lg"
        >
            <slot />
        </div>
    </div>
</template>
