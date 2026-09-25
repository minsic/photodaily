<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink, type RouteLocationRaw } from 'vue-router'

import type { Photo } from '@/api/types'
import AppIcon from '@/components/AppIcon.vue'
import { usePhotosStore } from '@/stores/photos'
import { captionToPlainText, captionWithoutLinks } from '@/utils/caption'
import { formatDayAndMonth } from '@/utils/date'

const props = defineProps<{
  photo: Photo
  /** Dove porta la foto: la timeline pubblica usa le proprie rotte. */
  to?: RouteLocationRaw
}>()

const target = computed<RouteLocationRaw>(
  () => props.to ?? { name: 'photo', params: { id: props.photo.id } },
)

const photos = usePhotosStore()
const loaded = ref(false)
const retried = ref(false)

/** Lato lungo della miniatura generata dal server (ThumbnailMaker::MAX_SIDE). */
const THUMBNAIL_SIDE = 400

/**
 * Larghezza della foto nella colonna: 700px da 768px di viewport in su
 * (max-w-3xl meno padding e rientro della linea del tempo), altrimenti
 * lo schermo meno quei margini.
 */
const SIZES = '(min-width: 768px) 700px, (min-width: 640px) calc(100vw - 68px), calc(100vw - 60px)'

/** Dimensioni da mostrare: quelle della versione media sono già ruotate secondo l'EXIF. */
const display = computed(() => {
  const { medium_width, medium_height, width, height } = props.photo

  if (medium_width && medium_height) {
    return { width: medium_width, height: medium_height }
  }

  return width && height ? { width, height } : null
})

const ratio = computed(() =>
  display.value ? `${display.value.width} / ${display.value.height}` : '4 / 5',
)

/**
 * Miniatura e versione media come candidati: il browser sceglie in base a
 * `sizes` e alla densità dello schermo. Senza versione media resta la sola
 * miniatura, mai l'originale.
 */
const srcset = computed(() => {
  const { medium_url, medium_width, medium_height, thumbnail_url } = props.photo

  if (!medium_url || !medium_width || !medium_height) {
    return undefined
  }

  const thumbnailWidth = Math.round(
    medium_width * Math.min(1, THUMBNAIL_SIDE / Math.max(medium_width, medium_height)),
  )

  return `${thumbnail_url} ${thumbnailWidth}w, ${medium_url} ${medium_width}w`
})

const captionPreview = computed(() => captionWithoutLinks(props.photo.didascalia))

const altText = computed(
  () => captionToPlainText(props.photo.didascalia) || `Foto del ${props.photo.data}`,
)

/** Se l'URL firmato è scaduto mentre la pagina era aperta, se ne chiede uno nuovo. */
async function onError(): Promise<void> {
  if (retried.value) {
    return
  }

  retried.value = true
  await photos.refreshImage(props.photo.id)
}
</script>

<template>
  <li class="relative pl-7 sm:pl-9">
    <span
      class="absolute left-0 top-1.5 size-3 rounded-full border-2"
      :class="photo.data_speciale ? 'border-brick bg-brick' : 'border-line bg-paper'"
      aria-hidden="true"
    />
    <span class="absolute bottom-0 left-[5px] top-6 w-0.5 bg-line" aria-hidden="true" />

    <RouterLink :to="target" class="block pb-8">
      <div class="flex items-center gap-2">
        <time :datetime="photo.data" class="text-sm font-bold uppercase tracking-wide text-muted">
          {{ formatDayAndMonth(photo.data) }}
        </time>
        <AppIcon v-if="photo.data_speciale" name="star" filled class="size-4 text-brick" />
        <span
          v-if="photo.is_draft"
          class="rounded bg-azure px-1.5 py-0.5 text-[11px] font-bold uppercase tracking-wide text-white"
        >
          bozza
        </span>
      </div>

      <figure class="mt-2 overflow-hidden rounded-2xl bg-card card-shadow">
        <img
          :src="photo.thumbnail_url"
          :srcset="srcset"
          :sizes="srcset ? SIZES : undefined"
          :alt="altText"
          :width="display?.width"
          :height="display?.height"
          :style="{ aspectRatio: ratio }"
          class="w-full object-cover fade-in-img"
          :data-loaded="loaded"
          loading="lazy"
          decoding="async"
          @load="loaded = true"
          @error="onError"
        />
      </figure>

      <div
        v-if="photo.didascalia"
        class="caption-html mt-2 text-sm leading-relaxed text-muted"
        v-html="captionPreview"
      />
    </RouterLink>
  </li>
</template>
