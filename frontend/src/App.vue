<script setup lang="ts">
import { computed } from 'vue'
import { RouterView, useRoute } from 'vue-router'

import PwaUpdatePrompt from '@/components/PwaUpdatePrompt.vue'
import ToastList from '@/components/ToastList.vue'
import { useReaderStore } from '@/stores/reader'

const route = useRoute()
const reader = useReaderStore()

/**
 * Col lettore aperto, sotto resta montata la vista da cui si è arrivati: lo
 * stesso RouterView riceve quella rotta invece della corrente, quindi il
 * componente non si smonta e lo scroll non si perde.
 */
const baseRoute = computed(() => {
  if (!route.meta.reader) {
    return route
  }

  return reader.backdropRoute
})
</script>

<template>
  <div :inert="route.meta.reader || undefined" :aria-hidden="route.meta.reader || undefined">
    <RouterView v-if="baseRoute" :route="baseRoute" />
  </div>
  <RouterView v-if="route.meta.reader" />
  <ToastList />
  <PwaUpdatePrompt />
</template>
