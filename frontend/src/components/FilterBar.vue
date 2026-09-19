<script setup lang="ts">
import AppIcon from '@/components/AppIcon.vue'
import { usePhotosStore } from '@/stores/photos'

const photos = usePhotosStore()

function onYearChange(event: Event): void {
  photos.setAnno(Number((event.target as HTMLSelectElement).value))
  void photos.load()
}

function toggleSpeciali(): void {
  photos.toggleSpeciali()
  void photos.load()
}

function toggleBozze(): void {
  photos.setStato(photos.stato === 'bozze' ? 'pubblicate' : 'bozze')
  void photos.load()
}
</script>

<template>
  <div class="flex flex-wrap items-center gap-2 py-4">
    <label class="sr-only" for="anno">Anno</label>
    <select
      id="anno"
      class="rounded-full border-2 border-line bg-transparent py-1.5 pl-3 pr-8 text-sm font-bold text-ink disabled:opacity-50"
      :value="photos.anno ?? ''"
      :disabled="photos.stato === 'bozze' || photos.years.length === 0"
      @change="onYearChange"
    >
      <option v-for="year in photos.years" :key="year.anno" :value="year.anno">
        {{ year.anno }} · {{ year.foto }} foto
      </option>
    </select>

    <button
      type="button"
      class="flex items-center gap-1.5 rounded-full border-2 px-3 py-1.5 text-sm font-bold transition"
      :class="
        photos.soloSpeciali
          ? 'border-brick bg-brick text-white'
          : 'border-line text-muted hover:text-ink'
      "
      :aria-pressed="photos.soloSpeciali"
      @click="toggleSpeciali"
    >
      <AppIcon name="star" :filled="photos.soloSpeciali" class="size-4" />
      Speciali
    </button>

    <button
      type="button"
      class="rounded-full border-2 px-3 py-1.5 text-sm font-bold transition"
      :class="
        photos.stato === 'bozze'
          ? 'border-azure bg-azure text-white'
          : 'border-line text-muted hover:text-ink'
      "
      :aria-pressed="photos.stato === 'bozze'"
      @click="toggleBozze"
    >
      Bozze
    </button>
  </div>
</template>
