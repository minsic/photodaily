<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'

import { photos as photosApi } from '@/api'
import { ApiError } from '@/api/client'
import type { Photo } from '@/api/types'
import AppHeader from '@/components/AppHeader.vue'
import AppIcon from '@/components/AppIcon.vue'
import PhotoAge from '@/components/PhotoAge.vue'
import AppSpinner from '@/components/AppSpinner.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { useAuthStore } from '@/stores/auth'
import { usePhotosStore } from '@/stores/photos'
import { useToastsStore } from '@/stores/toasts'
import { captionToPlainText } from '@/utils/caption'
import { formatLongDate, formatShortDate } from '@/utils/date'
import { canRetrySignedUrl } from '@/utils/signedUrls'

const props = defineProps<{ id: string }>()

const store = usePhotosStore()
const toasts = useToastsStore()
const router = useRouter()

const auth = useAuthStore()
const photo = ref<Photo | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)
const imageLoaded = ref(false)
/** Ultimo rinnovo degli URL chiesto per questa foto. */
const lastRetryAt = ref<number | null>(null)
const confirmingDelete = ref(false)
const deleting = ref(false)

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

async function load(id: string): Promise<void> {
  // La foto già in elenco si mostra subito; la richiesta serve per
  // precedente/successiva e per un URL firmato fresco.
  const cached = store.find(id)

  photo.value = cached ? { ...cached } : null
  loading.value = !cached
  error.value = null
  imageLoaded.value = false
  lastRetryAt.value = null

  try {
    const fresh = await photosApi.get(id)

    photo.value = fresh
    store.upsert(fresh)
  } catch (cause) {
    if (!cached) {
      error.value = cause instanceof ApiError ? cause.message : 'Non riesco a caricare la foto.'
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

  const fresh = await photosApi.get(photo.value.id).catch(() => null)

  if (fresh) {
    photo.value = fresh
  }
}

async function remove(): Promise<void> {
  if (!photo.value) {
    return
  }

  deleting.value = true

  try {
    await photosApi.remove(photo.value.id)
    store.drop(photo.value.id)
    await store.loadYears().catch(() => undefined)
    toasts.success('Foto eliminata.')
    await router.replace({ name: 'timeline' })
  } catch (cause) {
    toasts.error(cause instanceof ApiError ? cause.message : 'Non riesco a eliminare la foto.')
  } finally {
    deleting.value = false
    confirmingDelete.value = false
  }
}

function goTo(id: string | undefined): void {
  if (id) {
    void router.replace({ name: 'photo', params: { id } })
  }
}

function onKeydown(event: KeyboardEvent): void {
  if (confirmingDelete.value) {
    return
  }

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
        :to="{ name: 'timeline' }"
        class="flex items-center gap-1.5 rounded-lg py-1 font-bold text-ink"
      >
        <AppIcon name="back" class="size-5" />
        Timeline
      </RouterLink>
    </template>

    <template #right>
      <RouterLink
        v-if="photo"
        :to="{ name: 'photo-edit', params: { id: photo.id } }"
        class="flex size-9 items-center justify-center rounded-full border-2 border-line text-muted hover:text-ink"
        aria-label="Modifica"
      >
        <AppIcon name="pencil" class="size-4" />
      </RouterLink>
      <button
        v-if="photo"
        type="button"
        class="flex size-9 items-center justify-center rounded-full border-2 border-line text-muted hover:border-brick hover:text-brick"
        aria-label="Elimina"
        @click="confirmingDelete = true"
      >
        <AppIcon name="trash" class="size-4" />
      </button>
    </template>
  </AppHeader>

  <main class="mx-auto max-w-3xl px-4 pb-16">
    <AppSpinner v-if="loading" />

    <p v-else-if="error" class="mt-6 rounded-xl bg-brick/10 px-4 py-3 text-sm text-brick">
      {{ error }}
    </p>

    <article v-else-if="photo" @touchstart.passive="onTouchStart" @touchend.passive="onTouchEnd">
      <!-- La miniatura fa da segnaposto sfocato finché non arriva l'originale. -->
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
        <span
          v-if="photo.is_draft"
          class="rounded bg-azure px-1.5 py-0.5 text-[11px] font-bold uppercase tracking-wide text-white"
        >
          bozza
        </span>
      </div>
      <PhotoAge :date="photo.data" :protagonist="auth.family?.protagonist" />

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
