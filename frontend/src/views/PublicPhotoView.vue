<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'

import { ApiError } from '@/api/client'
import type { Photo } from '@/api/types'
import AppHeader from '@/components/AppHeader.vue'
import AppIcon from '@/components/AppIcon.vue'
import AppSpinner from '@/components/AppSpinner.vue'
import { useNoIndex } from '@/composables/useNoIndex'
import { usePublicDiaryStore } from '@/stores/publicDiary'
import { captionToPlainText } from '@/utils/caption'
import { formatLongDate, formatShortDate } from '@/utils/date'
import { canRetrySignedUrl } from '@/utils/signedUrls'

/** Dettaglio in sola lettura: nessuna azione di modifica o cancellazione. */
const props = defineProps<{ slug: string; id: number }>()

const diary = usePublicDiaryStore()
const router = useRouter()

useNoIndex()

const photo = ref<Photo | null>(null)
const loading = ref(true)
const unavailable = ref(false)
const imageLoaded = ref(false)
/** Ultimo rinnovo degli URL chiesto per questa foto. */
const lastRetryAt = ref<number | null>(null)

const ratio = computed(() =>
  photo.value?.width && photo.value.height
    ? `${photo.value.width} / ${photo.value.height}`
    : undefined,
)

const altText = computed(() =>
  photo.value
    ? captionToPlainText(photo.value.didascalia) || `Foto del ${photo.value.data}`
    : '',
)

async function load(id: number): Promise<void> {
  if (diary.slug !== props.slug) {
    await diary.open(props.slug)
  }

  const cached = diary.find(id)

  photo.value = cached ? { ...cached } : null
  loading.value = !cached
  unavailable.value = false
  imageLoaded.value = false
  lastRetryAt.value = null

  try {
    photo.value = await diary.photo(id)
  } catch (cause) {
    if (!cached) {
      unavailable.value = cause instanceof ApiError
    }
  } finally {
    loading.value = false
  }
}

async function onImageError(): Promise<void> {
  if (!photo.value || !canRetrySignedUrl(lastRetryAt.value)) {
    return
  }

  lastRetryAt.value = Date.now()

  const fresh = await diary.photo(photo.value.id).catch(() => null)

  if (fresh) {
    photo.value = fresh
  }
}

function goTo(id: number | undefined): void {
  if (id) {
    void router.replace({ name: 'public-photo', params: { slug: props.slug, id } })
  }
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'ArrowLeft') {
    goTo(photo.value?.precedente?.id)
  }

  if (event.key === 'ArrowRight') {
    goTo(photo.value?.successiva?.id)
  }
}

let touchStartX = 0

function onTouchStart(event: TouchEvent): void {
  touchStartX = event.changedTouches[0]?.clientX ?? 0
}

function onTouchEnd(event: TouchEvent): void {
  const delta = (event.changedTouches[0]?.clientX ?? 0) - touchStartX

  if (Math.abs(delta) < 60) {
    return
  }

  goTo(delta > 0 ? photo.value?.precedente?.id : photo.value?.successiva?.id)
}

onMounted(() => window.addEventListener('keydown', onKeydown))
onUnmounted(() => window.removeEventListener('keydown', onKeydown))

watch(() => props.id, load, { immediate: true })
</script>

<template>
  <AppHeader>
    <template #left>
      <RouterLink
        :to="{ name: 'public-timeline', params: { slug } }"
        class="flex items-center gap-1.5 rounded-lg py-1 font-bold text-ink"
      >
        <AppIcon name="back" class="size-5" />
        Diario
      </RouterLink>
    </template>
    <template #right>
      <span class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wide text-muted">
        <AppIcon name="lock" class="size-3.5" />
        sola lettura
      </span>
    </template>
  </AppHeader>

  <main class="mx-auto max-w-3xl px-4 pb-16">
    <AppSpinner v-if="loading" />

    <div v-else-if="unavailable || !photo" class="py-20 text-center">
      <h1 class="text-xl font-bold">Foto non disponibile</h1>
      <p class="mt-2 text-sm text-muted">Questa foto non fa parte del diario pubblico.</p>
      <RouterLink
        :to="{ name: 'public-timeline', params: { slug } }"
        class="mt-4 inline-block rounded-xl bg-brick px-4 py-2.5 font-bold text-white"
      >
        Torna al diario
      </RouterLink>
    </div>

    <article v-else @touchstart.passive="onTouchStart" @touchend.passive="onTouchEnd">
      <figure
        class="mt-4 overflow-hidden rounded-2xl bg-card bg-cover bg-center card-shadow"
        :style="imageLoaded ? undefined : { backgroundImage: `url(${photo.thumbnail_url})` }"
      >
        <img
          :src="photo.image_url ?? photo.thumbnail_url"
          :alt="altText"
          :width="photo.width ?? undefined"
          :height="photo.height ?? undefined"
          :style="{ aspectRatio: ratio }"
          class="max-h-[75dvh] w-full object-contain fade-in-img"
          :data-loaded="imageLoaded"
          decoding="async"
          @load="imageLoaded = true"
          @error="onImageError"
        />
      </figure>

      <div class="mt-4 flex items-center gap-2">
        <time :datetime="photo.data" class="text-lg font-bold">{{ formatLongDate(photo.data) }}</time>
        <AppIcon v-if="photo.data_speciale" name="star" filled class="size-5 text-brick" />
      </div>

      <div
        v-if="photo.didascalia"
        class="caption-html mt-2 leading-relaxed text-muted"
        v-html="photo.didascalia"
      />

      <nav class="mt-8 flex items-center justify-between gap-3" aria-label="Naviga tra le foto">
        <button
          type="button"
          class="flex flex-1 items-center gap-2 rounded-xl border-2 border-line px-3 py-2 text-left text-sm font-bold disabled:opacity-40"
          :disabled="!photo.precedente"
          @click="goTo(photo.precedente?.id)"
        >
          <AppIcon name="back" class="size-4 shrink-0" />
          <span class="truncate">
            {{ photo.precedente ? formatShortDate(photo.precedente.data) : 'Prima foto' }}
          </span>
        </button>

        <button
          type="button"
          class="flex flex-1 items-center justify-end gap-2 rounded-xl border-2 border-line px-3 py-2 text-right text-sm font-bold disabled:opacity-40"
          :disabled="!photo.successiva"
          @click="goTo(photo.successiva?.id)"
        >
          <span class="truncate">
            {{ photo.successiva ? formatShortDate(photo.successiva.data) : 'Ultima foto' }}
          </span>
          <AppIcon name="forward" class="size-4 shrink-0" />
        </button>
      </nav>
    </article>
  </main>
</template>
