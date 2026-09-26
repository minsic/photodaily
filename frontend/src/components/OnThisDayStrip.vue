<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'

import type { OnThisDay } from '@/api/types'
import AppIcon from '@/components/AppIcon.vue'
import { useDiary } from '@/diary/useDiary'
import { describeAge } from '@/utils/age'
import { formatLongDate } from '@/utils/date'
import { hideFor, isHiddenFor, yearsAgoLabel } from '@/utils/onThisDay'

/**
 * "Un anno fa oggi" in cima alla timeline: le foto dello stesso giorno
 * degli anni passati, una per anno. Un tocco apre il lettore; la × la
 * nasconde fino a domani, su questo dispositivo.
 */
const diary = useDiary()
const data = ref<OnThisDay | null>(null)
const hidden = ref(false)

const years = computed(() => data.value?.anni ?? [])

function hide(): void {
  if (data.value) {
    hideFor(data.value.oggi)
  }

  hidden.value = true
}

onMounted(async () => {
  // Se non arriva pazienza: è un di più, la timeline resta com'è.
  data.value = await diary.value.onThisDay().catch(() => null)
  hidden.value = data.value !== null && isHiddenFor(data.value.oggi)
})
</script>

<template>
  <section v-if="years.length > 0 && !hidden" class="mt-4 rounded-2xl bg-card p-3 card-shadow" aria-labelledby="anni-fa-titolo">
    <header class="flex items-center justify-between gap-2 px-1">
      <h2 id="anni-fa-titolo" class="text-sm font-bold">
        {{ years.length === 1 ? `${yearsAgoLabel(years[0]!.anni)} oggi` : 'Oggi, negli anni passati' }}
      </h2>
      <button
        type="button"
        class="flex size-8 items-center justify-center rounded-full text-muted hover:text-ink"
        aria-label="Nascondi fino a domani"
        @click="hide"
      >
        <AppIcon name="close" class="size-4" />
      </button>
    </header>

    <ul class="mt-2 flex snap-x gap-3 overflow-x-auto pb-1">
      <li v-for="year in years" :key="year.id" class="shrink-0 snap-start" :class="years.length === 1 ? 'w-full' : 'w-40'">
        <RouterLink :to="diary.to.photo(year.id)" class="block">
          <div class="relative overflow-hidden rounded-xl bg-paper" :class="years.length === 1 ? 'aspect-4/3' : 'aspect-4/5'">
            <img
              :src="year.medium_url ?? year.thumbnail_url"
              :alt="`Foto del ${formatLongDate(year.data)}`"
              loading="lazy"
              decoding="async"
              class="size-full object-cover"
            />
            <span class="absolute left-2 top-2 rounded-full bg-black/55 px-2 py-0.5 text-xs font-bold text-white">
              {{ yearsAgoLabel(year.anni) }}
            </span>
            <AppIcon v-if="year.speciale" name="star" filled class="absolute right-2 top-2 size-4 text-brick drop-shadow" />
          </div>
          <p class="mt-1.5 text-xs font-bold">{{ formatLongDate(year.data) }}</p>
          <p v-if="describeAge(diary.protagonist?.birthdate, year.data, diary.protagonist?.name)" class="text-xs text-muted">
            {{ describeAge(diary.protagonist?.birthdate, year.data, diary.protagonist?.name) }}
          </p>
          <p v-if="year.foto > 1" class="text-xs text-muted">{{ year.foto }} foto quel giorno</p>
        </RouterLink>
      </li>
    </ul>
  </section>
</template>
