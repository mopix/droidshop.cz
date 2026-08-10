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
import { ref, watch } from 'vue'

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
    attributes: props.ariaLabel ? { 'aria-label': props.ariaLabel } : {},
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
      link: false, // replaced in Task 2 with one that keeps the title attribute
    }),
    SizedImage,
  ],
  onUpdate: ({ editor }) => {
    if (applyingExternal.value) return

    emit('update:modelValue', editor.isEmpty ? '' : collapseSingleParagraphListItems(editor.getHTML()))
  },
})

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
    if ((value ?? '') === '' && editor.value.isEmpty) return

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
        aria-label="Vymazat formátování"
        class="rounded px-2 py-1 text-sm text-gray-700 hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-gray-900"
        @click="editor.chain().focus().unsetAllMarks().clearNodes().run()"
      >
        ✕
      </button>
    </div>

    <EditorContent :editor="editor" :aria-describedby="ariaDescribedby" />
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
</style>
