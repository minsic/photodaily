<script setup lang="ts">
import { computed } from 'vue'

type IconName = keyof typeof paths

const paths = {
  star: 'M12 3.5l2.6 5.28 5.83.85-4.22 4.11 1 5.81L12 16.8l-5.21 2.74 1-5.81-4.22-4.11 5.83-.85L12 3.5z',
  plus: 'M12 5v14M5 12h14',
  back: 'M19 12H5M12 19l-7-7 7-7',
  forward: 'M5 12h14M12 5l7 7-7 7',
  pencil: 'M4 20h4L19 9a2.83 2.83 0 10-4-4L4 16v4z',
  trash: 'M4 7h16M10 11v6M14 11v6M5 7l1 13h12l1-13M9 7V4h6v3',
  logout: 'M15 17l5-5-5-5M20 12H9M13 3H6a1 1 0 00-1 1v16a1 1 0 001 1h7',
  camera: 'M3 8a2 2 0 012-2h2.5l1.2-2h6.6l1.2 2H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V8z M15 13a3 3 0 11-6 0 3 3 0 016 0z',
  check: 'M4 12.5l5 5 11-11',
  close: 'M6 6l12 12M18 6L6 18',
  warning: 'M12 4l9 16H3l9-16zM12 10v4M12 17.5v.5',
} as const

const props = withDefaults(defineProps<{ name: IconName; filled?: boolean }>(), { filled: false })

const d = computed(() => paths[props.name])
</script>

<template>
  <svg
    viewBox="0 0 24 24"
    :fill="filled ? 'currentColor' : 'none'"
    :stroke="filled ? 'none' : 'currentColor'"
    stroke-width="2"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
    focusable="false"
  >
    <path :d="d" />
  </svg>
</template>
