<script setup lang="ts">
import { RouterLink } from 'vue-router'
</script>

<template>
  <nav aria-label="Informazioni legali" class="flex flex-wrap justify-center gap-x-4 gap-y-1 text-xs text-muted">
    <RouterLink :to="{ name: 'privacy' }" class="underline hover:text-ink">Privacy</RouterLink>
    <RouterLink :to="{ name: 'terms' }" class="underline hover:text-ink">Termini</RouterLink>
    <RouterLink :to="{ name: 'cookies' }" class="underline hover:text-ink">Cookie</RouterLink>
  </nav>
</template>
