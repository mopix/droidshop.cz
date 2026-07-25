<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import ConfirmDialog from '@/Components/Ui/ConfirmDialog.vue'

type BlockPayload = Record<string, unknown>

type Block = {
  id: number
  type: string
  payload: BlockPayload
  visible: boolean
}

const props = defineProps<{
  blocks: Block[]
  blockTypes: string[]
}>()

/** Czech labels for the block types the server knows about (`BlockType` enum). */
const TYPE_LABELS: Record<string, string> = {
  hero: 'Hero (úvodní)',
  product_row: 'Řada produktů',
  category_grid: 'Mřížka kategorií',
  text: 'Textový blok',
  banner: 'Banner',
}

const typeLabel = (type: string) => TYPE_LABELS[type] ?? type

// --- Add block --------------------------------------------------------

const addForm = useForm({ type: props.blockTypes[0] ?? 'hero' })

const submitAdd = () =>
  addForm.post(route('admin.storefront.homepage.store'), {
    preserveScroll: true,
  })

// --- Reorder / visibility ----------------------------------------------
// Buttons rather than drag and drop: dragging is not reachable from the
// keyboard, and this screen has to be (WCAG 2.1.1) — same reasoning as
// Categories/Index.vue.

const move = (block: Block, direction: 'up' | 'down') =>
  router.patch(
    route('admin.storefront.homepage.move', block.id),
    { direction },
    { preserveScroll: true },
  )

const toggleVisibility = (block: Block) =>
  router.patch(
    route('admin.storefront.homepage.toggle', block.id),
    { visible: !block.visible },
    { preserveScroll: true },
  )

// --- Delete (confirmed) --------------------------------------------------

const deleting = ref<Block | null>(null)

const confirmDelete = () => {
  const block = deleting.value

  if (!block) return

  router.delete(route('admin.storefront.homepage.destroy', block.id), {
    preserveScroll: true,
    onFinish: () => (deleting.value = null),
  })
}

// --- Edit ------------------------------------------------------------
// One inline panel open at a time. The payload shape is free-form JSON that
// differs per block type, so edit state is a plain reactive object rather
// than a typed useForm — payloadFor() below is what turns it back into the
// shape the server expects for the given type.

const editingId = ref<number | null>(null)
const editState = ref<Record<string, any> | null>(null)
const existingImagePath = ref<string | null>(null)
const editErrors = ref<Record<string, string>>({})
const editProcessing = ref(false)
const editImageInput = ref<HTMLInputElement | null>(null)

/** list("1, 2, 3") -> number[]; blank/non-numeric entries are dropped. */
const parseIdList = (value: string): number[] =>
  value
    .split(',')
    .map((part) => part.trim())
    .filter((part) => part !== '')
    .map((part) => Number(part))
    .filter((n) => Number.isFinite(n))

const startEdit = (block: Block) => {
  const p = block.payload

  editingId.value = block.id
  editErrors.value = {}
  existingImagePath.value = (p.image_path as string | null) ?? null

  editState.value = {
    // hero
    title: (p.title as string) ?? '',
    subtitle: (p.subtitle as string) ?? '',
    cta_label: (p.cta_label as string) ?? '',
    cta_url: (p.cta_url as string) ?? '',
    // product_row / category_grid / text share "heading"
    heading: (p.heading as string) ?? '',
    mode: (p.mode as string) ?? 'latest',
    count: (p.count as number) ?? 8,
    product_ids: ((p.product_ids as number[]) ?? []).join(', '),
    category_ids: ((p.category_ids as number[]) ?? []).join(', '),
    // text
    html: (p.html as string) ?? '',
    // banner
    url: (p.url as string) ?? '',
    alt: (p.alt as string) ?? '',
    // hero + banner
    image: null as File | null,
  }
}

const cancelEdit = () => {
  editingId.value = null
  editState.value = null
  editErrors.value = {}
}

const onEditImageChange = (event: Event) => {
  if (!editState.value) return

  editState.value.image = (event.target as HTMLInputElement).files?.[0] ?? null
}

/** Builds the `payload` object the server expects for this block's type. */
const payloadFor = (type: string, state: Record<string, any>): BlockPayload => {
  switch (type) {
    case 'hero':
      return {
        title: state.title,
        subtitle: state.subtitle || null,
        cta_label: state.cta_label || null,
        cta_url: state.cta_url || null,
      }
    case 'product_row':
      return {
        heading: state.heading,
        mode: state.mode,
        count: Number(state.count) || 1,
        product_ids: parseIdList(state.product_ids),
      }
    case 'category_grid':
      return {
        heading: state.heading,
        category_ids: parseIdList(state.category_ids),
      }
    case 'text':
      return {
        heading: state.heading || null,
        html: state.html,
      }
    case 'banner':
      return {
        url: state.url || null,
        alt: state.alt,
      }
    default:
      return {}
  }
}

const submitEdit = (block: Block) => {
  const state = editState.value

  if (!state) return

  const data: Record<string, unknown> = { payload: payloadFor(block.type, state) }

  if (state.image) {
    data.image = state.image
  }

  editProcessing.value = true

  router.patch(route('admin.storefront.homepage.update', block.id), data, {
    forceFormData: true,
    preserveScroll: true,
    onError: (errors) => {
      editErrors.value = errors as Record<string, string>
    },
    onSuccess: () => cancelEdit(),
    onFinish: () => {
      editProcessing.value = false
      if (editImageInput.value) editImageInput.value.value = ''
    },
  })
}

const errorFor = (field: string) => editErrors.value[field]
const describedByFor = (id: string, field: string) => (errorFor(field) ? `${id}-error` : undefined)

const isFirst = (block: Block) => props.blocks[0]?.id === block.id
const isLast = (block: Block) => props.blocks[props.blocks.length - 1]?.id === block.id

const existingImageName = computed(() => existingImagePath.value?.split('/').pop() ?? null)
</script>

<template>
  <AdminLayout title="Homepage">
    <template #header>
      <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 class="text-xl font-semibold text-gray-900">Homepage</h1>
          <p class="mt-1 text-sm text-gray-600">
            Bloky úvodní stránky vašeho e-shopu, v pořadí, ve kterém se zobrazují.
          </p>
        </div>

        <a
          href="/"
          target="_blank"
          rel="noopener noreferrer"
          class="shrink-0 rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
        >
          Zobrazit e-shop
          <span class="sr-only">(otevře se v novém okně)</span>
        </a>
      </div>
    </template>

    <!-- Add block -->
    <form
      class="mb-6 flex flex-wrap items-end gap-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm"
      @submit.prevent="submitAdd"
    >
      <div>
        <label for="new-block-type" class="block text-sm font-medium text-gray-700">
          Přidat blok
        </label>
        <select
          id="new-block-type"
          v-model="addForm.type"
          class="mt-1 rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
        >
          <option v-for="type in blockTypes" :key="type" :value="type">
            {{ typeLabel(type) }}
          </option>
        </select>
        <p v-if="addForm.errors.type" class="mt-1 text-sm text-red-700">
          {{ addForm.errors.type }}
        </p>
      </div>

      <button
        type="submit"
        :disabled="addForm.processing"
        class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:bg-gray-400 disabled:text-gray-700"
      >
        Přidat blok
      </button>
    </form>

    <!-- Block list -->
    <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
      <p v-if="blocks.length === 0" class="py-8 text-center text-gray-600">
        Homepage zatím nemá žádný blok. Přidejte první výše.
      </p>

      <ul v-else class="space-y-3">
        <li
          v-for="block in blocks"
          :key="block.id"
          class="rounded-md border border-gray-200"
          :class="block.visible ? 'bg-white' : 'bg-gray-50'"
        >
          <div class="flex flex-wrap items-center justify-between gap-3 p-3">
            <div class="flex items-center gap-3">
              <span class="font-medium text-gray-900">{{ typeLabel(block.type) }}</span>

              <!-- Not color alone: the text itself says visible/hidden. -->
              <span
                class="rounded-full border px-2 py-0.5 text-xs font-medium"
                :class="
                  block.visible
                    ? 'border-emerald-300 bg-emerald-50 text-emerald-800'
                    : 'border-gray-300 bg-gray-100 text-gray-600'
                "
              >
                {{ block.visible ? 'Viditelný' : 'Skrytý' }}
              </span>
            </div>

            <div class="flex flex-wrap items-center gap-2">
              <button
                type="button"
                :disabled="isFirst(block)"
                :aria-label="`Posunout blok ${typeLabel(block.type)} nahoru`"
                class="rounded-md border border-gray-300 px-2 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 disabled:cursor-not-allowed disabled:opacity-40"
                @click="move(block, 'up')"
              >
                ↑ <span class="sr-only">Nahoru</span>
              </button>

              <button
                type="button"
                :disabled="isLast(block)"
                :aria-label="`Posunout blok ${typeLabel(block.type)} dolů`"
                class="rounded-md border border-gray-300 px-2 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 disabled:cursor-not-allowed disabled:opacity-40"
                @click="move(block, 'down')"
              >
                ↓ <span class="sr-only">Dolů</span>
              </button>

              <button
                type="button"
                :aria-pressed="block.visible"
                class="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
                @click="toggleVisibility(block)"
              >
                {{ block.visible ? 'Skrýt' : 'Zobrazit' }}
              </button>

              <button
                type="button"
                :aria-expanded="editingId === block.id"
                :aria-controls="`edit-panel-${block.id}`"
                class="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
                @click="editingId === block.id ? cancelEdit() : startEdit(block)"
              >
                {{ editingId === block.id ? 'Zavřít' : 'Upravit' }}
              </button>

              <button
                type="button"
                class="rounded-md border border-red-300 px-3 py-1.5 text-sm font-semibold text-red-700 hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-700 focus-visible:ring-offset-2"
                @click="deleting = block"
              >
                Smazat
              </button>
            </div>
          </div>

          <!-- Edit panel, per block type -->
          <div
            v-if="editingId === block.id && editState"
            :id="`edit-panel-${block.id}`"
            class="border-t border-gray-200 p-4"
          >
            <form class="grid gap-4 sm:grid-cols-2" enctype="multipart/form-data" @submit.prevent="submitEdit(block)">
              <!-- Hero -->
              <template v-if="block.type === 'hero'">
                <div class="sm:col-span-2">
                  <label :for="`title-${block.id}`" class="block text-sm font-medium text-gray-700">
                    Nadpis
                  </label>
                  <input
                    :id="`title-${block.id}`"
                    v-model="editState.title"
                    type="text"
                    required
                    class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
                  />
                </div>

                <div class="sm:col-span-2">
                  <label :for="`subtitle-${block.id}`" class="block text-sm font-medium text-gray-700">
                    Podnadpis
                  </label>
                  <input
                    :id="`subtitle-${block.id}`"
                    v-model="editState.subtitle"
                    type="text"
                    class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
                  />
                </div>

                <div>
                  <label :for="`cta-label-${block.id}`" class="block text-sm font-medium text-gray-700">
                    Text tlačítka
                  </label>
                  <input
                    :id="`cta-label-${block.id}`"
                    v-model="editState.cta_label"
                    type="text"
                    class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
                  />
                </div>

                <div>
                  <label :for="`cta-url-${block.id}`" class="block text-sm font-medium text-gray-700">
                    Odkaz tlačítka
                  </label>
                  <input
                    :id="`cta-url-${block.id}`"
                    v-model="editState.cta_url"
                    type="text"
                    :aria-invalid="errorFor('payload.cta_url') ? 'true' : undefined"
                    :aria-describedby="describedByFor(`cta-url-${block.id}`, 'payload.cta_url')"
                    class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
                  />
                  <p
                    v-if="errorFor('payload.cta_url')"
                    :id="`cta-url-${block.id}-error`"
                    class="mt-1 text-sm text-red-700"
                  >
                    {{ errorFor('payload.cta_url') }}
                  </p>
                </div>

                <div class="sm:col-span-2">
                  <label :for="`image-${block.id}`" class="block text-sm font-medium text-gray-700">
                    Obrázek
                  </label>
                  <p v-if="existingImageName" class="mt-1 text-sm text-gray-600">
                    Aktuální obrázek: {{ existingImageName }}. Nahrajte nový soubor pro nahrazení.
                  </p>
                  <input
                    :id="`image-${block.id}`"
                    ref="editImageInput"
                    type="file"
                    accept="image/png,image/jpeg,image/webp"
                    class="mt-1 block w-full text-sm text-gray-700 file:mr-4 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-gray-700 hover:file:bg-gray-200"
                    :aria-invalid="errorFor('image') ? 'true' : undefined"
                    :aria-describedby="describedByFor(`image-${block.id}`, 'image')"
                    @change="onEditImageChange"
                  />
                  <p v-if="errorFor('image')" :id="`image-${block.id}-error`" class="mt-1 text-sm text-red-700">
                    {{ errorFor('image') }}
                  </p>
                </div>
              </template>

              <!-- Product row -->
              <template v-else-if="block.type === 'product_row'">
                <div class="sm:col-span-2">
                  <label :for="`heading-${block.id}`" class="block text-sm font-medium text-gray-700">
                    Nadpis
                  </label>
                  <input
                    :id="`heading-${block.id}`"
                    v-model="editState.heading"
                    type="text"
                    required
                    class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
                  />
                </div>

                <fieldset class="sm:col-span-2">
                  <legend class="text-sm font-medium text-gray-700">Zdroj produktů</legend>
                  <div class="mt-2 flex gap-4">
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                      <input
                        v-model="editState.mode"
                        type="radio"
                        :name="`mode-${block.id}`"
                        value="latest"
                        class="border-gray-300 text-gray-900 focus:ring-gray-900"
                      />
                      Nejnovější
                    </label>
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                      <input
                        v-model="editState.mode"
                        type="radio"
                        :name="`mode-${block.id}`"
                        value="manual"
                        class="border-gray-300 text-gray-900 focus:ring-gray-900"
                      />
                      Ručně vybrané
                    </label>
                  </div>
                </fieldset>

                <div v-if="editState.mode === 'latest'">
                  <label :for="`count-${block.id}`" class="block text-sm font-medium text-gray-700">
                    Počet produktů
                  </label>
                  <input
                    :id="`count-${block.id}`"
                    v-model.number="editState.count"
                    type="number"
                    min="1"
                    max="12"
                    :aria-invalid="errorFor('payload.count') ? 'true' : undefined"
                    :aria-describedby="describedByFor(`count-${block.id}`, 'payload.count')"
                    class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
                  />
                  <p
                    v-if="errorFor('payload.count')"
                    :id="`count-${block.id}-error`"
                    class="mt-1 text-sm text-red-700"
                  >
                    {{ errorFor('payload.count') }}
                  </p>
                </div>

                <div v-else class="sm:col-span-2">
                  <label :for="`product-ids-${block.id}`" class="block text-sm font-medium text-gray-700">
                    ID produktů (oddělené čárkou)
                  </label>
                  <input
                    :id="`product-ids-${block.id}`"
                    v-model="editState.product_ids"
                    type="text"
                    placeholder="např. 12, 45, 9"
                    aria-describedby="product-ids-hint"
                    class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
                  />
                  <p id="product-ids-hint" class="mt-1 text-sm text-gray-600">
                    ID produktu najdete v jeho detailu v adminu.
                  </p>
                </div>
              </template>

              <!-- Category grid -->
              <template v-else-if="block.type === 'category_grid'">
                <div class="sm:col-span-2">
                  <label :for="`heading-${block.id}`" class="block text-sm font-medium text-gray-700">
                    Nadpis
                  </label>
                  <input
                    :id="`heading-${block.id}`"
                    v-model="editState.heading"
                    type="text"
                    required
                    class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
                  />
                </div>

                <div class="sm:col-span-2">
                  <label :for="`category-ids-${block.id}`" class="block text-sm font-medium text-gray-700">
                    ID kategorií (oddělené čárkou)
                  </label>
                  <input
                    :id="`category-ids-${block.id}`"
                    v-model="editState.category_ids"
                    type="text"
                    placeholder="např. 3, 7"
                    aria-describedby="category-ids-hint"
                    class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
                  />
                  <p id="category-ids-hint" class="mt-1 text-sm text-gray-600">
                    ID kategorie najdete v jejím detailu v seznamu kategorií.
                  </p>
                </div>
              </template>

              <!-- Text -->
              <template v-else-if="block.type === 'text'">
                <div class="sm:col-span-2">
                  <label :for="`heading-${block.id}`" class="block text-sm font-medium text-gray-700">
                    Nadpis (nepovinné)
                  </label>
                  <input
                    :id="`heading-${block.id}`"
                    v-model="editState.heading"
                    type="text"
                    class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
                  />
                </div>

                <div class="sm:col-span-2">
                  <label :for="`html-${block.id}`" class="block text-sm font-medium text-gray-700">
                    Obsah (HTML)
                  </label>
                  <textarea
                    :id="`html-${block.id}`"
                    v-model="editState.html"
                    rows="6"
                    aria-describedby="html-hint"
                    class="mt-1 w-full rounded-md border-gray-300 font-mono text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
                  />
                  <p id="html-hint" class="mt-1 text-sm text-gray-600">
                    Povolené HTML: odstavce, tučné, kurzíva, seznamy, nadpisy, odkazy. Ostatní se při uložení
                    odstraní.
                  </p>
                </div>
              </template>

              <!-- Banner -->
              <template v-else-if="block.type === 'banner'">
                <div class="sm:col-span-2">
                  <label :for="`image-${block.id}`" class="block text-sm font-medium text-gray-700">
                    Obrázek
                  </label>
                  <p v-if="existingImageName" class="mt-1 text-sm text-gray-600">
                    Aktuální obrázek: {{ existingImageName }}. Nahrajte nový soubor pro nahrazení.
                  </p>
                  <input
                    :id="`image-${block.id}`"
                    ref="editImageInput"
                    type="file"
                    accept="image/png,image/jpeg,image/webp"
                    class="mt-1 block w-full text-sm text-gray-700 file:mr-4 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-gray-700 hover:file:bg-gray-200"
                    :aria-invalid="errorFor('image') ? 'true' : undefined"
                    :aria-describedby="describedByFor(`image-${block.id}`, 'image')"
                    @change="onEditImageChange"
                  />
                  <p v-if="errorFor('image')" :id="`image-${block.id}-error`" class="mt-1 text-sm text-red-700">
                    {{ errorFor('image') }}
                  </p>
                </div>

                <div>
                  <label :for="`url-${block.id}`" class="block text-sm font-medium text-gray-700">
                    Odkaz (nepovinné)
                  </label>
                  <input
                    :id="`url-${block.id}`"
                    v-model="editState.url"
                    type="text"
                    :aria-invalid="errorFor('payload.url') ? 'true' : undefined"
                    :aria-describedby="describedByFor(`url-${block.id}`, 'payload.url')"
                    class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
                  />
                  <p
                    v-if="errorFor('payload.url')"
                    :id="`url-${block.id}-error`"
                    class="mt-1 text-sm text-red-700"
                  >
                    {{ errorFor('payload.url') }}
                  </p>
                </div>

                <div>
                  <label :for="`alt-${block.id}`" class="block text-sm font-medium text-gray-700">
                    Alternativní text obrázku
                  </label>
                  <input
                    :id="`alt-${block.id}`"
                    v-model="editState.alt"
                    type="text"
                    class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-gray-900 focus:ring-gray-900"
                  />
                </div>
              </template>

              <div class="sm:col-span-2 flex justify-end gap-3">
                <button
                  type="button"
                  class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
                  @click="cancelEdit"
                >
                  Zrušit
                </button>
                <button
                  type="submit"
                  :disabled="editProcessing"
                  class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:bg-gray-400 disabled:text-gray-700"
                >
                  Uložit
                </button>
              </div>
            </form>
          </div>
        </li>
      </ul>
    </div>

    <ConfirmDialog
      :show="deleting !== null"
      title="Smazat blok"
      :message="`Opravdu smazat blok ${deleting ? typeLabel(deleting.type) : ''}? Akci nelze vzít zpět.`"
      confirm-label="Smazat"
      danger
      @cancel="deleting = null"
      @confirm="confirmDelete"
    />
  </AdminLayout>
</template>
