<script setup lang="ts">
import { computed } from 'vue'

import { LOGO, type LogoVariant } from './logoPaths'

/**
 * Logo PhotoDaily inline: il simbolo ha i suoi colori, la scritta prende il
 * colore del testo (text-ink), quindi va bene sia col tema chiaro sia con lo
 * scuro. L'altezza si dà con una classe (es. h-8): la larghezza segue.
 */
const props = withDefaults(defineProps<{ variant?: LogoVariant; title?: string }>(), {
  variant: 'orizzontale',
  title: 'PhotoDaily',
})

const shape = computed(() => LOGO[props.variant])
</script>

<template>
  <svg :viewBox="shape.viewBox" role="img" :aria-label="title" class="w-auto shrink-0">
    <path
      v-for="(part, index) in shape.symbol"
      :key="`s${index}`"
      :d="part.d"
      :fill="part.fill"
      fill-rule="evenodd"
    />
    <path v-for="(d, index) in shape.word" :key="`w${index}`" :d="d" fill="currentColor" />
  </svg>
</template>
