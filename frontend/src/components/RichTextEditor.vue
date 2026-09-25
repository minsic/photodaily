<script setup lang="ts">
import { Bold } from '@tiptap/extension-bold'
import { CharacterCount } from '@tiptap/extension-character-count'
import { Document } from '@tiptap/extension-document'
import { HardBreak } from '@tiptap/extension-hard-break'
import { History } from '@tiptap/extension-history'
import { Italic } from '@tiptap/extension-italic'
import { Link } from '@tiptap/extension-link'
import { Paragraph } from '@tiptap/extension-paragraph'
import { Placeholder } from '@tiptap/extension-placeholder'
import { Text } from '@tiptap/extension-text'
import { EditorContent, useEditor } from '@tiptap/vue-3'
import { computed, nextTick, ref, watch } from 'vue'

import AppIcon from '@/components/AppIcon.vue'

/**
 * Editor minimale per le didascalie: grassetto, corsivo, link e a capo.
 * Lo schema è volutamente povero — quello che non c'è qui il server lo
 * butterebbe via comunque in fase di sanificazione.
 */
const props = withDefaults(
  defineProps<{
    modelValue: string
    placeholder?: string
    limit?: number
    ariaLabelledby?: string
  }>(),
  { placeholder: '', limit: 5000, ariaLabelledby: undefined },
)

const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const editor = useEditor({
  content: props.modelValue,
  // Tiptap aggiungerebbe un <style> inline, bloccato dalla Content-Security-Policy:
  // le regole che servono stanno in style.css.
  injectCSS: false,
  extensions: [
    Document,
    Paragraph,
    Text,
    Bold,
    Italic,
    HardBreak,
    History,
    Link.configure({
      openOnClick: false,
      protocols: ['http', 'https', 'mailto'],
      HTMLAttributes: { rel: 'nofollow noreferrer noopener', target: '_blank' },
    }),
    Placeholder.configure({ placeholder: () => props.placeholder }),
    CharacterCount.configure({ limit: props.limit }),
  ],
  editorProps: {
    attributes: {
      class: 'caption-html min-h-24 px-3 py-2 focus:outline-none',
      ...(props.ariaLabelledby ? { 'aria-labelledby': props.ariaLabelledby } : {}),
    },
  },
  onUpdate({ editor }) {
    emit('update:modelValue', editor.isEmpty ? '' : editor.getHTML())
  },
})

// Il form può riscrivere il valore da fuori (reset dopo il salvataggio):
// si ricarica solo se è davvero diverso, altrimenti il cursore salta.
watch(
  () => props.modelValue,
  (value) => {
    if (editor.value && value !== editor.value.getHTML() && !(value === '' && editor.value.isEmpty)) {
      editor.value.commands.setContent(value, { emitUpdate: false })
    }
  },
)

const used = computed(() => editor.value?.storage.characterCount.characters() ?? 0)
const nearLimit = computed(() => used.value > props.limit * 0.9)

const linkOpen = ref(false)
const linkUrl = ref('')
const linkInput = ref<HTMLInputElement | null>(null)

function toggleLinkForm(): void {
  if (!editor.value) {
    return
  }

  if (editor.value.isActive('link')) {
    editor.value.chain().focus().extendMarkRange('link').unsetLink().run()

    return
  }

  linkUrl.value = ''
  linkOpen.value = true
  void nextTick(() => linkInput.value?.focus())
}

function applyLink(): void {
  const href = normalizeUrl(linkUrl.value)

  if (!editor.value || !href) {
    return
  }

  const chain = editor.value.chain().focus()

  // Senza testo selezionato non ci sarebbe niente da rendere cliccabile:
  // si scrive l'indirizzo e lo si trasforma in link.
  if (editor.value.state.selection.empty) {
    chain.insertContent(linkUrl.value.trim()).setTextSelection({
      from: editor.value.state.selection.from,
      to: editor.value.state.selection.from + linkUrl.value.trim().length,
    })
  }

  chain.extendMarkRange('link').setLink({ href }).run()

  closeLinkForm()
}

function closeLinkForm(): void {
  linkOpen.value = false
  linkUrl.value = ''
}

/** Aggiunge https:// se manca e scarta gli schemi che il server rifiuterebbe. */
function normalizeUrl(value: string): string | null {
  const trimmed = value.trim()

  if (trimmed === '') {
    return null
  }

  const withProtocol = /^(https?:|mailto:)/i.test(trimmed) ? trimmed : `https://${trimmed}`

  try {
    const url = new URL(withProtocol)

    return ['http:', 'https:', 'mailto:'].includes(url.protocol) ? url.toString() : null
  } catch {
    return null
  }
}
</script>

<template>
  <div class="rounded-xl border-2 border-line bg-card focus-within:border-muted">
    <div class="flex flex-wrap items-center gap-1 border-b border-line px-2 py-1.5">
      <button
        type="button"
        class="size-8 rounded-lg font-bold transition"
        :class="
          editor?.isActive('bold') ? 'bg-brick text-white' : 'text-muted hover:text-ink'
        "
        :aria-pressed="editor?.isActive('bold') ?? false"
        title="Grassetto"
        aria-label="Grassetto"
        @mousedown.prevent
        @click="editor?.chain().focus().toggleBold().run()"
      >
        B
      </button>

      <button
        type="button"
        class="size-8 rounded-lg font-bold italic transition"
        :class="
          editor?.isActive('italic') ? 'bg-brick text-white' : 'text-muted hover:text-ink'
        "
        :aria-pressed="editor?.isActive('italic') ?? false"
        title="Corsivo"
        aria-label="Corsivo"
        @mousedown.prevent
        @click="editor?.chain().focus().toggleItalic().run()"
      >
        I
      </button>

      <button
        type="button"
        class="flex size-8 items-center justify-center rounded-lg transition"
        :class="
          editor?.isActive('link') ? 'bg-brick text-white' : 'text-muted hover:text-ink'
        "
        :aria-pressed="editor?.isActive('link') ?? false"
        :title="editor?.isActive('link') ? 'Togli il link' : 'Inserisci un link'"
        :aria-label="editor?.isActive('link') ? 'Togli il link' : 'Inserisci un link'"
        @mousedown.prevent
        @click="toggleLinkForm"
      >
        <AppIcon name="link" class="size-4" />
      </button>

      <span v-if="nearLimit" class="ml-auto text-xs text-muted">{{ used }}/{{ limit }}</span>
    </div>

    <div v-if="linkOpen" class="flex items-center gap-2 border-b border-line px-2 py-1.5">
      <input
        ref="linkInput"
        v-model="linkUrl"
        type="url"
        inputmode="url"
        placeholder="https://esempio.it"
        aria-label="Indirizzo del link"
        class="min-w-0 flex-1 rounded-lg border-2 border-line bg-paper px-2 py-1 text-sm text-ink placeholder:text-muted/70"
        @keydown.enter.prevent="applyLink"
        @keydown.esc.prevent="closeLinkForm"
      />
      <button
        type="button"
        class="rounded-lg bg-brick px-2.5 py-1 text-sm font-bold text-white transition disabled:opacity-60"
        :disabled="linkUrl.trim() === ''"
        @click="applyLink"
      >
        Applica
      </button>
      <button
        type="button"
        class="flex size-7 items-center justify-center rounded-lg text-muted transition hover:text-ink"
        aria-label="Annulla"
        @click="closeLinkForm"
      >
        <AppIcon name="close" class="size-4" />
      </button>
    </div>

    <EditorContent :editor="editor" />
  </div>
</template>
