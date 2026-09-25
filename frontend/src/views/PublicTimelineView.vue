<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'

import AppHeader from '@/components/AppHeader.vue'
import AppIcon from '@/components/AppIcon.vue'
import AppSpinner from '@/components/AppSpinner.vue'
import FilterBar from '@/components/FilterBar.vue'
import TimelineItem from '@/components/TimelineItem.vue'
import { useRefreshWhenVisible } from '@/composables/useRefreshWhenVisible'
import { useNoIndex } from '@/composables/useNoIndex'
import { usePublicDiaryStore } from '@/stores/publicDiary'

/**
 * Diario in sola lettura: nessun pulsante di caricamento, modifica o
 * cancellazione. Le rotte di scrittura restano quelle autenticate.
 */
const props = defineProps<{ slug: string }>()

const diary = usePublicDiaryStore()
const password = ref('')

useNoIndex()

// Tornando alla pagina dopo un po' gli URL delle immagini sarebbero scaduti.
useRefreshWhenVisible(() => diary.refreshIfStale())

onMounted(() => diary.open(props.slug))
watch(() => props.slug, (slug) => diary.open(slug))
</script>

<template>
  <AppHeader>
    <template #left>
      <span class="text-lg font-bold tracking-tight">
        Photo<span class="text-brick">Daily</span>
      </span>
    </template>
    <template #right>
      <span class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wide text-muted">
        <AppIcon name="lock" class="size-3.5" />
        sola lettura
      </span>
    </template>
  </AppHeader>

  <main class="mx-auto max-w-3xl px-4 pb-16">
    <AppSpinner v-if="diary.state === 'loading'" label="Apro il diario…" />

    <div v-else-if="diary.state === 'unavailable'" class="py-20 text-center">
      <AppIcon name="lock" class="mx-auto size-8 text-muted" />
      <h1 class="mt-3 text-xl font-bold">Questo diario non è pubblico</h1>
      <p class="mt-2 text-sm text-muted">
        Il link potrebbe non essere più valido. Chiedi a chi te l'ha mandato.
      </p>
    </div>

    <form
      v-else-if="diary.state === 'password'"
      class="mx-auto mt-16 w-full max-w-sm"
      novalidate
      @submit.prevent="diary.unlock(password)"
    >
      <AppIcon name="lock" class="mx-auto size-8 text-muted" />
      <h1 class="mt-3 text-center text-xl font-bold">Diario protetto da password</h1>
      <p class="mt-2 text-center text-sm text-muted">
        Inserisci la password che ti hanno dato per vedere le foto.
      </p>

      <label for="password" class="sr-only">Password condivisa</label>
      <input
        id="password"
        v-model="password"
        type="password"
        required
        autocomplete="off"
        class="mt-6 w-full rounded-xl border-2 border-line bg-card px-3 py-2.5"
      />

      <p v-if="diary.unlockError" class="mt-2 text-sm text-brick">{{ diary.unlockError }}</p>

      <button
        type="submit"
        class="mt-3 w-full rounded-xl bg-brick px-4 py-3 font-bold text-white disabled:opacity-60"
        :disabled="diary.unlocking"
      >
        {{ diary.unlocking ? 'Verifico…' : 'Entra' }}
      </button>
    </form>

    <template v-else>
      <FilterBar
        :years="diary.years"
        :anno="diary.anno"
        :speciali="diary.soloSpeciali"
        :with-drafts="false"
        @update:anno="diary.setAnno"
        @update:speciali="diary.setSoloSpeciali"
      />

      <AppSpinner v-if="diary.loading" />

      <p v-else-if="diary.error" class="rounded-xl bg-brick/10 px-4 py-3 text-sm text-brick">
        {{ diary.error }}
        <button type="button" class="ml-2 font-bold underline" @click="diary.load()">Riprova</button>
      </p>

      <div v-else-if="diary.items.length === 0" class="py-16 text-center text-muted">
        <p class="font-bold">Nessuna foto da vedere qui.</p>
      </div>

      <ul v-else class="mt-2">
        <TimelineItem
          v-for="photo in diary.items"
          :key="photo.id"
          :photo="photo"
          :to="{ name: 'public-photo', params: { slug, id: photo.id } }"
          :refresh="diary.refreshImage"
        />
      </ul>
    </template>
  </main>
</template>
