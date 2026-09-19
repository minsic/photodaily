<script setup lang="ts">
withDefaults(defineProps<{ label?: string }>(), { label: 'Caricamento…' })
</script>

<template>
  <div class="flex items-center justify-center gap-3 py-10 text-muted" role="status">
    <span
      class="size-5 animate-spin rounded-full border-2 border-line border-t-brick"
      aria-hidden="true"
    />
    <span class="text-sm">{{ label }}</span>
  </div>
</template>
