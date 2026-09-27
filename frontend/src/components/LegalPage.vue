<script setup lang="ts">
import { RouterLink } from 'vue-router'

import LegalLinks from '@/components/LegalLinks.vue'
import AppLogo from '@/components/logo/AppLogo.vue'
import { legal } from '@/legal'

defineProps<{ title: string }>()
</script>

<template>
  <main class="mx-auto max-w-2xl px-4 py-10">
    <RouterLink :to="{ name: 'timeline' }" class="inline-flex text-ink" aria-label="PhotoDaily">
      <AppLogo variant="orizzontale" class="h-10" />
    </RouterLink>

    <article class="legal-text mt-8 rounded-2xl bg-card px-5 py-6 card-shadow sm:px-8">
      <h1 class="text-2xl font-bold">{{ title }}</h1>
      <p class="mt-1 text-sm text-muted">Ultimo aggiornamento: {{ legal.updatedAt }}</p>
      <slot />
    </article>

    <footer class="mt-8">
      <LegalLinks />
    </footer>
  </main>
</template>

