<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'

import { ApiError } from '@/api/client'
import type { PhotoCalendar } from '@/api/types'
import AppHeader from '@/components/AppHeader.vue'
import AppIcon from '@/components/AppIcon.vue'
import AppSpinner from '@/components/AppSpinner.vue'
import { useFamilyToday } from '@/composables/useFamilyToday'
import { useDiary } from '@/diary/useDiary'
import { buildCalendar, describeProgress } from '@/utils/calendar'

/**
 * Vista Anno: la heatmap dei dodici mesi. Un tocco su un mese apre la vista
 * Mese; ci si arriva anche col pizzico verso l'interno sulla vista Mese.
 * Si contano i giorni con la foto, niente serie: un giorno saltato non
 * "rompe" niente.
 */
const props = defineProps<{ anno: number }>()

const diary = useDiary()
const router = useRouter()
const { today } = useFamilyToday()

const currentYear = Number(today().slice(0, 4))
const calendar = ref<PhotoCalendar | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)
const knownYears = ref<number[]>([])

const years = computed(() => [...new Set([currentYear, props.anno, ...knownYears.value])].sort((a, b) => b - a))
const months = computed(() => (calendar.value ? buildCalendar(calendar.value) : []))
const progress = computed(() => (calendar.value ? describeProgress(calendar.value) : null))

const monthName = new Intl.DateTimeFormat('it-IT', { month: 'long', timeZone: 'UTC' })
const weekdays = ['L', 'M', 'M', 'G', 'V', 'S', 'D']

function nameOfMonth(month: number): string {
  return monthName.format(new Date(Date.UTC(props.anno, month - 1, 1)))
}

function monthSummary(month: ReturnType<typeof buildCalendar>[number]): string {
  const full = month.days.filter((day) => day.state === 'pieno').length

  return `${nameOfMonth(month.month)}: ${full} ${full === 1 ? 'giorno' : 'giorni'} con la foto. Apri il mese`
}

async function load(): Promise<void> {
  loading.value = true
  error.value = null

  try {
    calendar.value = await diary.value.year(props.anno)
  } catch (cause) {
    error.value = cause instanceof ApiError ? cause.message : 'Non riesco a caricare il calendario.'
  } finally {
    loading.value = false
  }
}

function onYear(event: Event): void {
  void router.replace(diary.value.to.year(Number((event.target as HTMLSelectElement).value)))
}

onMounted(async () => {
  knownYears.value = await diary.value
    .years()
    .then((list) => list.map((year) => year.anno))
    .catch(() => [])
})

watch(() => props.anno, load, { immediate: true })
</script>

<template>
  <AppHeader>
    <template #left>
      <RouterLink
        :to="diary.to.month(anno, anno === currentYear ? Number(today().slice(5, 7)) : 12)"
        class="flex items-center gap-1.5 rounded-lg py-1 font-bold text-ink"
      >
        <AppIcon name="back" class="size-5" />
        Mese
      </RouterLink>
    </template>
  </AppHeader>

  <main class="mx-auto max-w-3xl px-4 pb-16">
    <header class="mt-6 flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold tracking-tight">Anno {{ anno }}</h1>
        <p v-if="progress" class="mt-1 text-muted">
          <strong class="text-ink">{{ progress }}</strong> hanno la loro foto
        </p>
      </div>

      <label class="sr-only" for="calendar-year">Anno</label>
      <select
        id="calendar-year"
        :value="anno"
        class="rounded-full border-2 border-line bg-transparent py-1.5 pl-3 pr-8 text-sm font-bold text-ink"
        @change="onYear"
      >
        <option v-for="year in years" :key="year" :value="year" class="bg-card text-ink">{{ year }}</option>
      </select>
    </header>

    <p class="mt-2 text-sm text-muted">Tocca un mese per vederlo con le foto.</p>

    <RouterLink
      v-if="diary.canUpload"
      :to="{ name: 'recover' }"
      class="mt-4 flex items-center justify-center gap-2 rounded-2xl border-2 border-brick px-4 py-3 font-bold text-brick"
    >
      <AppIcon name="camera" class="size-5" />
      Recupera giorni mancanti
    </RouterLink>

    <AppSpinner v-if="loading" />

    <p v-else-if="error" class="mt-6 rounded-xl bg-brick/10 px-4 py-3 text-sm text-brick">
      {{ error }}
      <button type="button" class="ml-2 font-bold underline" @click="load">Riprova</button>
    </p>

    <div v-else class="mt-6 grid gap-x-8 gap-y-4 sm:grid-cols-2">
      <RouterLink
        v-for="month in months"
        :key="month.month"
        :to="diary.to.month(anno, month.month)"
        :aria-label="monthSummary(month)"
        class="block rounded-xl p-2 transition hover:bg-card"
      >
        <h2 class="mb-2 text-sm font-bold capitalize">{{ nameOfMonth(month.month) }}</h2>

        <div class="grid grid-cols-7 gap-1 text-center text-[10px] font-bold text-muted" aria-hidden="true">
          <span v-for="(weekday, index) in weekdays" :key="index">{{ weekday }}</span>
        </div>

        <ol class="mt-1 grid grid-cols-7 gap-1" aria-hidden="true">
          <li v-for="blank in month.offset" :key="`vuoto-${blank}`" />
          <li
            v-for="day in month.days"
            :key="day.date"
            class="flex aspect-square items-center justify-center rounded-md text-[10px] font-bold"
            :class="
              day.state === 'pieno'
                ? day.special
                  ? 'bg-brick text-white ring-2 ring-brick/40 ring-offset-1 ring-offset-paper'
                  : 'bg-brick text-white/85'
                : day.state === 'bozza'
                  ? 'bg-azure/30 text-ink'
                  : day.state === 'vuoto'
                    ? 'border border-line font-normal text-muted'
                    : 'font-normal text-muted/40'
            "
          >
            {{ day.day }}
          </li>
        </ol>
      </RouterLink>
    </div>
  </main>
</template>
