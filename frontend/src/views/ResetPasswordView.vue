<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'

import { auth as authApi } from '@/api'
import { ApiError } from '@/api/client'
import AppLogo from '@/components/logo/AppLogo.vue'
import { useAuthStore } from '@/stores/auth'
import { useToastsStore } from '@/stores/toasts'

/** Dal link dell'email: si sceglie la password nuova e si entra subito. */
const props = defineProps<{ token: string; email: string }>()

const auth = useAuthStore()
const toasts = useToastsStore()
const router = useRouter()

const password = ref('')
const confirmation = ref('')
const busy = ref(false)
const error = ref<string | null>(null)
const expired = ref(props.token === '' || props.email === '')

async function onSubmit(): Promise<void> {
  if (password.value !== confirmation.value) {
    error.value = 'Le due password non coincidono.'

    return
  }

  busy.value = true
  error.value = null

  try {
    auth.applySession(
      await authApi.resetPassword({
        token: props.token,
        email: props.email,
        password: password.value,
        password_confirmation: confirmation.value,
      }),
    )
    toasts.success('Password cambiata: sei dentro.')
    await router.replace({ name: 'timeline' })
  } catch (cause) {
    if (cause instanceof ApiError && cause.field('token')) {
      expired.value = true
    } else {
      error.value =
        cause instanceof ApiError
          ? cause.status === 429
            ? 'Troppi tentativi: riprova tra qualche minuto.'
            : (cause.field('password') ?? cause.message)
          : 'Non riesco a salvare la password. Riprova.'
    }
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
      <h2 class="mt-6 text-center text-xl font-bold">Nuova password</h2>

      <div v-if="expired" class="mt-6 rounded-xl bg-card px-4 py-4 text-center text-sm card-shadow">
        <p>Il link non è valido o è scaduto.</p>
        <RouterLink
          :to="{ name: 'forgot-password', query: email ? { email } : {} }"
          class="mt-3 inline-block font-bold text-brick underline"
        >
          Chiedine uno nuovo
        </RouterLink>
      </div>

      <form v-else class="mt-6 space-y-4" novalidate @submit.prevent="onSubmit">
        <p class="text-sm text-muted">
          Per <strong class="text-ink">{{ email }}</strong>
        </p>
        <div>
          <label for="password" class="mb-1 block text-sm font-bold">Nuova password</label>
          <input
            id="password"
            v-model="password"
            type="password"
            autocomplete="new-password"
            minlength="8"
            required
            class="w-full rounded-xl border-2 border-line bg-card px-3 py-2.5"
          />
          <p class="mt-1 text-xs text-muted">Almeno 8 caratteri.</p>
        </div>
        <div>
          <label for="confirmation" class="mb-1 block text-sm font-bold">Ripetila</label>
          <input
            id="confirmation"
            v-model="confirmation"
            type="password"
            autocomplete="new-password"
            required
            class="w-full rounded-xl border-2 border-line bg-card px-3 py-2.5"
          />
        </div>

        <p v-if="error" class="rounded-xl bg-brick/10 px-3 py-2 text-sm text-brick">{{ error }}</p>

        <button
          type="submit"
          class="w-full rounded-xl bg-brick px-4 py-3 font-bold text-white disabled:opacity-60"
          :disabled="busy || !password"
        >
          {{ busy ? 'Salvo…' : 'Salva e entra' }}
        </button>
      </form>
    </div>
  </main>
</template>
