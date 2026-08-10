<script setup lang="ts">
/**
 * A small WYSIWYG editor for the three admin fields that carry tenant HTML.
 *
 * The editor's schema is a hand-kept mirror of the allowlist in
 * app/Core/Html/HtmlSanitizer.php. The two can only drift by someone changing
 * one without the other, so: a button here that produces a tag the sanitiser
 * drops is a lie about what the shop can do, and a tag the sanitiser allows
 * but the schema does not know is data the editor deletes just by opening.
 *
 * The sanitiser stays the authority (decision 2026-07-20, cleaning on write).
 * This component is convenience, not defence.
 */
import { EditorContent, useEditor } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import Image from '@tiptap/extension-image'
import Link from '@tiptap/extension-link'
import { TableKit } from '@tiptap/extension-table'
import type { Node as ProseMirrorNode } from '@tiptap/pm/model'
import { nextTick, ref, watch } from 'vue'

const props = defineProps<{
  modelValue: string | null
  id: string
  ariaDescribedby?: string
  /**
   * Accessible name for the actual editing surface.
   *
   * The wrapping <div> carries `id`, but the focusable, editable node is the
   * `.ProseMirror` div Tiptap builds a level inside it at runtime — a
   * `<label for>` pointing at `id` targets nothing a screen reader considers
   * a control. There is no way to derive a Czech label from `id`, so a
   * caller that already shows a visible <label> repeats its text here (see
   * Show.vue). Applied through `editorProps.attributes`, the only path that
   * reaches the DOM node ProseMirror itself owns — attributes bound in the
   * template on `<EditorContent>` land on the wrapper it renders, one level
   * above.
   */
  ariaLabel?: string
}>()

/**
 * Attributes that must land on the actual editing surface, not on the
 * wrapper `<EditorContent>` renders for itself — the same reason `ariaLabel`
 * goes through `editorProps.attributes` above. `aria-describedby` binding on
 * `<EditorContent>` in the template would attach to that wrapper, which is
 * never focused and never announced; the hint would exist in the DOM but no
 * screen reader would ever read it out.
 */
const editableAttributes = () => ({
  ...(props.ariaLabel ? { 'aria-label': props.ariaLabel } : {}),
  ...(props.ariaDescribedby ? { 'aria-describedby': props.ariaDescribedby } : {}),
})

const emit = defineEmits<{ (e: 'update:modelValue', value: string): void }>()

/**
 * The sanitiser allows width and height on <img>; Tiptap does not know them
 * and would drop them on load.
 */
const SizedImage = Image.extend({
  addAttributes() {
    return {
      ...this.parent?.(),
      width: { default: null },
      height: { default: null },
    }
  },
})

/** The sanitiser allows title on <a>; Tiptap does not know it. */
const TitledLink = Link.extend({
  addAttributes() {
    return { ...this.parent?.(), title: { default: null } }
  },
}).configure({
  openOnClick: false,
  autolink: false,
  // Naming these here does not narrow Tiptap to just this list — `protocols`
  // is additive to linkifyjs's own built-in set (which already includes
  // http/https/mailto), not a replacement of it, so this is not what keeps
  // the schema in step with HtmlSanitizer::ALLOWED_SCHEMES. `isSafeUrl()`
  // below is the actual gate: it runs before setLink() is ever called.
  protocols: ['http', 'https', 'mailto', 'tel'],
})

/**
 * Tiptap's ListItem node requires a paragraph child (`content: 'paragraph
 * block*'`), so a plain one-line bullet round-trips through `getHTML()` as
 * `<li><p>Aluminium</p></li>`. `HtmlSanitizer` allows both tags and does not
 * police nesting, so that shape survives the server untouched — it is just
 * not what "one line in an odrážka" should print on the storefront.
 *
 * Overriding the node's content model to drop the paragraph
 * (`content: 'inline*'`) looks like the fix, but breaks the thing it is
 * fixing: `toggleBulletList` wraps the selected *paragraph node* inside a
 * new list item, and an inline-only item schema rejects a paragraph child,
 * so the command silently no-ops — Tab-nesting and Enter-to-continue rely on
 * the same paragraph-shaped content. So the model stays stock, and this
 * strips the wrapper only from the string that leaves the component: a `<li>`
 * whose entire content is one `<p>` unwraps to bare text, exactly what
 * `HtmlSanitizer` would also consider unchanged (it does not care about
 * nesting). A `<li>` with more than one child (a nested list, several
 * paragraphs) is left alone — there is no single tag to unwrap it to.
 */
function collapseSingleParagraphListItems(html: string): string {
  const doc = new DOMParser().parseFromString(html, 'text/html')

  doc.querySelectorAll('li').forEach((li) => {
    if (li.children.length === 1 && li.children[0].tagName === 'P') {
      const paragraph = li.children[0]

      while (paragraph.firstChild) li.insertBefore(paragraph.firstChild, paragraph)
      li.removeChild(paragraph)
    }
  })

  return doc.body.innerHTML
}

/**
 * Tiptap's own `editor.isEmpty` walks every descendant leaf and calls the
 * document empty as soon as none of them holds text — including a freshly
 * inserted table, whose header and body cells are all blank paragraphs until
 * someone types into one. `onUpdate` below used to gate on `editor.isEmpty`
 * to collapse the placeholder ProseMirror leaves behind after a full
 * select-and-delete (a lone empty paragraph, serialised as `<p></p>`) down to
 * a real empty string; that same check silently discarded a table the moment
 * it was inserted and saved, because the table has no text either.
 *
 * "Nothing here" for this field is specifically that placeholder, not "no
 * text content anywhere" — a table or a top-level image is real structure
 * even with zero characters typed into it.
 *
 * That narrows what "empty" means compared to the old check, and the
 * narrowing is unannounced anywhere else: a heading whose text a merchant
 * deletes down to nothing used to collapse to '' (an empty `<h2>` has no
 * text, `editor.isEmpty` said so); it now persists as `<h2></h2>` instead,
 * because a single non-paragraph block does not match the placeholder shape
 * this function looks for. Deliberate — it means the function never discards
 * structure it cannot prove is meaningless — but worth knowing before
 * changing what counts as "the placeholder shape" here.
 */
function isTrulyEmpty(doc: ProseMirrorNode): boolean {
  if (doc.childCount === 0) return true
  if (doc.childCount > 1) return false

  const only = doc.firstChild

  return only !== null && only.type.name === 'paragraph' && only.content.size === 0
}

/** True while we are writing the prop into the editor, so the resulting
 * update event does not bounce back out as a change the user made. */
const applyingExternal = ref(false)

/**
 * useEditor, not `new Editor`: it re-renders the component on every editor
 * transaction, which is what keeps the toolbar's aria-pressed in step with
 * where the cursor is. A plain shallowRef would leave every button stuck in
 * the state it had when the editor was built. It also destroys the editor on
 * unmount for us.
 */
const editor = useEditor({
  content: props.modelValue ?? '',
  editorProps: {
    attributes: editableAttributes(),
    // On macOS, Chromium resolves a literal Ctrl-A inside any editable
    // region to the Cocoa text-editing binding "move to paragraph start",
    // not select-all (only Cmd-a is). Left alone, a merchant on a Mac would
    // get an inconsistent, surprising Ctrl-A here. Handling it ourselves
    // makes Ctrl-a and Cmd-a both mean "select everything", the same on
    // every platform, matching what any other web text editor does.
    handleKeyDown(_view, event) {
      if (event.key.toLowerCase() === 'a' && (event.ctrlKey || event.metaKey)) {
        editor.value?.commands.selectAll()

        return true
      }

      return false
    },
  },
  extensions: [
    StarterKit.configure({
      // Tags the sanitiser drops. A button for them would produce markup the
      // server deletes on save.
      code: false,
      codeBlock: false,
      strike: false,
      horizontalRule: false,
      // h1 belongs to the product name on the storefront.
      heading: { levels: [2, 3, 4] },
      // Replaced below by TitledLink, which keeps the `title` attribute the
      // sanitiser allows and StarterKit's default Link does not know about.
      link: false,
    }),
    SizedImage,
    TitledLink,
    TableKit.configure({
      // Resizing writes a colwidth attribute the sanitiser drops anyway, and
      // the handle is mouse-only.
      table: { resizable: false },
    }),
  ],
  onUpdate: ({ editor }) => {
    if (applyingExternal.value) return

    emit('update:modelValue', isTrulyEmpty(editor.state.doc) ? '' : collapseSingleParagraphListItems(editor.getHTML()))
  },
})

const linkOpen = ref(false)
const linkUrl = ref('')
const linkError = ref('')
const linkInput = ref<HTMLInputElement | null>(null)
// Derived from props.id, not a fixed string, so two editor instances on the
// same admin page cannot collide on which input their error text describes.
const linkErrorId = `${props.id}-link-error`

/**
 * Deliberately stricter than HtmlSanitizer::isSafeUrl on one point: the
 * server accepts anything starting with "/", including "//evil.com" and
 * "/\evil.com" — open redirects wearing an internal path. Rejecting those
 * here uses the same guard as BlockUrl::isSafe (decision 2026-07-26). The
 * divergence only ever runs in the safe direction: this refuses a value the
 * server would keep, so the merchant is never told "saved" when it was not.
 */
function isSafeUrl(url: string): boolean {
  const value = url.trim()
  if (value === '') return false
  if (value.startsWith('#')) return true
  if (value.startsWith('/')) return !value.startsWith('//') && !value.startsWith('/\\')

  return /^(https?|mailto|tel):/i.test(value)
}

function openLinkDialog() {
  linkUrl.value = editor.value?.getAttributes('link').href ?? ''
  linkError.value = ''
  linkOpen.value = true

  // A dialog a keyboard user cannot type into is worse than no dialog: the
  // click that opened it left focus on the toolbar button.
  nextTick(() => linkInput.value?.focus())
}

function applyLink() {
  if (!isSafeUrl(linkUrl.value)) {
    linkError.value = 'Adresa musí začínat http://, https://, mailto:, tel: nebo /.'

    return
  }

  editor.value?.chain().focus().extendMarkRange('link').setLink({ href: linkUrl.value.trim() }).run()
  linkOpen.value = false
}

function removeLink() {
  editor.value?.chain().focus().extendMarkRange('link').unsetLink().run()
}

/**
 * Escape and "Zrušit" unmount the panel while focus is still inside it
 * (the input, or one of these two buttons) — without moving focus back to
 * the editor first, a keyboard user lands on document.body and has lost
 * their place on the page, same failure applyLink already avoids by
 * chaining `.focus()` before it closes.
 */
function closeLinkDialog() {
  editor.value?.chain().focus().run()
  linkOpen.value = false
}

watch(
  () => props.modelValue,
  (value) => {
    // Same transform as onUpdate's emit, or every keystroke inside a list
    // would look like an external change: the live doc always serialises
    // with the wrapping <p> (the schema still requires it), so comparing it
    // to the collapsed string we last emitted would never match, and this
    // watcher would call setContent() on top of what the user is typing.
    const current = editor.value ? collapseSingleParagraphListItems(editor.value.getHTML()) : ''
    if (!editor.value || value === current) return
    // An empty document reads as "<p></p>", which is not the same as no value.
    if ((value ?? '') === '' && isTrulyEmpty(editor.value.state.doc)) return

    applyingExternal.value = true
    editor.value.commands.setContent(value ?? '', { emitUpdate: false })
    applyingExternal.value = false
  },
)
</script>

<template>
  <div :id="id" class="mt-1 rounded-md border border-gray-300 shadow-sm focus-within:border-gray-900 focus-within:ring-1 focus-within:ring-gray-900">
    <div
      v-if="editor"
      role="toolbar"
      aria-label="Formátování textu"
      class="flex flex-wrap gap-1 border-b border-gray-200 bg-gray-50 p-1"
    >
      <button
        type="button"
        aria-label="Tučné"
        :aria-pressed="editor.isActive('bold')"
        class="rounded px-2 py-1 text-sm font-bold text-gray-700 hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-gray-900 aria-pressed:bg-gray-900 aria-pressed:text-white"
        @click="editor.chain().focus().toggleBold().run()"
      >
        B
      </button>
      <button
        type="button"
        aria-label="Kurzíva"
        :aria-pressed="editor.isActive('italic')"
        class="rounded px-2 py-1 text-sm italic text-gray-700 hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-gray-900 aria-pressed:bg-gray-900 aria-pressed:text-white"
        @click="editor.chain().focus().toggleItalic().run()"
      >
        I
      </button>
      <button
        type="button"
        aria-label="Podtržené"
        :aria-pressed="editor.isActive('underline')"
        class="rounded px-2 py-1 text-sm underline text-gray-700 hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-gray-900 aria-pressed:bg-gray-900 aria-pressed:text-white"
        @click="editor.chain().focus().toggleUnderline().run()"
      >
        U
      </button>

      <span class="mx-1 w-px bg-gray-300" aria-hidden="true" />

      <button
        type="button"
        aria-label="Odstavec"
        :aria-pressed="editor.isActive('paragraph')"
        class="rounded px-2 py-1 text-sm text-gray-700 hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-gray-900 aria-pressed:bg-gray-900 aria-pressed:text-white"
        @click="editor.chain().focus().setParagraph().run()"
      >
        ¶
      </button>
      <button
        type="button"
        aria-label="Nadpis 2"
        :aria-pressed="editor.isActive('heading', { level: 2 })"
        class="rounded px-2 py-1 text-sm font-semibold text-gray-700 hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-gray-900 aria-pressed:bg-gray-900 aria-pressed:text-white"
        @click="editor.chain().focus().toggleHeading({ level: 2 }).run()"
      >
        H2
      </button>
      <button
        type="button"
        aria-label="Nadpis 3"
        :aria-pressed="editor.isActive('heading', { level: 3 })"
        class="rounded px-2 py-1 text-sm font-semibold text-gray-700 hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-gray-900 aria-pressed:bg-gray-900 aria-pressed:text-white"
        @click="editor.chain().focus().toggleHeading({ level: 3 }).run()"
      >
        H3
      </button>
      <button
        type="button"
        aria-label="Nadpis 4"
        :aria-pressed="editor.isActive('heading', { level: 4 })"
        class="rounded px-2 py-1 text-sm font-semibold text-gray-700 hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-gray-900 aria-pressed:bg-gray-900 aria-pressed:text-white"
        @click="editor.chain().focus().toggleHeading({ level: 4 }).run()"
      >
        H4
      </button>

      <span class="mx-1 w-px bg-gray-300" aria-hidden="true" />

      <button
        type="button"
        aria-label="Odrážkový seznam"
        :aria-pressed="editor.isActive('bulletList')"
        class="rounded px-2 py-1 text-sm text-gray-700 hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-gray-900 aria-pressed:bg-gray-900 aria-pressed:text-white"
        @click="editor.chain().focus().toggleBulletList().run()"
      >
        •
      </button>
      <button
        type="button"
        aria-label="Číslovaný seznam"
        :aria-pressed="editor.isActive('orderedList')"
        class="rounded px-2 py-1 text-sm text-gray-700 hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-gray-900 aria-pressed:bg-gray-900 aria-pressed:text-white"
        @click="editor.chain().focus().toggleOrderedList().run()"
      >
        1.
      </button>
      <button
        type="button"
        aria-label="Citace"
        :aria-pressed="editor.isActive('blockquote')"
        class="rounded px-2 py-1 text-sm text-gray-700 hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-gray-900 aria-pressed:bg-gray-900 aria-pressed:text-white"
        @click="editor.chain().focus().toggleBlockquote().run()"
      >
        „
      </button>

      <span class="mx-1 w-px bg-gray-300" aria-hidden="true" />

      <button
        type="button"
        aria-label="Vložit odkaz"
        :aria-pressed="editor.isActive('link')"
        class="rounded px-2 py-1 text-sm text-gray-700 hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-gray-900 aria-pressed:bg-gray-900 aria-pressed:text-white"
        @click="openLinkDialog"
      >
        🔗
      </button>
      <button
        type="button"
        aria-label="Odebrat odkaz"
        :disabled="!editor.isActive('link')"
        class="rounded px-2 py-1 text-sm text-gray-700 hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-gray-900 disabled:opacity-40 disabled:hover:bg-transparent"
        @click="removeLink"
      >
        🔗✕
      </button>

      <span class="mx-1 w-px bg-gray-300" aria-hidden="true" />

      <button
        type="button"
        aria-label="Vložit tabulku"
        class="rounded px-2 py-1 text-sm text-gray-700 hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-gray-900"
        @click="editor.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run()"
      >
        ▦
      </button>
      <button
        type="button"
        aria-label="Přidat řádek"
        :disabled="!editor.can().addRowAfter()"
        class="rounded px-2 py-1 text-sm text-gray-700 hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-gray-900 disabled:opacity-40 disabled:hover:bg-transparent"
        @click="editor.chain().focus().addRowAfter().run()"
      >
        +řádek
      </button>
      <button
        type="button"
        aria-label="Odebrat řádek"
        :disabled="!editor.can().deleteRow()"
        class="rounded px-2 py-1 text-sm text-gray-700 hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-gray-900 disabled:opacity-40 disabled:hover:bg-transparent"
        @click="editor.chain().focus().deleteRow().run()"
      >
        −řádek
      </button>
      <button
        type="button"
        aria-label="Přidat sloupec"
        :disabled="!editor.can().addColumnAfter()"
        class="rounded px-2 py-1 text-sm text-gray-700 hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-gray-900 disabled:opacity-40 disabled:hover:bg-transparent"
        @click="editor.chain().focus().addColumnAfter().run()"
      >
        +sloupec
      </button>
      <button
        type="button"
        aria-label="Odebrat sloupec"
        :disabled="!editor.can().deleteColumn()"
        class="rounded px-2 py-1 text-sm text-gray-700 hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-gray-900 disabled:opacity-40 disabled:hover:bg-transparent"
        @click="editor.chain().focus().deleteColumn().run()"
      >
        −sloupec
      </button>
      <button
        type="button"
        aria-label="Smazat tabulku"
        :disabled="!editor.can().deleteTable()"
        class="rounded px-2 py-1 text-sm text-gray-700 hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-gray-900 disabled:opacity-40 disabled:hover:bg-transparent"
        @click="editor.chain().focus().deleteTable().run()"
      >
        ✕tabulka
      </button>

      <span class="mx-1 w-px bg-gray-300" aria-hidden="true" />

      <button
        type="button"
        aria-label="Vymazat formátování"
        class="rounded px-2 py-1 text-sm text-gray-700 hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-gray-900"
        @click="editor.chain().focus().unsetAllMarks().clearNodes().run()"
      >
        ✕
      </button>
    </div>

    <div v-if="linkOpen" class="flex flex-wrap items-start gap-2 border-b border-gray-200 bg-white p-2">
      <input
        ref="linkInput"
        v-model="linkUrl"
        type="text"
        aria-label="Adresa odkazu"
        :aria-invalid="linkError ? 'true' : undefined"
        :aria-describedby="linkErrorId"
        class="min-w-0 flex-1 rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
        placeholder="https://"
        @keydown.enter.prevent="applyLink"
        @keydown.esc.prevent="closeLinkDialog"
      />
      <button type="button" class="rounded-md bg-gray-900 px-3 py-2 text-sm text-white" @click="applyLink">
        Vložit
      </button>
      <button type="button" class="rounded-md border border-gray-300 px-3 py-2 text-sm" @click="closeLinkDialog">
        Zrušit
      </button>
      <!--
        Always in the DOM — the input's aria-describedby points at this id
        unconditionally, and an id that only exists while there is an error
        is a dangling reference (and an axe aria-valid-attr-value violation)
        the rest of the time. v-show only toggles visibility, never removes it.
      -->
      <p v-show="linkError" :id="linkErrorId" class="w-full text-sm text-red-600">{{ linkError }}</p>
    </div>

    <EditorContent :editor="editor" />
  </div>
</template>

<style scoped>
/**
 * Tailwind's preflight strips headings and lists back to plain text, so the
 * editor would show a heading identical to a paragraph. Scoped styles do not
 * reach nodes ProseMirror creates at runtime, hence :deep().
 */
:deep(.ProseMirror) {
  min-height: 14rem;
  padding: 0.75rem;
  outline: none;
}
:deep(.ProseMirror:focus) {
  outline: none;
}
:deep(.ProseMirror h2) {
  font-size: 1.375rem;
  font-weight: 700;
  margin: 1rem 0 0.5rem;
}
:deep(.ProseMirror h3) {
  font-size: 1.175rem;
  font-weight: 700;
  margin: 0.875rem 0 0.5rem;
}
:deep(.ProseMirror h4) {
  font-size: 1rem;
  font-weight: 700;
  margin: 0.75rem 0 0.5rem;
}
:deep(.ProseMirror p) {
  margin: 0.5rem 0;
}
:deep(.ProseMirror ul) {
  list-style: disc;
  padding-left: 1.5rem;
  margin: 0.5rem 0;
}
:deep(.ProseMirror ol) {
  list-style: decimal;
  padding-left: 1.5rem;
  margin: 0.5rem 0;
}
:deep(.ProseMirror blockquote) {
  border-left: 3px solid #d1d5db;
  padding-left: 0.75rem;
  color: #4b5563;
  margin: 0.5rem 0;
}
:deep(.ProseMirror a) {
  color: #1d4ed8;
  text-decoration: underline;
}
:deep(.ProseMirror img) {
  max-width: 100%;
  height: auto;
}
:deep(.ProseMirror table) {
  border-collapse: collapse;
  width: 100%;
  margin: 0.75rem 0;
}
:deep(.ProseMirror th),
:deep(.ProseMirror td) {
  border: 1px solid #d1d5db;
  padding: 0.375rem 0.5rem;
  text-align: left;
}
:deep(.ProseMirror th) {
  background: #f3f4f6;
  font-weight: 600;
}
</style>
