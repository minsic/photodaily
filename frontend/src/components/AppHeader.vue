<script setup lang="ts">
import { RouterLink } from 'vue-router'
</script>

<template>
  <header class="sticky top-0 z-20 border-b border-line bg-paper/85 backdrop-blur-md">
    <div class="mx-auto flex h-14 max-w-3xl items-center gap-2 px-4">
      <slot name="left">
        <RouterLink
          :to="{ name: 'timeline' }"
          class="rounded-lg text-lg font-bold tracking-tight text-ink"
        >
          Photo<span class="text-brick">Daily</span>
        </RouterLink>
      </slot>

      <div class="ml-auto flex items-center gap-1">
        <slot name="right" />
      </div>
    </div>
  </header>
</template>
