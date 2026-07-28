<script setup lang="ts">
import { reactive, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

type Feed = {
  type: string
  enabled: boolean
  delivery_date: number
  url: string
}

type CategoryRow = {
  id: number
  name: string
  depth: number
  mapping: Record<string, string>
}

const props = defineProps<{
  feeds: Feed[]
  categories: CategoryRow[]
}>()

const LABELS: Record<string, string> = {
  heureka: 'Heureka.cz',
  zbozi: 'Zboží.cz',
}

const feeds = reactive(props.feeds.map((feed) => ({ ...feed })))
const rows = reactive(props.categories.map((row) => ({ ...row, mapping: { ...row.mapping } })))

const saving = ref<string | null>(null)

const saveFeed = (feed: Feed) => {
  saving.value = feed.type

  router.patch(
    route('admin.feeds.update', feed.type),
    { enabled: feed.enabled, delivery_date: feed.delivery_date },
    { preserveScroll: true, onFinish: () => (saving.value = null) },
  )
}

const saveMappings = (type: string) => {
  saving.value = `mapping-${type}`

  router.patch(
    route('admin.feeds.categories', type),
    {
      mappings: rows.map((row) => ({
        category_id: row.id,
        category_text: row.mapping[type] ?? '',
      })),
    },
    { preserveScroll: true, onFinish: () => (saving.value = null) },
  )
}
</script>

<template>
  <AdminLayout title="Feedy pro porovnávače">
    <div class="mx-auto max-w-5xl space-y-8 p-6">
      <header>
        <h1 class="text-2xl font-semibold text-gray-900">Feedy pro porovnávače</h1>
        <p class="mt-1 text-sm text-gray-600">
          Zapni feed a jeho adresu vlož do administrace porovnávače. Feed obsahuje ceny, které
          zákazník opravdu zaplatí, a každou variantu jako samostatnou položku.
        </p>
      </header>

      <section v-for="feed in feeds" :key="feed.type" class="rounded-lg border border-gray-200 bg-white p-6">
        <h2 class="text-lg font-medium text-gray-900">{{ LABELS[feed.type] ?? feed.type }}</h2>

        <div class="mt-4 space-y-4">
          <div class="flex items-start gap-2">
            <input :id="`enabled-${feed.type}`" v-model="feed.enabled" type="checkbox" class="mt-1" />
            <label :for="`enabled-${feed.type}`" class="text-sm text-gray-700">
              Zveřejnit feed. Vypnutý feed vrací 404, takže porovnávač nečte prázdný katalog.
            </label>
          </div>

          <div>
            <label :for="`delivery-${feed.type}`" class="block text-sm font-medium text-gray-700">
              Dodací lhůta u zboží mimo sklad (dny)
            </label>
            <input
              :id="`delivery-${feed.type}`"
              v-model.number="feed.delivery_date"
              type="number"
              min="1"
              max="365"
              class="mt-1 w-32 rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
            />
          </div>

          <p class="text-sm text-gray-600">
            Adresa feedu: <code class="rounded bg-gray-100 px-1 py-0.5">{{ feed.url }}</code>
          </p>

          <button
            type="button"
            class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:opacity-50"
            :disabled="saving === feed.type"
            @click="saveFeed(feed)"
          >
            Uložit nastavení
          </button>
        </div>
      </section>

      <section class="rounded-lg border border-gray-200 bg-white p-6">
        <h2 class="text-lg font-medium text-gray-900">Kategorie porovnávačů</h2>
        <p class="mt-1 text-sm text-gray-600">
          Vlož cestu z číselníku porovnávače, například
          <code class="rounded bg-gray-100 px-1 py-0.5">Elektronika | Počítače a kancelář | Klávesnice</code>.
          Prázdné pole znamená, že se použije tvůj vlastní strom kategorií.
        </p>

        <p v-if="rows.length === 0" class="mt-3 text-sm text-gray-600">
          Zatím nemáš žádné kategorie.
        </p>

        <table v-else class="mt-4 w-full text-left text-sm">
          <caption class="sr-only">Mapování kategorií na číselníky porovnávačů</caption>
          <thead>
            <tr class="text-gray-500">
              <th scope="col" class="py-2 pr-2 font-medium">Kategorie</th>
              <th scope="col" class="px-2 py-2 font-medium">Heureka.cz</th>
              <th scope="col" class="px-2 py-2 font-medium">Zboží.cz</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in rows" :key="row.id" class="border-t border-gray-100">
              <th scope="row" class="py-2 pr-2 text-left font-normal text-gray-900">
                <span :style="{ paddingLeft: `${row.depth * 16}px` }">{{ row.name }}</span>
              </th>
              <td v-for="type in ['heureka', 'zbozi']" :key="type" class="px-2 py-2">
                <label :for="`map-${type}-${row.id}`" class="sr-only">
                  Kategorie {{ row.name }} pro {{ LABELS[type] }}
                </label>
                <input
                  :id="`map-${type}-${row.id}`"
                  v-model="row.mapping[type]"
                  type="text"
                  class="w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
                />
              </td>
            </tr>
          </tbody>
        </table>

        <div v-if="rows.length > 0" class="mt-4 flex gap-2">
          <button
            v-for="type in ['heureka', 'zbozi']"
            :key="type"
            type="button"
            class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-900 hover:bg-gray-50 disabled:opacity-50"
            :disabled="saving === `mapping-${type}`"
            @click="saveMappings(type)"
          >
            Uložit mapování {{ LABELS[type] }}
          </button>
        </div>
      </section>
    </div>
  </AdminLayout>
</template>
