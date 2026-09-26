<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue'

import type { SequenceFilters, SequenceItem } from '@/api/types'
import AppIcon from '@/components/AppIcon.vue'
import type { Diary } from '@/diary/useDiary'
import { describeAge } from '@/utils/age'
import { formatLongDate } from '@/utils/date'

import {
  BUFFER_AHEAD,
  decodeImage,
  ImageBuffer,
  SlideFeed,
  slideSrc,
  SPEED_DELAY,
  type SlideshowSpeed,
} from './slideshow'

/**
 * Slideshow a schermo intero, modalità del lettore. Lento e Normale in
 * dissolvenza (senza, con prefers-reduced-motion); Crescita a 8 foto al
 * secondo con ~20 immagini precaricate: se il buffer si svuota rallenta,
 * non mostra buchi. Schermo sempre acceso finché gira.
 */
const props = defineProps<{
  diary: Diary
  filters: SequenceFilters
  speed: SlideshowSpeed
  loop: boolean
}>()

const emit = defineEmits<{ close: [lastId: string | null] }>()

const reducedMotion =
  typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches
const fade = props.speed !== 'crescita' && !reducedMotion
const growth = props.speed === 'crescita'

const root = ref<HTMLElement | null>(null)
const current = shallowRef<SequenceItem | null>(null)
const previous = shallowRef<SequenceItem | null>(null)
const index = ref(0)
const paused = ref(false)
const finished = ref(false)
const loopOn = ref(props.loop)
const controlsVisible = ref(true)
const message = ref<string | null>(null)
const total = ref(0)

const feed = new SlideFeed((filters) => props.diary.sequence(filters), props.filters)
const buffer = new ImageBuffer(decodeImage)

let timer: number | undefined
let hideTimer: number | undefined
let wakeLock: WakeLockSentinel | null = null
let stopped = false

const age = computed(() =>
  current.value ? describeAge(props.diary.protagonist?.birthdate, current.value.data, props.diary.protagonist?.name) : null,
)

async function start(): Promise<void> {
  try {
    await feed.ensure(0)
  } catch {
    message.value = 'Non riesco a caricare le foto dello slideshow.'

    return
  }

  if (feed.items.length === 0) {
    message.value = 'Nessuna foto da mostrare con questa scelta.'

    return
  }

  show(0)
  schedule()
}

function show(position: number): void {
  previous.value = fade ? current.value : null
  current.value = feed.items[position] ?? null
  index.value = position
  total.value = feed.items.length
  fillBuffer()

  // Il blocco successivo arriva prima di finire questo.
  void feed.ensure(position).then(() => {
    total.value = feed.items.length
    fillBuffer()
  }).catch(() => undefined)
}

function fillBuffer(): void {
  const ahead = feed.items.slice(index.value + 1, index.value + 1 + BUFFER_AHEAD[props.speed]).map(slideSrc)

  buffer.fill(ahead)
  buffer.keepOnly(new Set([...ahead, ...(current.value ? [slideSrc(current.value)] : [])]))
}

function schedule(delay = SPEED_DELAY[props.speed]): void {
  window.clearTimeout(timer)

  if (!stopped) {
    timer = window.setTimeout(() => void tick(), delay)
  }
}

async function tick(): Promise<void> {
  if (paused.value || stopped) {
    return
  }

  let next = index.value + 1

  if (next >= feed.items.length) {
    if (!feed.complete) {
      // Il blocco successivo è in arrivo: si aspetta un attimo.
      await feed.ensure(index.value).catch(() => undefined)
      schedule(100)

      return
    }

    if (loopOn.value) {
      feed.reset()
      buffer.clear()
      await feed.ensure(0).catch(() => undefined)

      if (feed.items.length > 0) {
        show(0)
        schedule()
      }

      return
    }

    finished.value = true
    controlsVisible.value = true

    return
  }

  // Le foto che non si caricano si saltano.
  while (next < feed.items.length - 1 && buffer.failed(slideSrc(feed.items[next]!))) {
    next++
  }

  const src = slideSrc(feed.items[next]!)

  if (!buffer.settled(src)) {
    // Buffer vuoto: si rallenta, non si mostra una foto a metà.
    buffer.fill([src])
    schedule(50)

    return
  }

  show(next)
  schedule()
}

function step(direction: 1 | -1): void {
  const position = index.value + direction

  if (position >= 0 && position < feed.items.length) {
    finished.value = false
    show(position)
    schedule()
  }
}

function togglePause(): void {
  if (finished.value) {
    return
  }

  paused.value = !paused.value
  revealControls()

  if (!paused.value) {
    schedule()
  }
}

function restart(): void {
  finished.value = false
  paused.value = false
  show(0)
  schedule()
}

/** I controlli spariscono dopo 2 secondi fermi, finché lo slideshow gira. */
function revealControls(): void {
  controlsVisible.value = true
  window.clearTimeout(hideTimer)
  hideTimer = window.setTimeout(() => {
    if (!paused.value && !finished.value) {
      controlsVisible.value = false
    }
  }, 2000)
}

async function toggleFullscreen(): Promise<void> {
  if (document.fullscreenElement) {
    await document.exitFullscreen().catch(() => undefined)
  } else {
    await root.value?.requestFullscreen?.().catch(() => undefined)
  }
}

async function keepScreenOn(): Promise<void> {
  if (!('wakeLock' in navigator) || document.visibilityState !== 'visible') {
    return
  }

  try {
    wakeLock = await navigator.wakeLock.request('screen')
  } catch {
    wakeLock = null
  }
}

/** Il blocco dello schermo si perde quando si cambia app: lo si riprende al ritorno. */
function onVisibilityChange(): void {
  if (document.visibilityState === 'visible' && !stopped) {
    void keepScreenOn()
  }
}

function onKeydown(event: KeyboardEvent): void {
  event.stopImmediatePropagation()

  if (event.key === ' ') {
    event.preventDefault()
    togglePause()
  } else if (event.key === 'ArrowRight') {
    step(1)
  } else if (event.key === 'ArrowLeft') {
    step(-1)
  } else if (event.key === 'Escape' && !document.fullscreenElement) {
    close()
  }

  revealControls()
}

function close(): void {
  stopped = true
  window.clearTimeout(timer)
  emit('close', current.value?.id ?? null)
}

onMounted(() => {
  // Si parte dal tocco su "Avvia": il browser concede ancora lo schermo intero.
  void root.value?.requestFullscreen?.().catch(() => undefined)
  void keepScreenOn()
  document.addEventListener('visibilitychange', onVisibilityChange)
  window.addEventListener('keydown', onKeydown, true)
  revealControls()
  void start()
})

onBeforeUnmount(() => {
  stopped = true
  window.clearTimeout(timer)
  window.clearTimeout(hideTimer)
  document.removeEventListener('visibilitychange', onVisibilityChange)
  window.removeEventListener('keydown', onKeydown, true)
  void wakeLock?.release().catch(() => undefined)

  if (document.fullscreenElement) {
    void document.exitFullscreen().catch(() => undefined)
  }
})
</script>

<template>
  <div
    ref="root"
    class="fixed inset-0 z-50 select-none bg-black text-white"
    :class="{ 'cursor-none': !controlsVisible }"
    role="dialog"
    aria-modal="true"
    aria-label="Slideshow"
    @pointermove="revealControls"
  >
    <img
      v-if="previous"
      :key="`prima-${previous.id}`"
      :src="slideSrc(previous)"
      alt=""
      class="pointer-events-none absolute inset-0 size-full object-contain"
    />
    <img
      v-if="current"
      :key="current.id"
      :src="slideSrc(current)"
      alt=""
      class="pointer-events-none absolute inset-0 size-full object-contain"
      :class="{ 'slide-fade-in': fade }"
    />
    <div class="absolute inset-0" @click="togglePause" />

    <!-- Data ed età sopra la foto; in Crescita un contatore in basso. -->
    <div
      v-if="current && !growth"
      class="pointer-events-none absolute inset-x-0 top-0 bg-linear-to-b from-black/60 to-transparent px-5 pt-[max(1rem,env(safe-area-inset-top))] pb-10"
    >
      <p class="flex items-center gap-2 text-lg font-bold">
        {{ formatLongDate(current.data) }}
        <AppIcon v-if="current.data_speciale" name="star" filled class="size-4 text-brick" />
      </p>
      <p v-if="age" class="text-sm text-white/80">{{ age }}</p>
    </div>

    <p
      v-if="current && growth"
      class="pointer-events-none absolute inset-x-0 bottom-24 text-center text-3xl font-bold tabular-nums drop-shadow"
    >
      {{ formatLongDate(current.data) }}
    </p>

    <p v-if="message" class="absolute inset-0 flex items-center justify-center px-8 text-center">{{ message }}</p>

    <div v-if="finished" class="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-black/60">
      <p class="text-xl font-bold">Fine</p>
      <div class="flex gap-2">
        <button type="button" class="rounded-xl bg-white/15 px-4 py-2 font-bold" @click="restart">Ricomincia</button>
        <button type="button" class="rounded-xl bg-white px-4 py-2 font-bold text-black" @click="close">Chiudi</button>
      </div>
    </div>

    <div
      class="absolute inset-x-0 bottom-0 flex items-center justify-center gap-2 px-4 pt-6 pb-[max(1rem,env(safe-area-inset-bottom))] transition-opacity duration-300"
      :class="controlsVisible ? 'opacity-100' : 'pointer-events-none opacity-0'"
    >
      <button type="button" class="flex size-11 items-center justify-center rounded-full bg-white/15" aria-label="Chiudi slideshow" @click="close">
        <AppIcon name="close" class="size-5" />
      </button>
      <button
        type="button"
        class="flex size-14 items-center justify-center rounded-full bg-white text-black"
        :aria-label="paused ? 'Riprendi' : 'Pausa'"
        @click="togglePause"
      >
        <AppIcon :name="paused ? 'play' : 'pause'" :filled="paused" class="size-6" />
      </button>
      <button
        type="button"
        class="flex size-11 items-center justify-center rounded-full"
        :class="loopOn ? 'bg-brick' : 'bg-white/15'"
        :aria-pressed="loopOn"
        aria-label="Ricomincia da capo alla fine"
        @click="loopOn = !loopOn"
      >
        <AppIcon name="repeat" class="size-5" />
      </button>
      <button type="button" class="flex size-11 items-center justify-center rounded-full bg-white/15" aria-label="Schermo intero" @click="toggleFullscreen">
        <AppIcon name="expand" class="size-5" />
      </button>
      <span v-if="total" class="ml-2 text-xs tabular-nums text-white/70">{{ index + 1 }} / {{ total }}{{ feed.complete ? '' : '+' }}</span>
    </div>
  </div>
</template>
