<script setup lang="ts">
import { computed } from 'vue'

import type { Protagonist } from '@/api/types'
import { describeAge } from '@/utils/age'

/** "Gio ha 1 anno, 3 mesi e 4 giorni" sotto la data di una foto; niente se non c'è niente da dire. */
const props = defineProps<{
  date: string
  protagonist: Protagonist | null | undefined
}>()

const text = computed(() => describeAge(props.protagonist?.birthdate, props.date, props.protagonist?.name))
</script>

<template>
  <p v-if="text" class="text-sm text-muted">{{ text }}</p>
</template>
