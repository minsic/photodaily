<script setup lang="ts">
import { RouterLink } from 'vue-router'

import AppIcon from '@/components/AppIcon.vue'
import LegalLinks from '@/components/LegalLinks.vue'
import AppLogo from '@/components/logo/AppLogo.vue'

/** L'indirizzo principale del servizio visto da chi non è entrato. */
const points = [
  {
    icon: 'camera',
    title: 'Una foto al giorno',
    text: 'Scegli lo scatto della giornata e scrivi due righe: in un anno hai un racconto intero.',
  },
  {
    icon: 'heart',
    title: 'Solo per la tua famiglia',
    text: 'Il diario è privato: lo vedono le persone che inviti. Se vuoi, lo apri a tutti o lo proteggi con una password.',
  },
  {
    icon: 'calendar',
    title: 'Da rileggere',
    text: 'Timeline, mesi e anni, lo slideshow e le foto di un anno fa oggi, per ritrovare ogni momento.',
  },
] as const
</script>

<template>
  <main class="flex min-h-dvh flex-col items-center px-4 py-10">
    <div class="flex w-full max-w-md flex-1 flex-col justify-center">
      <h1 class="flex justify-center text-ink">
        <AppLogo variant="verticale" class="h-28" />
      </h1>
      <p class="mt-4 text-center text-lg">Il diario fotografico di famiglia.</p>

      <ul class="mt-8 space-y-3">
        <li v-for="point in points" :key="point.title" class="flex gap-3 rounded-2xl bg-card px-4 py-4 card-shadow">
          <AppIcon :name="point.icon" class="mt-0.5 size-6 shrink-0 text-brick" />
          <div>
            <h2 class="font-bold">{{ point.title }}</h2>
            <p class="mt-1 text-sm text-muted">{{ point.text }}</p>
          </div>
        </li>
      </ul>

      <RouterLink
        :to="{ name: 'login' }"
        class="mt-8 block rounded-xl bg-brick px-4 py-3 text-center font-bold text-white"
      >
        Entra nel tuo diario
      </RouterLink>
    </div>

    <footer class="mt-10">
      <LegalLinks />
    </footer>
  </main>
</template>
