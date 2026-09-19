<script setup lang="ts">
import { ref } from 'vue'

import AppIcon from '@/components/AppIcon.vue'

const props = withDefaults(defineProps<{ value: string; label?: string }>(), { label: 'Copia' })

const copied = ref(false)
const failed = ref(false)

async function copy(): Promise<void> {
  failed.value = false

  try {
    await navigator.clipboard.writeText(props.value)
    copied.value = true
    window.setTimeout(() => (copied.value = false), 2000)
  } catch {
    // Succede senza HTTPS o senza permesso: il link resta selezionabile a mano.
    failed.value = true
  }
}
</script>

<template>
  <button
    type="button"
    class="flex shrink-0 items-center gap-1.5 rounded-lg border-2 border-line px-2.5 py-1.5 text-sm font-bold transition hover:text-ink"
    :class="copied ? 'border-azure text-azure' : 'text-muted'"
    @click="copy"
  >
    <AppIcon :name="copied ? 'check' : 'copy'" class="size-4" />
    {{ copied ? 'Copiato' : failed ? 'Copia a mano' : label }}
  </button>
</template>
