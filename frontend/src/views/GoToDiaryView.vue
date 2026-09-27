<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'

import { auth as authApi } from '@/api'
import AppSpinner from '@/components/AppSpinner.vue'
import { useAuthStore } from '@/stores/auth'

/**
 * Su photodaily.app, per chi è entrato: il diario sta sul suo indirizzo
 * (nome.photodaily.app) e ci si arriva già dentro con un codice monouso.
 */
const auth = useAuthStore()
const router = useRouter()

const failed = ref(false)

async function go(): Promise<void> {
  failed.value = false

  try {
    window.location.assign(await authApi.toDiary())
  } catch {
    failed.value = true
  }
}

async function logout(): Promise<void> {
  await auth.logout()
  await router.replace({ name: 'home' })
}

onMounted(go)
</script>

<template>
  <main class="flex min-h-dvh flex-col items-center justify-center gap-3 px-4 text-center">
    <template v-if="failed">
      <h1 class="text-2xl font-bold">Non riesco ad aprire il diario</h1>
      <p class="text-muted">Riprova tra poco, oppure esci ed entra dall'indirizzo del tuo diario.</p>
      <div class="flex gap-2">
        <button type="button" class="rounded-xl bg-brick px-4 py-2.5 font-bold text-white" @click="go">Riprova</button>
        <button type="button" class="rounded-xl border-2 border-line px-4 py-2.5 font-bold" @click="logout">Esci</button>
      </div>
    </template>
    <AppSpinner v-else :label="`Apro ${auth.family?.name ?? 'il tuo diario'}…`" />
  </main>
</template>
