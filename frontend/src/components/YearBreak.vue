<script setup lang="ts">
/**
 * Separatore d'anno nella timeline con tutti gli anni: resta in alto sotto
 * l'intestazione finché si scorrono le foto di quell'anno.
 */
defineProps<{ year: number }>()
</script>

<template>
  <li class="sticky top-14 z-10 -mx-4 mb-3 bg-paper/90 px-4 py-2 backdrop-blur-md">
    <h2 class="text-xl font-bold tracking-tight">{{ year }}</h2>
  </li>
</template>
