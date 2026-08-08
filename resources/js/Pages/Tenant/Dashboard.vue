<script setup lang="ts">
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { ExternalLink } from 'lucide-vue-next'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import NavIcon from '@/Components/Ui/NavIcon.vue'

const props = defineProps<{
  shop: { name: string; url: string | null }
  summary: {
    awaiting: number
    unpaid: number
    placed: number
    revenue: number
    windowDays: number
    currency: string
  }
  usage: { products: number; storageMb: number }
  links: Record<string, string | null>
}>()

/**
 * Money arrives in minor units, the way it is stored everywhere in this
 * project; formatting is presentation and belongs here rather than in a
 * server-side string the page cannot re-format.
 */
const revenue = computed(() =>
  new Intl.NumberFormat('cs-CZ', {
    style: 'currency',
    currency: props.summary.currency,
    maximumFractionDigits: 0,
  }).format(props.summary.revenue / 100),
)

const tiles = computed(() => [
  {
    label: 'Čeká na vyřízení',
    value: String(props.summary.awaiting),
    icon: 'receipt',
    href: props.links.orders,
    hint: 'Nové objednávky',
  },
  {
    label: 'Nezaplaceno',
    value: String(props.summary.unpaid),
    icon: 'file-text',
    href: props.links.orders,
    hint: 'Objednávky bez platby',
  },
  {
    label: `Objednávky / ${props.summary.windowDays} dní`,
    value: String(props.summary.placed),
    icon: 'package',
    href: props.links.orders,
    hint: 'Přijaté objednávky',
  },
  {
    label: `Tržba / ${props.summary.windowDays} dní`,
    value: revenue.value,
    icon: 'tag',
    href: props.links.orders,
    hint: 'Bez stornovaných',
  },
])

/**
 * Quick links, minus whatever this shop cannot reach. A dead entry on the
 * first screen of the admin is worse than a shorter list.
 */
const quickLinks = computed(() =>
  [
    { label: 'Přidat produkt', href: props.links.products },
    { label: 'Upravit vzhled e-shopu', href: props.links.appearance },
    { label: 'Nastavit vlastní doménu', href: props.links.domain },
    { label: 'Fakturační údaje', href: props.links.billing },
  ].filter((link): link is { label: string; href: string } => link.href !== null),
)
</script>

<template>
  <AdminLayout title="Nástěnka">
    <template #header>
      <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-semibold text-gray-900">Nástěnka</h1>

        <a
          v-if="shop.url"
          :href="shop.url"
          target="_blank"
          rel="noopener"
          class="inline-flex items-center gap-2 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
        >
          Zobrazit e-shop
          <ExternalLink class="h-4 w-4" aria-hidden="true" />
        </a>
      </div>
    </template>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <component
        :is="tile.href ? Link : 'div'"
        v-for="tile in tiles"
        :key="tile.label"
        :href="tile.href ?? undefined"
        class="rounded-lg border border-gray-200 bg-white p-4"
        :class="tile.href ? 'hover:border-gray-300 hover:shadow-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900' : ''"
      >
        <span class="flex items-center gap-2 text-sm text-gray-600">
          <NavIcon :name="tile.icon" class="h-4 w-4" />
          {{ tile.label }}
        </span>
        <span class="mt-2 block text-2xl font-semibold text-gray-900">{{ tile.value }}</span>
        <span class="mt-1 block text-xs text-gray-500">{{ tile.hint }}</span>
      </component>
    </div>

    <div class="mt-6 grid gap-4 lg:grid-cols-2">
      <section class="rounded-lg border border-gray-200 bg-white p-4">
        <h2 class="text-sm font-semibold text-gray-900">Využití tarifu</h2>
        <dl class="mt-3 space-y-2 text-sm">
          <div class="flex justify-between">
            <dt class="text-gray-600">Produkty</dt>
            <dd class="font-medium text-gray-900">{{ usage.products }}</dd>
          </div>
          <div class="flex justify-between">
            <dt class="text-gray-600">Úložiště</dt>
            <dd class="font-medium text-gray-900">{{ usage.storageMb }} MB</dd>
          </div>
        </dl>
      </section>

      <section class="rounded-lg border border-gray-200 bg-white p-4">
        <h2 class="text-sm font-semibold text-gray-900">Rychlé odkazy</h2>
        <ul class="mt-3 space-y-2 text-sm">
          <li v-for="link in quickLinks" :key="link.label">
            <Link :href="link.href" class="text-indigo-600 underline hover:text-indigo-800">
              {{ link.label }}
            </Link>
          </li>
        </ul>
      </section>
    </div>
  </AdminLayout>
</template>
