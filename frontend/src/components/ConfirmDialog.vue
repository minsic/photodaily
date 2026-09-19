<script setup lang="ts">
withDefaults(
  defineProps<{
    title: string
    message: string
    confirmLabel?: string
    busy?: boolean
  }>(),
  { confirmLabel: 'Conferma', busy: false },
)

const emit = defineEmits<{ confirm: []; cancel: [] }>()
</script>

<template>
  <div
    class="fixed inset-0 z-40 flex items-end justify-center bg-ink/50 p-4 sm:items-center"
    role="dialog"
    aria-modal="true"
    @click.self="emit('cancel')"
    @keydown.esc="emit('cancel')"
  >
    <div class="w-full max-w-sm rounded-2xl bg-card p-5 card-shadow">
      <h2 class="text-lg font-bold">{{ title }}</h2>
      <p class="mt-2 text-sm text-muted">{{ message }}</p>

      <div class="mt-5 flex justify-end gap-2">
        <button
          type="button"
          class="rounded-lg px-4 py-2 text-sm font-bold text-muted hover:text-ink"
          :disabled="busy"
          @click="emit('cancel')"
        >
          Annulla
        </button>
        <button
          type="button"
          class="rounded-lg bg-brick px-4 py-2 text-sm font-bold text-white disabled:opacity-60"
          :disabled="busy"
          @click="emit('confirm')"
        >
          {{ busy ? 'Attendi…' : confirmLabel }}
        </button>
      </div>
    </div>
  </div>
</template>
