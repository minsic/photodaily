<script setup lang="ts">
import { ref } from 'vue'

import AppIcon from '@/components/AppIcon.vue'

import type { SlideshowScope, SlideshowSpeed } from './slideshow'

/** Pannellino del tasto ▶ nel lettore: cosa mostrare e a che velocità. */
const emit = defineEmits<{
  start: [options: { scope: SlideshowScope; speed: SlideshowSpeed; loop: boolean }]
  cancel: []
}>()

const scopes: { value: SlideshowScope; label: string }[] = [
  { value: 'da-qui', label: 'Da questa foto in poi' },
  { value: 'mese', label: 'Questo mese' },
  { value: 'anno', label: "Quest'anno" },
  { value: 'speciali', label: 'Solo Speciali' },
  { value: 'tutto', label: 'Tutto' },
]

const speeds: { value: SlideshowSpeed; label: string; hint: string }[] = [
  { value: 'lento', label: 'Lento', hint: '5 s' },
  { value: 'normale', label: 'Normale', hint: '3 s' },
  { value: 'crescita', label: 'Crescita', hint: '8 foto al secondo' },
]

const scope = ref<SlideshowScope>('da-qui')
const speed = ref<SlideshowSpeed>('normale')
const loop = ref(false)
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/60 p-3 sm:items-center" @click.self="emit('cancel')">
    <form
      class="w-full max-w-sm rounded-2xl bg-card p-5 text-ink card-shadow"
      role="dialog"
      aria-labelledby="slideshow-title"
      @submit.prevent="emit('start', { scope, speed, loop })"
    >
      <h2 id="slideshow-title" class="text-lg font-bold">Slideshow</h2>

      <fieldset class="mt-4">
        <legend class="text-sm font-bold text-muted">Cosa</legend>
        <label v-for="option in scopes" :key="option.value" class="mt-2 flex items-center gap-2">
          <input v-model="scope" type="radio" name="scope" :value="option.value" class="accent-brick" />
          {{ option.label }}
        </label>
      </fieldset>

      <fieldset class="mt-4">
        <legend class="text-sm font-bold text-muted">Velocità</legend>
        <div class="mt-2 grid grid-cols-3 gap-2">
          <label
            v-for="option in speeds"
            :key="option.value"
            class="flex cursor-pointer flex-col items-center rounded-xl border-2 px-2 py-2 text-center text-sm font-bold"
            :class="speed === option.value ? 'border-brick text-brick' : 'border-line'"
          >
            <input v-model="speed" type="radio" name="speed" :value="option.value" class="sr-only" />
            {{ option.label }}
            <span class="text-[11px] font-normal text-muted">{{ option.hint }}</span>
          </label>
        </div>
      </fieldset>

      <label class="mt-4 flex items-center gap-2 text-sm">
        <input v-model="loop" type="checkbox" class="accent-brick" />
        Ricomincia da capo alla fine
      </label>

      <div class="mt-5 flex gap-2">
        <button type="button" class="flex-1 rounded-xl border-2 border-line py-2.5 font-bold" @click="emit('cancel')">
          Annulla
        </button>
        <button type="submit" class="flex flex-1 items-center justify-center gap-2 rounded-xl bg-brick py-2.5 font-bold text-white">
          <AppIcon name="play" filled class="size-4" />
          Avvia
        </button>
      </div>
    </form>
  </div>
</template>
