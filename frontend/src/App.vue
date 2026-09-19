<script setup lang="ts">
import { RouterView } from 'vue-router'

import PwaUpdatePrompt from '@/components/PwaUpdatePrompt.vue'
import ToastList from '@/components/ToastList.vue'
</script>

<template>
  <RouterView />
  <ToastList />
  <PwaUpdatePrompt />
</template>
