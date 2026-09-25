<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'

import AppHeader from '@/components/AppHeader.vue'
import AppIcon from '@/components/AppIcon.vue'
import AppSpinner from '@/components/AppSpinner.vue'
import FilterBar from '@/components/FilterBar.vue'
import TimelineItem from '@/components/TimelineItem.vue'
import { useRefreshWhenVisible } from '@/composables/useRefreshWhenVisible'
import { useAuthStore } from '@/stores/auth'
import { usePhotosStore } from '@/stores/photos'

const auth = useAuthStore()
const photos = usePhotosStore()
const router = useRouter()

const menuOpen = ref(false)

// Tornando alla PWA dopo un po' gli URL delle immagini sarebbero scaduti.
useRefreshWhenVisible(() => photos.refreshIfStale())

function onAnno(anno: number): void {
  photos.setAnno(anno)
  void photos.load()
}

function onSpeciali(): void {
  photos.toggleSpeciali()
  void photos.load()
}

function onBozze(bozze: boolean): void {
  photos.setStato(bozze ? 'bozze' : 'pubblicate')
  void photos.load()
}

onMounted(async () => {
  if (photos.years.length === 0) {
    await photos.loadYears().catch(() => undefined)
  }

  await photos.load()
})

async function logout(): Promise<void> {
  menuOpen.value = false
  await auth.logout()
  photos.reset()
  await router.replace({ name: 'login' })
}
</script>

<template>
  <AppHeader>
    <template #right>
      <RouterLink
        :to="{ name: 'upload' }"
        class="flex items-center gap-1 rounded-full bg-brick px-3 py-1.5 text-sm font-bold text-white"
      >
        <AppIcon name="plus" class="size-4" />
        Carica
      </RouterLink>

      <button
        type="button"
        class="ml-1 flex size-9 items-center justify-center rounded-full border-2 border-line font-bold text-muted"
        :aria-expanded="menuOpen"
        aria-haspopup="true"
        :aria-label="`Menu di ${auth.user?.name ?? 'utente'}`"
        @click="menuOpen = !menuOpen"
      >
        {{ (auth.user?.name ?? '?').charAt(0).toUpperCase() }}
      </button>
    </template>
  </AppHeader>

  <div v-if="menuOpen" class="fixed inset-0 z-30" @click="menuOpen = false">
    <div
      class="absolute right-4 top-14 w-64 rounded-2xl bg-card p-4 text-sm card-shadow"
      @click.stop
    >
      <p class="font-bold">{{ auth.user?.name }}</p>
      <p class="truncate text-muted">{{ auth.user?.email }}</p>

      <dl v-if="auth.family" class="mt-3 space-y-1 border-t border-line pt-3 text-muted">
        <div class="flex justify-between gap-2">
          <dt>Famiglia</dt>
          <dd class="font-bold text-ink">{{ auth.family.name }}</dd>
        </div>
        <div class="flex justify-between gap-2">
          <dt>Spazio usato</dt>
          <dd class="font-bold text-ink">{{ auth.family.storage_used_mb }} MB</dd>
        </div>
        <div v-if="auth.family.photos_count !== undefined" class="flex justify-between gap-2">
          <dt>Foto</dt>
          <dd class="font-bold text-ink">{{ auth.family.photos_count }}</dd>
        </div>
      </dl>

      <RouterLink
        v-if="auth.isAdmin"
        :to="{ name: 'settings' }"
        class="mt-4 flex w-full items-center justify-center gap-2 rounded-xl border-2 border-line py-2 font-bold"
        @click="menuOpen = false"
      >
        <AppIcon name="settings" class="size-4" />
        Impostazioni famiglia
      </RouterLink>

      <button
        type="button"
        class="mt-2 flex w-full items-center justify-center gap-2 rounded-xl border-2 border-line py-2 font-bold"
        @click="logout"
      >
        <AppIcon name="logout" class="size-4" />
        Esci
      </button>
    </div>
  </div>

  <main class="mx-auto max-w-3xl px-4 pb-16">
    <FilterBar
      :years="photos.years"
      :anno="photos.anno"
      :speciali="photos.soloSpeciali"
      :bozze="photos.stato === 'bozze'"
      @update:anno="onAnno"
      @update:speciali="onSpeciali"
      @update:bozze="onBozze"
    />

    <AppSpinner v-if="photos.loading" />

    <p v-else-if="photos.error" class="rounded-xl bg-brick/10 px-4 py-3 text-sm text-brick">
      {{ photos.error }}
      <button type="button" class="ml-2 font-bold underline" @click="photos.load(true)">
        Riprova
      </button>
    </p>

    <div v-else-if="photos.items.length === 0" class="py-16 text-center text-muted">
      <p class="font-bold">Nessuna foto qui.</p>
      <p class="mt-1 text-sm">
        {{
          photos.hasFilters
            ? 'Prova a togliere i filtri.'
            : 'Carica la prima foto del diario.'
        }}
      </p>
    </div>

    <ul v-else class="mt-2">
      <TimelineItem v-for="photo in photos.items" :key="photo.id" :photo="photo" />
    </ul>
  </main>
</template>
