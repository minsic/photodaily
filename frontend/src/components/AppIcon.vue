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
  settings: 'M12 15a3 3 0 100-6 3 3 0 000 6z M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 11-2.83 2.83l-.06-.06a1.65 1.65 0 00-2.83 1.17V21a2 2 0 11-4 0v-.09A1.65 1.65 0 007.28 19.4a2 2 0 11-2.83-2.83l.06-.06A1.65 1.65 0 003 12a2 2 0 110-4h.09A1.65 1.65 0 004.6 7.28a2 2 0 112.83-2.83l.06.06A1.65 1.65 0 0012 3a2 2 0 114 0v.09c0 .67.4 1.27 1.02 1.51.62.26 1.33.12 1.81-.35l.06-.06a2 2 0 112.83 2.83l-.06.06A1.65 1.65 0 0021 12a2 2 0 110 4h-.09a1.65 1.65 0 00-1.51 1z',
  copy: 'M9 9h10v10a2 2 0 01-2 2H9a2 2 0 01-2-2V11a2 2 0 012-2z M5 15H4a2 2 0 01-2-2V5a2 2 0 012-2h8a2 2 0 012 2v1',
  link: 'M10 13a5 5 0 007.07 0l3-3a5 5 0 00-7.07-7.07l-1.5 1.5M14 11a5 5 0 00-7.07 0l-3 3a5 5 0 007.07 7.07l1.5-1.5',
  lock: 'M6 11h12a1 1 0 011 1v8a1 1 0 01-1 1H6a1 1 0 01-1-1v-8a1 1 0 011-1z M8 11V7a4 4 0 118 0v4',
  globe: 'M12 21a9 9 0 100-18 9 9 0 000 18z M3.5 9h17M3.5 15h17 M12 3a14 14 0 000 18 14 14 0 000-18z',
  mail: 'M3 7a2 2 0 012-2h14a2 2 0 012 2v10a2 2 0 01-2 2H5a2 2 0 01-2-2V7z M3.5 7.5l8.5 6 8.5-6',
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
