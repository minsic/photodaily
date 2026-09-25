<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'

import { captionToEditableText, editableTextToCaptionHtml } from '@/utils/caption'

/**
 * Campo della didascalia: testo semplice con emoji e a capo, in una textarea
 * che si allunga da sola. Il valore resta l'HTML minimale salvato dal server
 * (<p> e <br>): la conversione avviene qui, in entrambe le direzioni.
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

const textarea = ref<HTMLTextAreaElement | null>(null)
const text = ref(captionToEditableText(props.modelValue))
/** Ultimo valore mandato al form: se torna uguale non si tocca il testo (e il cursore). */
let emitted = props.modelValue

const nearLimit = computed(() => text.value.length > props.limit * 0.9)

function onInput(): void {
  emitted = editableTextToCaptionHtml(text.value)
  emit('update:modelValue', emitted)
  resize()
}

/** Altezza pari al contenuto: niente barra di scorrimento dentro il campo. */
function resize(): void {
  const element = textarea.value

  if (element) {
    element.style.height = 'auto'
    // border-box: all'altezza del contenuto vanno sommati i bordi.
    element.style.height = `${element.scrollHeight + element.offsetHeight - element.clientHeight}px`
  }
}

// Il form può riscrivere il valore da fuori (reset dopo il salvataggio).
watch(
  () => props.modelValue,
  (value) => {
    if (value !== emitted) {
      emitted = value
      text.value = captionToEditableText(value)
      void nextTick(resize)
    }
  },
)

onMounted(resize)
</script>

<template>
  <div>
    <textarea
      ref="textarea"
      v-model="text"
      rows="3"
      :placeholder="placeholder"
      :maxlength="limit"
      :aria-labelledby="ariaLabelledby"
      class="block min-h-24 w-full resize-none overflow-hidden rounded-xl border-2 border-line bg-card px-3 py-2 leading-relaxed text-ink focus:border-muted focus:outline-none"
      @input="onInput"
    />
    <p v-if="nearLimit" class="mt-1 text-right text-xs text-muted">{{ text.length }}/{{ limit }}</p>
  </div>
</template>
