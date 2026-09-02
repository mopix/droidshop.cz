<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { router, useForm, usePage } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import SettingsPage from '@/Components/Settings/SettingsPage.vue'
import SettingsGrid from '@/Components/Settings/SettingsGrid.vue'
import SettingsCard from '@/Components/Settings/SettingsCard.vue'

interface ExportReport {
  tables?: number
  rows?: number
  files?: number
  bytes?: number
  skipped?: Record<string, string>
  redacted?: Record<string, string[]>
  error?: string
}

interface LatestExport {
  id: number
  status: 'pending' | 'running' | 'finished' | 'failed'
  running: boolean
  createdAt: string | null
  finishedAt: string | null
  report: ExportReport | null
  downloadUrl: string | null
}

const props = defineProps<{ latest: LatestExport | null }>()

const form = useForm({})
const submit = () => form.post(route('admin.export.store'), { preserveScroll: true })

const errors = computed(() => usePage().props.errors as Record<string, string>)

/**
 * The export runs on a queue, so the page has to learn when it finished. A
 * poll, not a websocket: it runs only while an export is actually in flight
 * and stops the moment it is not.
 */
let timer: number | undefined

function stopPolling() {
  if (timer !== undefined) {
    window.clearInterval(timer)
    timer = undefined
  }
}

onMounted(() => {
  if (!props.latest?.running) return

  timer = window.setInterval(() => {
    router.reload({
      only: ['latest'],
      onSuccess: () => {
        if (!(usePage().props.latest as LatestExport | null)?.running) stopPolling()
      },
    })
  }, 5000)
})

onBeforeUnmount(stopPolling)

function formatBytes(bytes: number | undefined): string {
  if (!bytes) return '—'
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} kB`

  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

function formatDate(value: string | null): string {
  if (!value) return '—'

  return new Date(value).toLocaleString('cs-CZ')
}

const statusLabel = computed(() => {
  switch (props.latest?.status) {
    case 'pending':
      return 'Čeká ve frontě'
    case 'running':
      return 'Probíhá'
    case 'finished':
      return 'Hotovo'
    case 'failed':
      return 'Selhalo'
    default:
      return '—'
  }
})
</script>

<template>
  <AdminLayout title="Export dat">
    <SettingsPage
      title="Export dat"
      description="Kompletní kopie všeho, co váš e-shop obsahuje — produkty, objednávky, zákazníci, nastavení i nahrané soubory."
    >
      <SettingsGrid>
        <SettingsCard legend="Stažení dat">
          <p class="text-sm text-gray-700">
            Archiv je ZIP se soubory JSON (jedna tabulka = jeden soubor) a se všemi obrázky a doklady.
            Připravuje se na pozadí; u velkého e-shopu to může trvat několik minut.
          </p>

          <p class="mt-3 text-sm text-gray-700">
            Archiv obsahuje osobní údaje vašich zákazníků. Zacházejte s ním jako s databází —
            odkaz ke stažení platí 15 minut a je vázaný na vaše přihlášení.
          </p>

          <p
            v-if="errors.export"
            class="mt-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-800"
            role="alert"
          >
            {{ errors.export }}
          </p>

          <form class="mt-4" @submit.prevent="submit">
            <button
              type="submit"
              class="inline-flex items-center rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-900 disabled:opacity-50"
              :disabled="form.processing || latest?.running"
            >
              {{ latest?.running ? 'Export probíhá…' : 'Připravit export dat' }}
            </button>
          </form>
        </SettingsCard>

        <SettingsCard legend="Poslední export">
          <p v-if="!latest" class="text-sm text-gray-600">
            Zatím jste žádný export nepřipravovali.
          </p>

          <div v-else class="space-y-3 text-sm">
            <!-- aria-live so a screen reader hears the poll finish, not just sighted users -->
            <dl class="grid grid-cols-[auto,1fr] gap-x-4 gap-y-1" aria-live="polite">
              <dt class="text-gray-600">Stav</dt>
              <dd class="text-gray-900">{{ statusLabel }}</dd>

              <dt class="text-gray-600">Zadáno</dt>
              <dd class="text-gray-900">{{ formatDate(latest.createdAt) }}</dd>

              <template v-if="latest.finishedAt">
                <dt class="text-gray-600">Dokončeno</dt>
                <dd class="text-gray-900">{{ formatDate(latest.finishedAt) }}</dd>
              </template>

              <template v-if="latest.status === 'finished' && latest.report">
                <dt class="text-gray-600">Obsah</dt>
                <dd class="text-gray-900">
                  {{ latest.report.tables ?? 0 }} tabulek,
                  {{ latest.report.rows ?? 0 }} řádků,
                  {{ latest.report.files ?? 0 }} souborů
                </dd>

                <dt class="text-gray-600">Velikost</dt>
                <dd class="text-gray-900">{{ formatBytes(latest.report.bytes) }}</dd>
              </template>
            </dl>

            <p
              v-if="latest.status === 'failed'"
              class="rounded-md bg-red-50 px-3 py-2 text-red-800"
              role="alert"
            >
              Export se nepodařilo dokončit.
              <span v-if="latest.report?.error">{{ latest.report.error }}</span>
            </p>

            <a
              v-if="latest.downloadUrl"
              :href="latest.downloadUrl"
              class="inline-flex items-center rounded-md border border-gray-300 px-4 py-2 font-medium text-gray-900 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-900"
            >
              Stáhnout archiv
            </a>

            <p
              v-else-if="latest.status === 'finished'"
              class="text-gray-600"
            >
              Archiv už není k dispozici. Připravte si nový.
            </p>

            <!-- Named, so the archive never overstates what it contains. -->
            <div v-if="latest.report?.skipped && Object.keys(latest.report.skipped).length" class="pt-2">
              <p class="text-gray-600">V archivu záměrně nejsou:</p>
              <ul class="mt-1 list-inside list-disc text-gray-700">
                <li v-for="(reason, table) in latest.report.skipped" :key="table">
                  <code>{{ table }}</code> — {{ reason }}
                </li>
              </ul>
            </div>
          </div>
        </SettingsCard>
      </SettingsGrid>
    </SettingsPage>
  </AdminLayout>
</template>
