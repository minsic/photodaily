<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'

import { auth as authApi } from '@/api'
import { ApiError } from '@/api/client'
import AppLogo from '@/components/logo/AppLogo.vue'

/** Si chiede il link via email; la risposta è la stessa che l'account esista o no. */
const route = useRoute()

const email = ref(typeof route.query.email === 'string' ? route.query.email : '')
const busy = ref(false)
const sent = ref<string | null>(null)
const error = ref<string | null>(null)

async function onSubmit(): Promise<void> {
  busy.value = true
  error.value = null

  try {
    sent.value = (await authApi.forgotPassword(email.value.trim())).message
  } catch (cause) {
    error.value =
      cause instanceof ApiError
        ? cause.status === 429
          ? 'Troppe richieste: riprova tra qualche minuto.'
          : (cause.field('email') ?? cause.message)
        : 'Non riesco a mandare la richiesta. Riprova.'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <main class="flex min-h-dvh flex-col items-center justify-center px-4 py-10">
    <div class="w-full max-w-sm">
      <h1 class="flex justify-center text-ink">
        <AppLogo variant="verticale" class="h-24" />
      </h1>
      <h2 class="mt-6 text-center text-xl font-bold">Password dimenticata</h2>

      <div v-if="sent" class="mt-6 rounded-xl bg-card px-4 py-4 text-sm card-shadow" role="status">
        <p>{{ sent }}</p>
        <p class="mt-2 text-muted">Il link vale un'ora. Se non la trovi, guarda anche nello spam.</p>
      </div>

      <form v-else class="mt-6 space-y-4" novalidate @submit.prevent="onSubmit">
        <p class="text-sm text-muted">Scrivi l'email del tuo account: ti mandiamo un link per sceglierne una nuova.</p>
        <div>
          <label for="email" class="mb-1 block text-sm font-bold">Email</label>
          <input
            id="email"
            v-model="email"
            type="email"
            autocomplete="email"
            required
            class="w-full rounded-xl border-2 border-line bg-card px-3 py-2.5"
          />
        </div>

        <p v-if="error" class="rounded-xl bg-brick/10 px-3 py-2 text-sm text-brick">{{ error }}</p>

        <button
          type="submit"
          class="w-full rounded-xl bg-brick px-4 py-3 font-bold text-white disabled:opacity-60"
          :disabled="busy || !email"
        >
          {{ busy ? 'Invio…' : 'Mandami il link' }}
        </button>
      </form>

      <p class="mt-6 text-center text-sm">
        <RouterLink :to="{ name: 'login' }" class="font-bold text-muted underline hover:text-ink">Torna all'accesso</RouterLink>
      </p>
    </div>
  </main>
</template>
