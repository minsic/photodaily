<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'

import { photos as photosApi } from '@/api'
import { ApiError } from '@/api/client'
import type { PhotoCalendar } from '@/api/types'
import AppHeader from '@/components/AppHeader.vue'
import AppIcon from '@/components/AppIcon.vue'
import AppSpinner from '@/components/AppSpinner.vue'
import { useFamilyToday } from '@/composables/useFamilyToday'
import { usePhotosStore } from '@/stores/photos'
import { buildCalendar, describeProgress, type CalendarDay } from '@/utils/calendar'

/**
 * Un quadratino per giorno: pieno apre la foto, vuoto apre il caricamento
 * con la data già impostata. Si contano i giorni con la foto, niente serie:
 * un giorno saltato non "rompe" niente.
 */
const photos = usePhotosStore()
const { today } = useFamilyToday()

const currentYear = Number(today().slice(0, 4))
const anno = ref(currentYear)
const calendar = ref<PhotoCalendar | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)

const years = computed(() =>
  [...new Set([currentYear, ...photos.years.map((year) => year.anno)])].sort((a, b) => b - a),
)
const months = computed(() => (calendar.value ? buildCalendar(calendar.value) : []))
const progress = computed(() => (calendar.value ? describeProgress(calendar.value) : null))

const monthName = new Intl.DateTimeFormat('it-IT', { month: 'long', timeZone: 'UTC' })
const dayName = new Intl.DateTimeFormat('it-IT', { day: 'numeric', month: 'long', timeZone: 'UTC' })
const weekdays = ['L', 'M', 'M', 'G', 'V', 'S', 'D']

function nameOfMonth(month: number): string {
  return monthName.format(new Date(Date.UTC(anno.value, month - 1, 1)))
}

function label(day: CalendarDay): string {
  const when = dayName.format(new Date(`${day.date}T00:00:00Z`))

  switch (day.state) {
    case 'pieno':
      return day.photos > 1 ? `${when}: ${day.photos} foto` : `${when}: c'è la foto`
    case 'bozza':
      return `${when}: c'è una bozza`
    case 'vuoto':
      return `${when}: aggiungi la foto`
    default:
      return when
  }
}

async function load(): Promise<void> {
  loading.value = true
  error.value = null

  try {
    calendar.value = await photosApi.calendar(anno.value)
  } catch (cause) {
    error.value = cause instanceof ApiError ? cause.message : 'Non riesco a caricare il calendario.'
  } finally {
    loading.value = false
  }
}

function onYear(event: Event): void {
  anno.value = Number((event.target as HTMLSelectElement).value)
  void load()
}

onMounted(async () => {
  if (photos.years.length === 0) {
    await photos.loadYears().catch(() => undefined)
  }

  await load()
})
</script>

<template>
  <AppHeader>
    <template #left>
      <RouterLink
        :to="{ name: 'timeline' }"
        class="flex items-center gap-1.5 rounded-lg py-1 font-bold text-ink"
      >
        <AppIcon name="back" class="size-5" />
        Timeline
      </RouterLink>
    </template>
  </AppHeader>

  <main class="mx-auto max-w-3xl px-4 pb-16">
    <header class="mt-6 flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold tracking-tight">Calendario</h1>
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

    <p class="mt-2 text-sm text-muted">Tocca un giorno vuoto per aggiungere la sua foto.</p>

    <AppSpinner v-if="loading" />

    <p v-else-if="error" class="mt-6 rounded-xl bg-brick/10 px-4 py-3 text-sm text-brick">
      {{ error }}
      <button type="button" class="ml-2 font-bold underline" @click="load">Riprova</button>
    </p>

    <div v-else class="mt-6 grid gap-x-8 gap-y-6 sm:grid-cols-2">
      <section v-for="month in months" :key="month.month" :aria-label="nameOfMonth(month.month)">
        <h2 class="mb-2 text-sm font-bold capitalize">{{ nameOfMonth(month.month) }}</h2>

        <div class="grid grid-cols-7 gap-1 text-center text-[10px] font-bold text-muted" aria-hidden="true">
          <span v-for="(weekday, index) in weekdays" :key="index">{{ weekday }}</span>
        </div>

        <ol class="mt-1 grid grid-cols-7 gap-1">
          <li v-for="blank in month.offset" :key="`vuoto-${blank}`" aria-hidden="true" />
          <li v-for="day in month.days" :key="day.date">
            <RouterLink
              v-if="day.state === 'pieno' || day.state === 'bozza'"
              :to="{ name: 'photo', params: { id: day.photoId } }"
              :aria-label="label(day)"
              :title="label(day)"
              class="flex aspect-square items-center justify-center rounded-md text-[10px] font-bold"
              :class="
                day.state === 'pieno'
                  ? day.special
                    ? 'bg-brick text-white ring-2 ring-brick/40 ring-offset-1 ring-offset-paper'
                    : 'bg-brick text-white/85'
                  : 'bg-azure/30 text-ink'
              "
            >
              {{ day.day }}
            </RouterLink>
            <RouterLink
              v-else-if="day.state === 'vuoto'"
              :to="{ name: 'upload', query: { data: day.date } }"
              :aria-label="label(day)"
              :title="label(day)"
              class="flex aspect-square items-center justify-center rounded-md border border-line text-[10px] text-muted transition hover:border-brick hover:text-brick"
            >
              {{ day.day }}
            </RouterLink>
            <span
              v-else
              class="flex aspect-square items-center justify-center rounded-md text-[10px] text-muted/40"
              :aria-label="label(day)"
            >
              {{ day.day }}
            </span>
          </li>
        </ol>
      </section>
    </div>
  </main>
</template>
