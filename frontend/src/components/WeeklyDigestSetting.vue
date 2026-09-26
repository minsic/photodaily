<script setup lang="ts">
import { computed, ref } from 'vue'

import { push as pushApi } from '@/api'
import { ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useToastsStore } from '@/stores/toasts'

/** Il riepilogo della settimana via email: acceso di serie, si spegne da qui. */
const auth = useAuthStore()
const toasts = useToastsStore()
const saving = ref(false)

const enabled = computed(() => auth.user?.promemoria.riepilogo ?? true)

async function toggle(): Promise<void> {
  saving.value = true

  try {
    const user = await pushApi.setPreferences({ riepilogo: !enabled.value })

    if (auth.user) {
      auth.user.promemoria = user.promemoria
    }

    toasts.success(user.promemoria.riepilogo ? 'Il riepilogo arriverà domenica sera.' : 'Niente più riepilogo via email.')
  } catch (cause) {
    toasts.error(cause instanceof ApiError ? cause.message : 'Non riesco a salvare la scelta. Riprova tra poco.')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <section>
    <h2 class="text-lg font-bold">Riepilogo della settimana</h2>
    <p class="mt-1 text-sm text-muted">
      La domenica sera un'email con le foto della settimana, i giorni rimasti senza foto e i cuori
      arrivati. Se in settimana non c'è nessuna foto non arriva niente.
    </p>

    <label class="mt-4 flex cursor-pointer items-center justify-between gap-4 rounded-xl bg-card px-4 py-3 card-shadow">
      <span class="font-bold">Mandamelo a {{ auth.user?.email }}</span>
      <input
        type="checkbox"
        role="switch"
        class="size-5 accent-brick"
        :checked="enabled"
        :disabled="saving"
        @change="toggle"
      />
    </label>
  </section>
</template>
