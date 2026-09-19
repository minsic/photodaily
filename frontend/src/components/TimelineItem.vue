<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink } from 'vue-router'

import type { Photo } from '@/api/types'
import AppIcon from '@/components/AppIcon.vue'
import { usePhotosStore } from '@/stores/photos'
import { formatDayAndMonth } from '@/utils/date'

const props = defineProps<{ photo: Photo }>()

const photos = usePhotosStore()
const loaded = ref(false)
const retried = ref(false)

const ratio = computed(() =>
  props.photo.width && props.photo.height ? `${props.photo.width} / ${props.photo.height}` : '4 / 5',
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

    <RouterLink :to="{ name: 'photo', params: { id: photo.id } }" class="block pb-8">
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
          :alt="photo.didascalia ?? `Foto del ${photo.data}`"
          :width="photo.width ?? undefined"
          :height="photo.height ?? undefined"
          :style="{ aspectRatio: ratio }"
          class="w-full object-cover fade-in-img"
          :data-loaded="loaded"
          loading="lazy"
          decoding="async"
          @load="loaded = true"
          @error="onError"
        />
      </figure>

      <p v-if="photo.didascalia" class="mt-2 whitespace-pre-line text-sm leading-relaxed text-muted">
        {{ photo.didascalia }}
      </p>
    </RouterLink>
  </li>
</template>
