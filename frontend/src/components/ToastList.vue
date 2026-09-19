<script setup lang="ts">
import AppIcon from '@/components/AppIcon.vue'
import { useToastsStore } from '@/stores/toasts'

const toasts = useToastsStore()
</script>

<template>
  <div class="pointer-events-none fixed inset-x-0 bottom-4 z-50 flex flex-col items-center gap-2 px-4">
    <TransitionGroup
      enter-from-class="translate-y-2 opacity-0"
      enter-active-class="transition duration-200"
      leave-to-class="translate-y-2 opacity-0"
      leave-active-class="transition duration-200"
    >
      <output
        v-for="toast in toasts.items"
        :key="toast.id"
        class="pointer-events-auto flex w-full max-w-md items-start gap-2 rounded-xl px-4 py-3 text-sm card-shadow"
        :class="toast.kind === 'error' ? 'bg-brick text-white' : 'bg-ink text-paper'"
      >
        <AppIcon :name="toast.kind === 'error' ? 'warning' : 'check'" class="mt-0.5 size-4 shrink-0" />
        <span class="flex-1">{{ toast.text }}</span>
        <button
          type="button"
          class="shrink-0 rounded p-0.5 opacity-70 hover:opacity-100"
          aria-label="Chiudi"
          @click="toasts.dismiss(toast.id)"
        >
          <AppIcon name="close" class="size-4" />
        </button>
      </output>
    </TransitionGroup>
  </div>
</template>
