<script setup lang="ts">
import { computed } from 'vue'

import type { YearSummary } from '@/api/types'
import AppIcon from '@/components/AppIcon.vue'
import type { TimelineOrder } from '@/utils/timelineOrder'

/**
 * Barra filtri senza stato: la timeline autenticata e quella pubblica le
 * passano i propri valori. La vista pubblica nasconde il filtro bozze.
 */
const props = withDefaults(
  defineProps<{
    years: YearSummary[]
    /** null = tutti gli anni. */
    anno: number | null
    ordine: TimelineOrder
    speciali: boolean
    bozze?: boolean
    withDrafts?: boolean
  }>(),
  { bozze: false, withDrafts: true },
)

const emit = defineEmits<{
  'update:anno': [value: number | null]
  'update:ordine': [value: TimelineOrder]
  'update:speciali': [value: boolean]
  'update:bozze': [value: boolean]
}>()

const total = computed(() => props.years.reduce((sum, year) => sum + year.foto, 0))

function onYearChange(event: Event): void {
  const value = (event.target as HTMLSelectElement).value

  emit('update:anno', value === '' ? null : Number(value))
}
</script>

<template>
  <div class="flex flex-wrap items-center gap-2 py-4">
    <label class="sr-only" for="anno">Anno</label>
    <select
      id="anno"
      class="rounded-full border-2 border-line bg-transparent py-1.5 pl-3 pr-8 text-sm font-bold text-ink disabled:opacity-50"
      :value="anno ?? ''"
      :disabled="bozze || years.length === 0"
      @change="onYearChange"
    >
      <!-- Il popup nativo non eredita il tema: senza colori espliciti nel
           dark diventa testo chiaro su fondo bianco. -->
      <option value="" class="bg-card text-ink">Tutti gli anni · {{ total }} foto</option>
      <option v-for="year in years" :key="year.anno" :value="year.anno" class="bg-card text-ink">
        {{ year.anno }} · {{ year.foto }} foto
      </option>
    </select>

    <button
      type="button"
      class="flex items-center gap-1.5 rounded-full border-2 border-line px-3 py-1.5 text-sm font-bold text-muted transition hover:text-ink"
      :title="ordine === 'desc' ? 'Mostra dal primo giorno' : 'Mostra dal più recente'"
      @click="emit('update:ordine', ordine === 'desc' ? 'asc' : 'desc')"
    >
      <AppIcon name="sort" class="size-4" />
      {{ ordine === 'desc' ? 'Dal più recente' : 'Dal primo giorno' }}
    </button>

    <button
      type="button"
      class="flex items-center gap-1.5 rounded-full border-2 px-3 py-1.5 text-sm font-bold transition"
      :class="speciali ? 'border-brick bg-brick text-white' : 'border-line text-muted hover:text-ink'"
      :aria-pressed="speciali"
      @click="emit('update:speciali', !speciali)"
    >
      <AppIcon name="star" :filled="speciali" class="size-4" />
      Speciali
    </button>

    <button
      v-if="withDrafts"
      type="button"
      class="rounded-full border-2 px-3 py-1.5 text-sm font-bold transition"
      :class="bozze ? 'border-azure bg-azure text-white' : 'border-line text-muted hover:text-ink'"
      :aria-pressed="bozze"
      @click="emit('update:bozze', !bozze)"
    >
      Bozze
    </button>
  </div>
</template>
