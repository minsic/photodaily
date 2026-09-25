<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import { photos as photosApi } from '@/api'
import { ApiError, type ValidationErrors } from '@/api/client'
import type { PhotoPayload } from '@/api/types'
import AppHeader from '@/components/AppHeader.vue'
import AppIcon from '@/components/AppIcon.vue'
import PhotoForm from '@/components/PhotoForm.vue'
import { usePhotosStore } from '@/stores/photos'
import { useIncomingFilesStore } from '@/stores/incomingFiles'
import { useToastsStore } from '@/stores/toasts'
import { isIsoDate } from '@/utils/date'
import { takeSharedFiles } from '@/utils/sharedFiles'

const store = usePhotosStore()
const toasts = useToastsStore()
const router = useRouter()
const route = useRoute()

/** Dal calendario si arriva con ?data=YYYY-MM-DD: il giorno vuoto che si vuole riempire. */
const initialDate = isIsoDate(route.query.data) ? route.query.data : undefined

const incoming = useIncomingFilesStore()
const initialFile = ref<File | null>(null)
const ready = ref(false)

/**
 * Foto già scelte: dal pulsante "Carica" (store) o condivise dalla galleria
 * (?condiviso=1, parcheggiate dal service worker; restano lì anche se prima
 * serve il login). Una sola: form normale. Più d'una: recupero dei giorni.
 */
onMounted(async () => {
  const files = [...incoming.take(), ...(route.query.condiviso === '1' ? await takeSharedFiles() : [])]

  if (files.length > 1) {
    incoming.put(files)
    await router.replace({ name: 'recover' })

    return
  }

  initialFile.value = files[0] ?? null
  ready.value = true
})

const busy = ref(false)
const errors = ref<ValidationErrors>({})

async function submit(payload: PhotoPayload, image: File | null): Promise<void> {
  if (!image) {
    return
  }

  busy.value = true
  errors.value = {}

  try {
    const photo = await photosApi.create(image, payload)

    store.upsert(photo)
    await store.loadYears().catch(() => undefined)
    toasts.success('Foto caricata.')
    await router.replace({ name: 'photo', params: { id: photo.id } })
  } catch (cause) {
    if (cause instanceof ApiError) {
      errors.value = cause.errors
      toasts.error(cause.message)
    } else {
      toasts.error('Non riesco a caricare la foto.')
    }
  } finally {
    busy.value = false
  }
}
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

  <main class="mx-auto max-w-lg px-4 pb-16">
    <h1 class="mb-5 mt-6 text-2xl font-bold tracking-tight">Nuova foto</h1>

    <PhotoForm
      v-if="ready"
      with-image
      :initial-date="initialDate"
      :initial-file="initialFile"
      :busy="busy"
      :errors="errors"
      submit-label="Carica"
      @submit="submit"
    />
  </main>
</template>
