<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'

import { ApiError } from '@/api/client'
import type { Photo, SequenceFilters } from '@/api/types'
import AppIcon from '@/components/AppIcon.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { useDiary } from '@/diary/useDiary'
import { scopeFilters, type SlideshowScope, type SlideshowSpeed } from '@/slideshow/slideshow'
import SlideshowPanel from '@/slideshow/SlideshowPanel.vue'
import SlideshowPlayer from '@/slideshow/SlideshowPlayer.vue'
import { usePublicDiaryStore } from '@/stores/publicDiary'
import { useReaderStore } from '@/stores/reader'
import { useToastsStore } from '@/stores/toasts'
import { describeAge } from '@/utils/age'
import { captionToPlainText } from '@/utils/caption'
import { formatLongDate } from '@/utils/date'
import {
  clampTransform,
  containedSize,
  distance,
  DOUBLE_TAP_SCALE,
  IDENTITY,
  isTap,
  midpoint,
  swipeOutcome,
  zoomAround,
  type Point,
  type Size,
  type Transform,
} from '@/utils/gestures'
import { canRetrySignedUrl } from '@/utils/signedUrls'

/**
 * Lettore a schermo intero: si apre sopra la vista da cui arriva (App.vue la
 * tiene montata sotto), il tasto indietro lo chiude. Scorrimento con i
 * pointer events, pizzico e doppio tap per lo zoom, frecce ed Esc da
 * tastiera. Mostra la versione media; lo zoom carica la principale.
 */
const props = defineProps<{ id: string }>()

const diary = useDiary()
const router = useRouter()
const reader = useReaderStore()
const toasts = useToastsStore()
const publicDiary = usePublicDiaryStore()

interface Shown {
  src: string
  data: string
  size: Size | null
}

const photo = ref<Photo | null>(null)
/** Quello che si sa già prima della risposta: dalla timeline o dal link della foto accanto. */
const placeholder = ref<Shown | null>(null)
const error = ref<string | null>(null)
const uiVisible = ref(true)
const transform = ref<Transform>({ ...IDENTITY })
const dragX = ref(0)
const animating = ref(false)
/** URL della versione principale, una volta scaricata per lo zoom. */
const fullSrc = ref<string | null>(null)
const lastRetryAt = ref<number | null>(null)
const confirmingDelete = ref(false)
const deleting = ref(false)
const stage = ref<HTMLElement | null>(null)
const slideshowPanel = ref(false)
const slideshow = ref<{ filters: SequenceFilters; speed: SlideshowSpeed; loop: boolean } | null>(null)
const closeButton = ref<HTMLButtonElement | null>(null)

const reducedMotion =
  typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches

function sizeOf(photo: Pick<Photo, 'medium_width' | 'medium_height'> & Partial<Pick<Photo, 'width' | 'height'>>): Size | null {
  // Le misure della media sono già ruotate: quelle dell'originale importato a volte no.
  if (photo.medium_width && photo.medium_height) {
    return { width: photo.medium_width, height: photo.medium_height }
  }

  return photo.width && photo.height ? { width: photo.width, height: photo.height } : null
}

const shown = computed<Shown | null>(() => {
  if (photo.value) {
    return {
      src: fullSrc.value ?? photo.value.medium_url ?? photo.value.thumbnail_url,
      data: photo.value.data,
      size: sizeOf(photo.value),
    }
  }

  return placeholder.value
})

const neighbors = computed(() => ({
  prev: photo.value?.precedente ?? null,
  next: photo.value?.successiva ?? null,
}))

const age = computed(() =>
  shown.value ? describeAge(diary.value.protagonist?.birthdate, shown.value.data, diary.value.protagonist?.name) : null,
)

const altText = computed(() =>
  photo.value ? captionToPlainText(photo.value.didascalia) || `Foto del ${photo.value.data}` : 'Foto',
)

const imageStyle = computed(() => ({
  transform: `translate(${transform.value.x}px, ${transform.value.y}px) scale(${transform.value.scale})`,
}))

function slideStyle(offset: -1 | 0 | 1) {
  return { transform: `translateX(calc(${offset * 100}% + ${dragX.value}px))` }
}

async function load(id: string): Promise<void> {
  error.value = null
  fullSrc.value = null
  lastRetryAt.value = null
  transform.value = { ...IDENTITY }

  const cached = diary.value.cached(id)

  if (cached) {
    photo.value = { ...cached }
  }

  try {
    const fresh = await diary.value.photo(id)

    if (props.id !== id) {
      return
    }

    photo.value = fresh
    placeholder.value = null

    for (const link of [fresh.precedente, fresh.successiva]) {
      if (link) {
        preload(link.medium_url ?? link.thumbnail_url)
      }
    }
  } catch (cause) {
    if (props.id !== id || photo.value) {
      return
    }

    if (diary.value.kind === 'public' && cause instanceof ApiError && cause.status === 401) {
      error.value = 'Questo diario è protetto da password: aprilo dalla timeline per inserirla.'
    } else {
      error.value = cause instanceof ApiError ? cause.message : 'Non riesco a caricare la foto.'
    }
  }
}

function preload(src: string): void {
  const image = new Image()

  image.decoding = 'async'
  image.src = src
}

/** Con lo zoom arriva la versione principale; finché non è pronta resta la media. */
function loadFullIfZoomed(): void {
  const current = photo.value

  if (!current?.image_url || fullSrc.value || transform.value.scale < 1.05) {
    return
  }

  const url = current.image_url
  const image = new Image()

  image.src = url
  image
    .decode()
    .then(() => {
      if (photo.value?.id === current.id) {
        fullSrc.value = url
      }
    })
    .catch(() => undefined)
}

async function onImageError(): Promise<void> {
  if (!photo.value || !canRetrySignedUrl(lastRetryAt.value)) {
    return
  }

  lastRetryAt.value = Date.now()

  const fresh = await diary.value.photo(photo.value.id).catch(() => null)

  if (fresh && props.id === fresh.id) {
    photo.value = fresh
    fullSrc.value = null
  }
}

function go(direction: 'prev' | 'next'): void {
  const link = neighbors.value[direction]

  if (!link) {
    snapBack()

    return
  }

  const width = stage.value?.clientWidth ?? window.innerWidth

  const finish = () => {
    placeholder.value = {
      src: link.medium_url ?? link.thumbnail_url,
      data: link.data,
      size: sizeOf(link),
    }
    photo.value = null
    animating.value = false
    dragX.value = 0
    void router.replace(diary.value.to.photo(link.id))
  }

  if (reducedMotion) {
    finish()

    return
  }

  animating.value = true
  dragX.value = direction === 'prev' ? width : -width
  window.setTimeout(finish, 200)
}

function snapBack(): void {
  animating.value = !reducedMotion
  dragX.value = 0
}

function close(): void {
  const backdrop = reader.backdrop
  const state = window.history.state as { back?: string | null } | null

  // Si è arrivati dalla vista sotto: indietro, così si ritrova lo scroll.
  if (backdrop && state?.back === backdrop) {
    router.back()

    return
  }

  void router.replace(backdrop ?? diary.value.to.timeline())
}

async function remove(): Promise<void> {
  if (!photo.value) {
    return
  }

  deleting.value = true

  try {
    await diary.value.remove(photo.value.id)
    toasts.success('Foto eliminata.')
    confirmingDelete.value = false
    close()
  } catch (cause) {
    toasts.error(cause instanceof ApiError ? cause.message : 'Non riesco a eliminare la foto.')
  } finally {
    deleting.value = false
  }
}

function startSlideshow(options: { scope: SlideshowScope; speed: SlideshowSpeed; loop: boolean }): void {
  slideshowPanel.value = false

  if (shown.value) {
    slideshow.value = { filters: scopeFilters(options.scope, shown.value.data), speed: options.speed, loop: options.loop }
  }
}

/** Chiuso lo slideshow, il lettore resta sull'ultima foto mostrata. */
function stopSlideshow(lastId: string | null): void {
  slideshow.value = null

  if (lastId && lastId !== props.id) {
    photo.value = null
    placeholder.value = null
    void router.replace(diary.value.to.photo(lastId))
  }
}

/* ---- Gesti ---- */

interface Gesture {
  kind: 'undecided' | 'swipe' | 'pan' | 'pinch'
  start: Point
  startTime: number
  startTransform: Transform
  startDistance?: number
  startMid?: Point
}

const pointers = new Map<number, Point>()
let gesture: Gesture | null = null
let lastTap: { point: Point; time: number } | null = null
let tapTimer: number | undefined

function viewport(): Size {
  return { width: stage.value?.clientWidth ?? window.innerWidth, height: stage.value?.clientHeight ?? window.innerHeight }
}

function content(): Size {
  return shown.value?.size ? containedSize(shown.value.size, viewport()) : viewport()
}

/** Da coordinate dello schermo a coordinate rispetto al centro della foto. */
function relative(point: Point): Point {
  const rect = stage.value?.getBoundingClientRect()

  return rect
    ? { x: point.x - rect.left - rect.width / 2, y: point.y - rect.top - rect.height / 2 }
    : point
}

function setTransform(next: Transform): void {
  transform.value = clampTransform(next, content(), viewport())
  loadFullIfZoomed()
}

function onPointerDown(event: PointerEvent): void {
  if (event.pointerType === 'mouse' && event.button !== 0) {
    return
  }

  stage.value?.setPointerCapture(event.pointerId)
  pointers.set(event.pointerId, { x: event.clientX, y: event.clientY })
  animating.value = false

  if (pointers.size === 2) {
    const [a, b] = [...pointers.values()] as [Point, Point]

    dragX.value = 0
    gesture = {
      kind: 'pinch',
      start: midpoint(a, b),
      startTime: performance.now(),
      startTransform: { ...transform.value },
      startDistance: Math.max(1, distance(a, b)),
      startMid: relative(midpoint(a, b)),
    }

    return
  }

  if (pointers.size === 1) {
    gesture = {
      kind: transform.value.scale > 1.01 ? 'pan' : 'undecided',
      start: { x: event.clientX, y: event.clientY },
      startTime: performance.now(),
      startTransform: { ...transform.value },
    }
  }
}

function onPointerMove(event: PointerEvent): void {
  if (!gesture || !pointers.has(event.pointerId)) {
    return
  }

  pointers.set(event.pointerId, { x: event.clientX, y: event.clientY })

  if (gesture.kind === 'pinch') {
    if (pointers.size < 2) {
      return
    }

    const [a, b] = [...pointers.values()] as [Point, Point]
    const mid = relative(midpoint(a, b))
    const zoomed = zoomAround(
      gesture.startTransform,
      (gesture.startTransform.scale * distance(a, b)) / gesture.startDistance!,
      gesture.startMid!,
    )

    setTransform({ ...zoomed, x: zoomed.x + mid.x - gesture.startMid!.x, y: zoomed.y + mid.y - gesture.startMid!.y })

    return
  }

  const dx = event.clientX - gesture.start.x
  const dy = event.clientY - gesture.start.y

  if (gesture.kind === 'undecided' && Math.hypot(dx, dy) > 8) {
    gesture.kind = 'swipe'
  }

  if (gesture.kind === 'swipe') {
    // Oltre la prima o l'ultima foto il trascinamento fa resistenza.
    const hasNeighbor = dx > 0 ? neighbors.value.prev : neighbors.value.next
    dragX.value = hasNeighbor ? dx : dx * 0.25
  } else if (gesture.kind === 'pan') {
    setTransform({ ...gesture.startTransform, x: gesture.startTransform.x + dx, y: gesture.startTransform.y + dy })
  }
}

function onPointerUp(event: PointerEvent): void {
  const current = gesture
  const end = { x: event.clientX, y: event.clientY }

  pointers.delete(event.pointerId)

  if (!current) {
    return
  }

  if (current.kind === 'pinch') {
    const rest = [...pointers.values()][0]

    // Da due dita a una: si continua a spostare la foto ingrandita.
    gesture = rest
      ? { kind: 'pan', start: rest, startTime: performance.now(), startTransform: { ...transform.value } }
      : null

    if (!rest && transform.value.scale < 1.05) {
      animating.value = !reducedMotion
      transform.value = { ...IDENTITY }
    }

    return
  }

  gesture = null

  const duration = performance.now() - current.startTime

  if (isTap(current.start, end, duration)) {
    onTap(end)

    return
  }

  if (current.kind === 'swipe') {
    const outcome = swipeOutcome(end.x - current.start.x, end.y - current.start.y, duration, viewport().width)

    if (outcome) {
      go(outcome)
    } else {
      snapBack()
    }
  }
}

function onPointerCancel(event: PointerEvent): void {
  pointers.delete(event.pointerId)
  gesture = null
  snapBack()
}

/** Un tocco mostra o nasconde data e didascalia; due tocchi ingrandiscono. */
function onTap(point: Point): void {
  const now = performance.now()

  if (lastTap && now - lastTap.time < 300 && distance(lastTap.point, point) < 30) {
    window.clearTimeout(tapTimer)
    lastTap = null
    toggleZoom(point)

    return
  }

  lastTap = { point, time: now }
  tapTimer = window.setTimeout(() => {
    uiVisible.value = !uiVisible.value
    lastTap = null
  }, 260)
}

function toggleZoom(point: Point): void {
  animating.value = !reducedMotion
  setTransform(transform.value.scale > 1.01 ? { ...IDENTITY } : zoomAround(IDENTITY, DOUBLE_TAP_SCALE, relative(point)))
}

function onWheel(event: WheelEvent): void {
  animating.value = false
  setTransform(
    zoomAround(transform.value, transform.value.scale * Math.exp(-event.deltaY * 0.002), relative({ x: event.clientX, y: event.clientY })),
  )
}

function onKeydown(event: KeyboardEvent): void {
  if (confirmingDelete.value || slideshowPanel.value || slideshow.value) {
    return
  }

  if (event.key === 'ArrowLeft') {
    go('prev')
  } else if (event.key === 'ArrowRight') {
    go('next')
  } else if (event.key === 'Escape') {
    close()
  }
}

onMounted(() => {
  // Lo scroll della vista sotto resta fermo dov'è.
  document.documentElement.classList.add('overflow-hidden')
  window.addEventListener('keydown', onKeydown)
  closeButton.value?.focus()

  if (diary.value.kind === 'public') {
    void publicDiary.ensureProfile()
  }
})

onBeforeUnmount(() => {
  document.documentElement.classList.remove('overflow-hidden')
  window.removeEventListener('keydown', onKeydown)
  window.clearTimeout(tapTimer)
})

watch(() => props.id, load, { immediate: true })
</script>

<template>
  <div class="fixed inset-0 z-40 bg-black text-white" role="dialog" aria-modal="true" :aria-label="altText">
    <div
      ref="stage"
      class="absolute inset-0 touch-none overflow-hidden select-none"
      @pointerdown="onPointerDown"
      @pointermove="onPointerMove"
      @pointerup="onPointerUp"
      @pointercancel="onPointerCancel"
      @wheel.prevent="onWheel"
    >
      <div
        v-if="neighbors.prev"
        class="absolute inset-0 flex items-center justify-center"
        :class="{ 'transition-transform duration-200': animating }"
        :style="slideStyle(-1)"
      >
        <img :src="neighbors.prev.medium_url ?? neighbors.prev.thumbnail_url" alt="" class="max-h-full max-w-full object-contain" draggable="false" />
      </div>

      <div
        class="absolute inset-0 flex items-center justify-center"
        :class="{ 'transition-transform duration-200': animating }"
        :style="slideStyle(0)"
      >
        <img
          v-if="shown"
          :src="shown.src"
          :alt="altText"
          class="max-h-full max-w-full object-contain will-change-transform"
          :class="{ 'transition-transform duration-200': animating }"
          :style="imageStyle"
          draggable="false"
          @error="onImageError"
        />
        <p v-else-if="error" class="max-w-sm px-6 text-center text-sm">
          {{ error }}
          <RouterLink :to="diary.to.timeline()" class="mt-3 block font-bold underline">Vai alla timeline</RouterLink>
        </p>
      </div>

      <div
        v-if="neighbors.next"
        class="absolute inset-0 flex items-center justify-center"
        :class="{ 'transition-transform duration-200': animating }"
        :style="slideStyle(1)"
      >
        <img :src="neighbors.next.medium_url ?? neighbors.next.thumbnail_url" alt="" class="max-h-full max-w-full object-contain" draggable="false" />
      </div>
    </div>

    <!-- Data, età e didascalia: un tocco sulla foto li nasconde o li mostra. -->
    <div
      class="pointer-events-none absolute inset-0 flex flex-col justify-between transition-opacity duration-200"
      :class="uiVisible ? 'opacity-100' : 'opacity-0'"
    >
      <div class="flex items-start gap-2 bg-linear-to-b from-black/70 to-transparent px-3 pt-[max(0.75rem,env(safe-area-inset-top))] pb-8">
        <button
          ref="closeButton"
          type="button"
          class="pointer-events-auto flex size-10 shrink-0 items-center justify-center rounded-full bg-black/40"
          aria-label="Chiudi"
          @click="close"
        >
          <AppIcon name="close" class="size-5" />
        </button>

        <div v-if="shown" class="min-w-0 flex-1 pt-1">
          <p class="flex flex-wrap items-center gap-2 font-bold">
            <time :datetime="shown.data">{{ formatLongDate(shown.data) }}</time>
            <span
              v-if="photo?.data_speciale"
              class="flex items-center gap-1 rounded-full bg-brick px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide"
            >
              <AppIcon name="star" filled class="size-3" />
              Speciale
            </span>
            <span v-if="photo?.is_draft" class="rounded bg-azure px-1.5 py-0.5 text-[11px] font-bold uppercase tracking-wide">
              bozza
            </span>
          </p>
          <p v-if="age" class="text-sm text-white/80">{{ age }}</p>
        </div>

        <button
          v-if="shown"
          type="button"
          class="pointer-events-auto flex size-10 shrink-0 items-center justify-center rounded-full bg-black/40"
          aria-label="Slideshow"
          @click="slideshowPanel = true"
        >
          <AppIcon name="play" filled class="size-4" />
        </button>

        <template v-if="photo && diary.canUpload">
          <RouterLink
            :to="{ name: 'photo-edit', params: { id: photo.id } }"
            class="pointer-events-auto flex size-10 shrink-0 items-center justify-center rounded-full bg-black/40"
            aria-label="Modifica"
          >
            <AppIcon name="pencil" class="size-4" />
          </RouterLink>
          <button
            type="button"
            class="pointer-events-auto flex size-10 shrink-0 items-center justify-center rounded-full bg-black/40"
            aria-label="Elimina"
            @click="confirmingDelete = true"
          >
            <AppIcon name="trash" class="size-4" />
          </button>
        </template>
      </div>

      <div class="flex items-end gap-2 bg-linear-to-t from-black/70 to-transparent px-3 pt-10 pb-[max(1rem,env(safe-area-inset-bottom))]">
        <button
          type="button"
          class="pointer-events-auto hidden size-10 shrink-0 items-center justify-center rounded-full bg-black/40 disabled:opacity-30 md:flex"
          aria-label="Foto precedente"
          :disabled="!neighbors.prev"
          @click="go('prev')"
        >
          <AppIcon name="back" class="size-5" />
        </button>

        <div
          v-if="photo?.didascalia"
          class="caption-html pointer-events-auto mx-auto max-h-32 max-w-2xl flex-1 overflow-y-auto text-sm leading-relaxed text-white/90"
          v-html="photo.didascalia"
        />
        <div v-else class="flex-1" />

        <button
          type="button"
          class="pointer-events-auto hidden size-10 shrink-0 items-center justify-center rounded-full bg-black/40 disabled:opacity-30 md:flex"
          aria-label="Foto successiva"
          :disabled="!neighbors.next"
          @click="go('next')"
        >
          <AppIcon name="forward" class="size-5" />
        </button>
      </div>
    </div>
  </div>

  <SlideshowPanel v-if="slideshowPanel" @start="startSlideshow" @cancel="slideshowPanel = false" />
  <SlideshowPlayer
    v-if="slideshow"
    :diary="diary"
    :filters="slideshow.filters"
    :speed="slideshow.speed"
    :loop="slideshow.loop"
    @close="stopSlideshow"
  />

  <ConfirmDialog
    v-if="confirmingDelete"
    title="Eliminare la foto?"
    message="La foto e la sua immagine verranno cancellate definitivamente."
    confirm-label="Elimina"
    :busy="deleting"
    @confirm="remove"
    @cancel="confirmingDelete = false"
  />
</template>
