<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'

import { photos as photosApi } from '@/api'
import { ApiError, type ValidationErrors } from '@/api/client'
import type { Photo, PhotoPayload } from '@/api/types'
import AppHeader from '@/components/AppHeader.vue'
import AppIcon from '@/components/AppIcon.vue'
import AppSpinner from '@/components/AppSpinner.vue'
import PhotoForm from '@/components/PhotoForm.vue'
import { usePhotosStore } from '@/stores/photos'
import { useToastsStore } from '@/stores/toasts'

const props = defineProps<{ id: number }>()

const store = usePhotosStore()
const toasts = useToastsStore()
const router = useRouter()

const photo = ref<Photo | null>(null)
const loadError = ref<string | null>(null)
const busy = ref(false)
const errors = ref<ValidationErrors>({})

onMounted(async () => {
  try {
    photo.value = await photosApi.get(props.id)
  } catch (cause) {
    loadError.value = cause instanceof ApiError ? cause.message : 'Non riesco a caricare la foto.'
  }
})

async function submit(payload: PhotoPayload): Promise<void> {
  busy.value = true
  errors.value = {}

  try {
    const updated = await photosApi.update(props.id, payload)

    store.upsert(updated)
    await store.loadYears().catch(() => undefined)
    toasts.success('Modifiche salvate.')
    await router.replace({ name: 'photo', params: { id: props.id } })
  } catch (cause) {
    if (cause instanceof ApiError) {
      errors.value = cause.errors
      toasts.error(cause.message)
    } else {
      toasts.error('Non riesco a salvare le modifiche.')
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
        :to="{ name: 'photo', params: { id } }"
        class="flex items-center gap-1.5 rounded-lg py-1 font-bold text-ink"
      >
        <AppIcon name="back" class="size-5" />
        Foto
      </RouterLink>
    </template>
  </AppHeader>

  <main class="mx-auto max-w-lg px-4 pb-16">
    <h1 class="mb-5 mt-6 text-2xl font-bold tracking-tight">Modifica foto</h1>

    <AppSpinner v-if="!photo && !loadError" />

    <p v-else-if="loadError" class="rounded-xl bg-brick/10 px-4 py-3 text-sm text-brick">
      {{ loadError }}
    </p>

    <PhotoForm
      v-else
      :photo="photo"
      :busy="busy"
      :errors="errors"
      submit-label="Salva"
      @submit="submit"
    />
  </main>
</template>
