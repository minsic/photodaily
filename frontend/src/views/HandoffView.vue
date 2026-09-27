<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'

import { auth as authApi } from '@/api'
import AppSpinner from '@/components/AppSpinner.vue'
import { useAuthStore } from '@/stores/auth'
import { useToastsStore } from '@/stores/toasts'

/**
 * Ingresso nel diario col codice monouso arrivato nel frammento
 * dell'indirizzo (#…): dopo la registrazione o da photodaily.app.
 */
const auth = useAuthStore()
const toasts = useToastsStore()
const router = useRouter()

const failed = ref(false)

onMounted(async () => {
  const code = window.location.hash.slice(1)

  // Il codice non resta nella cronologia, anche se non vale più.
  window.history.replaceState(window.history.state, '', window.location.pathname)

  if (!code) {
    failed.value = true

    return
  }

  try {
    const session = await authApi.handoff(code)

    auth.applySession(session)

    if (session.benvenuto) {
      toasts.success(`Il diario ${session.user.family?.name ?? ''} è pronto: carica la prima foto!`)
      await router.replace({ name: 'upload' })
    } else {
      await router.replace({ name: 'timeline' })
    }
  } catch {
    failed.value = true
  }
})
</script>

<template>
  <main class="flex min-h-dvh flex-col items-center justify-center gap-3 px-4 text-center">
    <template v-if="failed">
      <h1 class="text-2xl font-bold">Link scaduto</h1>
      <p class="text-muted">Il link per entrare è già stato usato o è scaduto. Entra con email e password.</p>
      <RouterLink :to="{ name: 'login' }" class="rounded-xl bg-brick px-4 py-2.5 font-bold text-white">Entra</RouterLink>
    </template>
    <AppSpinner v-else label="Apro il tuo diario…" />
  </main>
</template>
