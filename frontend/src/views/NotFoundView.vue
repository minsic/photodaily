<script setup lang="ts">
import { RouterLink } from 'vue-router'
</script>

<template>
  <main class="flex min-h-dvh flex-col items-center justify-center gap-3 px-4 text-center">
    <h1 class="text-2xl font-bold">Pagina non trovata</h1>
    <p class="text-muted">Il link che hai seguito non porta da nessuna parte.</p>
    <RouterLink
      :to="{ name: 'timeline' }"
      class="rounded-xl bg-brick px-4 py-2.5 font-bold text-white"
    >
      Torna alla timeline
    </RouterLink>
  </main>
</template>
