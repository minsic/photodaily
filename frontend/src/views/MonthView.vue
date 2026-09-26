<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'

import { ApiError } from '@/api/client'
import type { PhotoMonth } from '@/api/types'
import AppHeader from '@/components/AppHeader.vue'
import AppIcon from '@/components/AppIcon.vue'
import AppSpinner from '@/components/AppSpinner.vue'
import ViewSwitch from '@/components/ViewSwitch.vue'
import AppLogo from '@/components/logo/AppLogo.vue'
import { useInfiniteScroll } from '@/composables/useInfiniteScroll'
import { useDiary } from '@/diary/useDiary'
import { usePublicDiaryStore } from '@/stores/publicDiary'
import { formatLongDate } from '@/utils/date'
import { distance, type Point } from '@/utils/gestures'
import {
  buildMonthGrid,
  describeMonthCount,
  formatMonth,
  isBeforeDiary,
  previousMonth,
  type MonthCell,
} from '@/utils/monthGrid'

/**
 * Vista Mese: calendario con le miniature, dal mese scelto all'indietro con
 * scroll continuo (un mese alla volta, quando ci si avvicina al fondo).
 * Un giorno pieno apre il lettore; un giorno vuoto apre il caricamento con
 * quella data, per chi può caricare. Pizzico verso l'interno: vista Anno.
 */
const props = defineProps<{ anno: number; mese: number }>()

const diary = useDiary()
const router = useRouter()
const publicDiary = usePublicDiaryStore()

const months = ref<PhotoMonth[]>([])
const loading = ref(true)
const loadingMore = ref(false)
const error = ref<string | null>(null)
const locked = ref(false)
const reachedStart = ref(false)
const years = ref<number[]>([])

const grids = computed(() => months.value.map((month) => ({ data: month, grid: buildMonthGrid(month) })))
const weekdays = ['L', 'M', 'M', 'G', 'V', 'S', 'D']
const monthNames = Array.from({ length: 12 }, (_, index) =>
  new Intl.DateTimeFormat('it-IT', { month: 'long', timeZone: 'UTC' }).format(new Date(Date.UTC(2000, index, 1))),
)

const today = computed(() => months.value[0]?.oggi ?? null)
const pickerYears = computed(() => {
  const current = today.value ? Number(today.value.slice(0, 4)) : props.anno

  return [...new Set([current, props.anno, ...years.value])].sort((a, b) => b - a)
})

async function fetchMonth(year: number, month: number): Promise<PhotoMonth> {
  return diary.value.month(year, month)
}

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  locked.value = false
  reachedStart.value = false
  months.value = []

  try {
    months.value = [await fetchMonth(props.anno, props.mese)]
    checkStart()
  } catch (cause) {
    handleError(cause)
  } finally {
    loading.value = false
  }
}

async function loadMore(): Promise<void> {
  const last = months.value.at(-1)

  if (!last || loadingMore.value || reachedStart.value) {
    return
  }

  loadingMore.value = true

  try {
    const { year, month } = previousMonth(last.anno, last.mese)

    months.value.push(await fetchMonth(year, month))
    checkStart()
  } catch (cause) {
    handleError(cause)
  } finally {
    loadingMore.value = false
  }
}

/** Il mese prima di quello caricato per ultimo è già fuori dal diario. */
function checkStart(): void {
  const last = months.value.at(-1)

  if (last) {
    const { year, month } = previousMonth(last.anno, last.mese)
    reachedStart.value = isBeforeDiary(year, month, last.inizio_diario) || last.inizio_diario === null
  }
}

function handleError(cause: unknown): void {
  if (diary.value.kind === 'public' && cause instanceof ApiError && (cause.status === 401 || cause.status === 404)) {
    locked.value = true
    publicDiary.handleAccessError(cause)

    return
  }

  error.value = cause instanceof ApiError ? cause.message : 'Non riesco a caricare il mese.'
}

function retry(): void {
  if (months.value.length === 0) {
    void load()

    return
  }

  error.value = null
  void loadMore()
}

const sentinel = ref<HTMLElement | null>(null)
useInfiniteScroll(sentinel, loadMore, () => !loading.value && !reachedStart.value && error.value === null && !locked.value)

function goToMonth(year: number, month: number): void {
  void router.replace(diary.value.to.month(year, month))
}

function onPickYear(event: Event): void {
  goToMonth(Number((event.target as HTMLSelectElement).value), props.mese)
}

function onPickMonth(event: Event): void {
  goToMonth(props.anno, Number((event.target as HTMLSelectElement).value))
}

function cellLabel(cell: MonthCell): string {
  const when = formatLongDate(cell.date)

  switch (cell.state) {
    case 'pieno':
      return cell.photo!.foto > 1 ? `${when}: ${cell.photo!.foto} foto` : when
    case 'bozza':
      return `${when}: c'è una bozza`
    case 'vuoto':
      return diary.value.canUpload ? `${when}: aggiungi la foto` : `${when}: nessuna foto`
    default:
      return when
  }
}

/* Pizzico verso l'interno sulla griglia: si passa alla vista Anno. */
const pointers = new Map<number, Point>()
let pinchStart: number | null = null

function onPointerDown(event: PointerEvent): void {
  if (event.pointerType !== 'touch') {
    return
  }

  pointers.set(event.pointerId, { x: event.clientX, y: event.clientY })

  if (pointers.size === 2) {
    const [a, b] = [...pointers.values()] as [Point, Point]
    pinchStart = distance(a, b)
  }
}

function onPointerMove(event: PointerEvent): void {
  if (!pointers.has(event.pointerId)) {
    return
  }

  pointers.set(event.pointerId, { x: event.clientX, y: event.clientY })

  if (pinchStart !== null && pointers.size === 2) {
    const [a, b] = [...pointers.values()] as [Point, Point]

    if (distance(a, b) / pinchStart < 0.65) {
      pinchStart = null
      void router.push(diary.value.to.year(props.anno))
    }
  }
}

function onPointerEnd(event: PointerEvent): void {
  pointers.delete(event.pointerId)

  if (pointers.size < 2) {
    pinchStart = null
  }
}

onMounted(async () => {
  years.value = await diary.value
    .years()
    .then((list) => list.map((year) => year.anno))
    .catch(() => [])
})

watch(() => [props.anno, props.mese], load, { immediate: true })
</script>

<template>
  <AppHeader>
    <template #left>
      <RouterLink :to="diary.to.timeline()" class="rounded-lg text-ink" aria-label="PhotoDaily, torna alla timeline">
        <AppLogo class="h-8" />
      </RouterLink>
    </template>
    <template #right>
      <ViewSwitch current="mese" />
    </template>
  </AppHeader>

  <main class="mx-auto max-w-3xl px-4 pb-16">
    <div class="mt-4 flex flex-wrap items-center gap-2">
      <label class="sr-only" for="month-pick">Mese</label>
      <select
        id="month-pick"
        :value="mese"
        class="rounded-full border-2 border-line bg-transparent py-1.5 pl-3 pr-8 text-sm font-bold capitalize text-ink"
        @change="onPickMonth"
      >
        <option v-for="(name, index) in monthNames" :key="name" :value="index + 1" class="bg-card text-ink">{{ name }}</option>
      </select>
      <label class="sr-only" for="year-pick">Anno</label>
      <select
        id="year-pick"
        :value="anno"
        class="rounded-full border-2 border-line bg-transparent py-1.5 pl-3 pr-8 text-sm font-bold text-ink"
        @change="onPickYear"
      >
        <option v-for="year in pickerYears" :key="year" :value="year" class="bg-card text-ink">{{ year }}</option>
      </select>

      <RouterLink
        :to="diary.to.year(anno)"
        class="ml-auto flex items-center gap-1.5 rounded-full border-2 border-line px-3 py-1.5 text-sm font-bold text-muted hover:text-ink"
      >
        <AppIcon name="calendar" class="size-4" />
        Anno {{ anno }}
      </RouterLink>
    </div>

    <AppSpinner v-if="loading" />

    <div v-else-if="locked" class="py-16 text-center">
      <AppIcon name="lock" class="mx-auto size-8 text-muted" />
      <p class="mt-3 font-bold">Per vedere questo diario serve la password.</p>
      <RouterLink :to="diary.to.timeline()" class="mt-2 inline-block text-sm font-bold text-brick underline">Inseriscila qui</RouterLink>
    </div>

    <template v-else>
      <!-- touch-pan-y: il pizzico sulla griglia non ingrandisce la pagina, porta alla vista Anno. -->
      <div
        class="touch-pan-y"
        @pointerdown="onPointerDown"
        @pointermove="onPointerMove"
        @pointerup="onPointerEnd"
        @pointercancel="onPointerEnd"
      >
        <section v-for="{ data, grid } in grids" :key="`${data.anno}-${data.mese}`" class="mt-4" :aria-label="formatMonth(data.anno, data.mese)">
          <header class="sticky top-14 z-10 -mx-4 flex items-baseline justify-between gap-2 bg-paper/90 px-4 py-2 backdrop-blur">
            <h2 class="text-lg font-bold capitalize">{{ formatMonth(data.anno, data.mese) }}</h2>
            <p v-if="describeMonthCount(data)" class="text-sm text-muted">{{ describeMonthCount(data) }}</p>
          </header>

          <div class="grid grid-cols-7 gap-1 text-center text-[11px] font-bold text-muted" aria-hidden="true">
            <span v-for="(weekday, index) in weekdays" :key="index">{{ weekday }}</span>
          </div>

          <ol class="mt-1 grid grid-cols-7 gap-1">
            <li v-for="blank in grid.offset" :key="`vuoto-${blank}`" aria-hidden="true" />
            <li v-for="cell in grid.cells" :key="cell.date" class="aspect-square">
              <RouterLink
                v-if="cell.photo"
                :to="diary.to.photo(cell.photo.id)"
                :aria-label="cellLabel(cell)"
                class="relative block size-full overflow-hidden rounded-md bg-card"
              >
                <img
                  :src="cell.photo.thumbnail_url"
                  alt=""
                  loading="lazy"
                  decoding="async"
                  class="size-full object-cover"
                  :class="{ 'opacity-50': cell.state === 'bozza' }"
                />
                <span class="absolute left-1 top-0.5 text-[11px] font-bold text-white drop-shadow">{{ cell.day }}</span>
                <AppIcon
                  v-if="cell.photo.speciale"
                  name="star"
                  filled
                  class="absolute right-0.5 top-0.5 size-3.5 text-brick drop-shadow"
                />
                <span
                  v-if="cell.photo.bozze > 0"
                  class="absolute bottom-1 right-1 size-2 rounded-full bg-azure ring-1 ring-white"
                  title="C'è una bozza"
                />
              </RouterLink>

              <RouterLink
                v-else-if="cell.state === 'vuoto' && diary.canUpload"
                :to="{ name: 'upload', query: { data: cell.date } }"
                :aria-label="cellLabel(cell)"
                class="flex size-full flex-col items-center justify-center rounded-md border border-dashed border-line text-muted transition hover:border-brick hover:text-brick"
              >
                <span class="text-[11px]">{{ cell.day }}</span>
                <AppIcon name="plus" class="size-3.5" />
              </RouterLink>

              <span
                v-else
                class="flex size-full items-center justify-center rounded-md text-[11px]"
                :class="cell.state === 'vuoto' ? 'border border-line text-muted' : 'text-muted/40'"
                :aria-label="cellLabel(cell)"
                :aria-disabled="cell.state === 'futuro' || undefined"
              >
                {{ cell.day }}
              </span>
            </li>
          </ol>
        </section>
      </div>

      <p v-if="error" class="mt-6 rounded-xl bg-brick/10 px-4 py-3 text-sm text-brick">
        {{ error }}
        <button type="button" class="ml-2 font-bold underline" @click="retry">Riprova</button>
      </p>

      <div ref="sentinel" class="h-px" />
      <AppSpinner v-if="loadingMore" />
      <p v-if="reachedStart && months.length" class="mt-8 text-center text-sm text-muted">L'inizio del diario.</p>
    </template>
  </main>
</template>
