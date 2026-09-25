<script setup lang="ts">
import { RouterLink } from 'vue-router'

import AppHeader from '@/components/AppHeader.vue'
import AppIcon from '@/components/AppIcon.vue'
import InstallGuide from '@/components/InstallGuide.vue'
import { useAuthStore } from '@/stores/auth'

/** Impostazioni personali: valgono per chi le guarda, non per tutta la famiglia. */
const auth = useAuthStore()
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

  <main class="mx-auto max-w-2xl space-y-10 px-4 pb-20">
    <header class="mt-6">
      <h1 class="text-2xl font-bold tracking-tight">Impostazioni personali</h1>
      <p class="mt-1 text-sm text-muted">{{ auth.user?.name }} · {{ auth.user?.email }}</p>
    </header>

    <InstallGuide />
  </main>
</template>
