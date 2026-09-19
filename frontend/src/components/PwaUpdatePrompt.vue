<script setup lang="ts">
import { useRegisterSW } from 'virtual:pwa-register/vue'

// Con registerType "autoUpdate" la nuova versione viene installata da sola:
// qui si avvisa solo che basta ricaricare per usarla.
const { needRefresh, updateServiceWorker } = useRegisterSW()
</script>

<template>
  <div
    v-if="needRefresh"
    class="fixed inset-x-0 bottom-0 z-40 flex justify-center px-4 pb-4"
    role="status"
  >
    <div class="flex w-full max-w-md items-center gap-3 rounded-xl bg-card px-4 py-3 text-sm card-shadow">
      <span class="flex-1">È disponibile una nuova versione di PhotoDaily.</span>
      <button
        type="button"
        class="rounded-lg bg-brick px-3 py-1.5 font-bold text-white"
        @click="updateServiceWorker(true)"
      >
        Ricarica
      </button>
      <button type="button" class="text-muted" @click="needRefresh = false">Dopo</button>
    </div>
  </div>
</template>
