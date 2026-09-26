<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'

import AppIcon from '@/components/AppIcon.vue'
import { useFamilyToday } from '@/composables/useFamilyToday'
import { useDiary } from '@/diary/useDiary'

/** "Timeline | Mese" nell'header: il lettore si apre da tutte e due. */
const props = defineProps<{ current: 'timeline' | 'mese' }>()

const diary = useDiary()
const { today } = useFamilyToday()

const monthTarget = computed(() => {
  const date = today()

  return diary.value.to.month(Number(date.slice(0, 4)), Number(date.slice(5, 7)))
})

const options = computed(() => [
  { key: 'timeline', label: 'Timeline', icon: 'list' as const, to: diary.value.to.timeline() },
  { key: 'mese', label: 'Mese', icon: 'grid' as const, to: monthTarget.value },
])
</script>

<template>
  <nav class="flex rounded-full border-2 border-line p-0.5 text-sm font-bold" aria-label="Vista">
    <RouterLink
      v-for="option in options"
      :key="option.key"
      :to="option.to"
      class="flex items-center gap-1 rounded-full px-2.5 py-1 transition"
      :class="props.current === option.key ? 'bg-ink text-paper' : 'text-muted hover:text-ink'"
      :aria-current="props.current === option.key ? 'page' : undefined"
    >
      <AppIcon :name="option.icon" class="size-3.5" />
      <span>{{ option.label }}</span>
    </RouterLink>
  </nav>
</template>
