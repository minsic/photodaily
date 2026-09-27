<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'

import { auth as authApi } from '@/api'
import { ApiError } from '@/api/client'
import AppLogo from '@/components/logo/AppLogo.vue'
import { useAuthStore } from '@/stores/auth'

/**
 * Dal link mandato al nuovo indirizzo: conferma il cambio appena si apre la
 * pagina. Funziona anche su un dispositivo dove non si è entrati.
 */
const props = defineProps<{ token: string }>()

const auth = useAuthStore()
const state = ref<'loading' | 'done' | 'error'>('loading')
const email = ref<string | null>(null)
const error = ref<string | null>(null)

onMounted(async () => {
  if (!props.token) {
    state.value = 'error'
    error.value = 'Il link non è completo: aprilo di nuovo dall\'email.'

    return
  }

  try {
    email.value = (await authApi.confirmEmailChange(props.token)).email
    state.value = 'done'

    // Se su questo dispositivo si è già dentro, l'email mostrata si aggiorna.
    if (auth.user) {
      auth.user.email = email.value
      auth.user.email_in_attesa = null
    }
  } catch (cause) {
    state.value = 'error'
    error.value =
      cause instanceof ApiError ? (cause.field('token') ?? cause.message) : 'Non riesco a confermare. Riprova tra poco.'
  }
})
</script>

<template>
  <main class="flex min-h-dvh flex-col items-center justify-center px-4 py-10">
    <div class="w-full max-w-sm text-center">
      <h1 class="flex justify-center text-ink">
        <AppLogo variant="verticale" class="h-24" />
      </h1>

      <p v-if="state === 'loading'" class="mt-8 text-muted">Confermo la nuova email…</p>

      <div v-else-if="state === 'done'" class="mt-8 rounded-xl bg-card px-4 py-4 card-shadow" role="status">
        <p class="font-bold">Email cambiata</p>
        <p class="mt-1 text-sm text-muted">
          Da ora entri con <strong class="text-ink">{{ email }}</strong>.
        </p>
        <RouterLink
          :to="{ name: auth.isLoggedIn ? 'timeline' : 'login' }"
          class="mt-4 inline-block rounded-xl bg-brick px-4 py-2.5 font-bold text-white"
        >
          {{ auth.isLoggedIn ? 'Vai al diario' : 'Entra' }}
        </RouterLink>
      </div>

      <div v-else class="mt-8 rounded-xl bg-card px-4 py-4 text-sm card-shadow" role="alert">
        <p>{{ error }}</p>
        <RouterLink :to="{ name: auth.isLoggedIn ? 'profile' : 'login' }" class="mt-3 inline-block font-bold text-brick underline">
          {{ auth.isLoggedIn ? 'Torna alle impostazioni' : 'Vai all\'accesso' }}
        </RouterLink>
      </div>
    </div>
  </main>
</template>
