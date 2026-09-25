<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref } from 'vue'

import type { ValidationErrors } from '@/api/client'
import type { Photo, PhotoPayload } from '@/api/types'
import AppIcon from '@/components/AppIcon.vue'
import CaptionTextarea from '@/components/CaptionTextarea.vue'
import { todayIso } from '@/utils/date'

const props = withDefaults(
  defineProps<{
    photo?: Photo | null
    /** Sul caricamento si sceglie anche il file; in modifica l'immagine non si tocca. */
    withImage?: boolean
    busy?: boolean
    errors?: ValidationErrors
    submitLabel?: string
  }>(),
  { photo: null, withImage: false, busy: false, errors: () => ({}), submitLabel: 'Salva' },
)

const emit = defineEmits<{ submit: [payload: PhotoPayload, image: File | null] }>()

const form = reactive({
  data: props.photo?.data ?? todayIso(),
  didascalia: props.photo?.didascalia ?? '',
  data_speciale: props.photo?.data_speciale ?? false,
  is_draft: props.photo?.is_draft ?? false,
})

const image = ref<File | null>(null)
const preview = ref<string | null>(null)
const missingImage = ref(false)

const fileSize = computed(() => {
  if (!image.value) {
    return null
  }

  const kb = image.value.size / 1024

  return kb < 1024 ? `${Math.round(kb)} KB` : `${(kb / 1024).toFixed(1)} MB`
})

function onFileChange(event: Event): void {
  const file = (event.target as HTMLInputElement).files?.[0] ?? null

  releasePreview()
  image.value = file
  preview.value = file ? URL.createObjectURL(file) : null
  missingImage.value = false
}

function onSubmit(): void {
  if (props.withImage && !image.value) {
    missingImage.value = true

    return
  }

  emit(
    'submit',
    {
      data: form.data,
      didascalia: form.didascalia.trim() === '' ? null : form.didascalia.trim(),
      data_speciale: form.data_speciale,
      is_draft: form.is_draft,
    },
    image.value,
  )
}

function releasePreview(): void {
  if (preview.value) {
    URL.revokeObjectURL(preview.value)
    preview.value = null
  }
}

onBeforeUnmount(releasePreview)

function fieldError(name: string): string | undefined {
  return props.errors?.[name]?.[0]
}
</script>

<template>
  <form class="space-y-5" novalidate @submit.prevent="onSubmit">
    <div v-if="withImage">
      <label
        class="flex aspect-4/5 w-full cursor-pointer flex-col items-center justify-center gap-2 overflow-hidden rounded-2xl border-2 border-dashed border-line bg-card text-muted transition hover:border-brick hover:text-brick"
        :class="{ 'border-brick': missingImage }"
      >
        <img v-if="preview" :src="preview" alt="Anteprima" class="size-full object-cover" />
        <template v-else>
          <AppIcon name="camera" class="size-8" />
          <span class="text-sm font-bold">Scegli una foto</span>
          <span class="text-xs">JPG, PNG o WebP</span>
        </template>
        <input
          type="file"
          accept="image/jpeg,image/png,image/webp"
          class="sr-only"
          @change="onFileChange"
        />
      </label>

      <p v-if="fileSize" class="mt-2 text-xs text-muted">{{ image?.name }} · {{ fileSize }}</p>
      <p v-if="missingImage" class="mt-2 text-sm text-brick">Serve una foto da caricare.</p>
      <p v-if="fieldError('image')" class="mt-2 text-sm text-brick">{{ fieldError('image') }}</p>
    </div>

    <div>
      <label for="data" class="mb-1 block text-sm font-bold">Data</label>
      <input
        id="data"
        v-model="form.data"
        type="date"
        required
        class="w-full rounded-xl border-2 border-line bg-card px-3 py-2 text-ink"
      />
      <p v-if="fieldError('data')" class="mt-1 text-sm text-brick">{{ fieldError('data') }}</p>
    </div>

    <div>
      <span id="didascalia-label" class="mb-1 block text-sm font-bold">Didascalia</span>
      <CaptionTextarea
        v-model="form.didascalia"
        placeholder="Cosa è successo oggi?"
        aria-labelledby="didascalia-label"
      />
      <p v-if="fieldError('didascalia')" class="mt-1 text-sm text-brick">
        {{ fieldError('didascalia') }}
      </p>
    </div>

    <div class="flex flex-wrap gap-2">
      <button
        type="button"
        class="flex items-center gap-1.5 rounded-full border-2 px-3 py-1.5 text-sm font-bold transition"
        :class="
          form.data_speciale
            ? 'border-brick bg-brick text-white'
            : 'border-line text-muted hover:text-ink'
        "
        :aria-pressed="form.data_speciale"
        @click="form.data_speciale = !form.data_speciale"
      >
        <AppIcon name="star" :filled="form.data_speciale" class="size-4" />
        Data speciale
      </button>

      <button
        type="button"
        class="rounded-full border-2 px-3 py-1.5 text-sm font-bold transition"
        :class="
          form.is_draft ? 'border-azure bg-azure text-white' : 'border-line text-muted hover:text-ink'
        "
        :aria-pressed="form.is_draft"
        @click="form.is_draft = !form.is_draft"
      >
        Bozza
      </button>
    </div>

    <button
      type="submit"
      class="w-full rounded-xl bg-brick px-4 py-3 font-bold text-white transition disabled:opacity-60"
      :disabled="busy"
    >
      {{ busy ? 'Attendi…' : submitLabel }}
    </button>
  </form>
</template>
