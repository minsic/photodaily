<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { ApiError } from '@/api/client'
import AppLogo from '@/components/logo/AppLogo.vue'
import { useAuthStore } from '@/stores/auth'
import { useSiteStore } from '@/stores/site'

const auth = useAuthStore()
const site = useSiteStore()
const router = useRouter()
const route = useRoute()

const email = ref('')
const password = ref('')
const busy = ref(false)
const error = ref<string | null>(null)

async function onSubmit(): Promise<void> {
  busy.value = true
  error.value = null

  try {
    await auth.login(email.value, password.value)

    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/'

    await router.replace(redirect)
  } catch (cause) {
    error.value =
      cause instanceof ApiError
        ? (cause.field('email') ?? cause.message)
        : 'Non riesco ad accedere. Riprova.'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <main class="flex min-h-dvh flex-col items-center justify-center px-4 py-10">
    <div class="w-full max-w-sm">
      <h1 class="flex justify-center text-ink">
        <AppLogo variant="verticale" class="h-28" />
      </h1>
      <p v-if="site.family" class="mt-2 text-center text-sm text-muted">
        Il diario di <strong class="text-ink">{{ site.family.name }}</strong>
      </p>
      <p v-else class="mt-2 text-center text-sm text-muted">Il diario fotografico di famiglia.</p>

      <form class="mt-8 space-y-4" novalidate @submit.prevent="onSubmit">
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

        <div>
          <label for="password" class="mb-1 block text-sm font-bold">Password</label>
          <input
            id="password"
            v-model="password"
            type="password"
            autocomplete="current-password"
            required
            class="w-full rounded-xl border-2 border-line bg-card px-3 py-2.5"
          />
        </div>

        <p v-if="error" class="rounded-xl bg-brick/10 px-3 py-2 text-sm text-brick">{{ error }}</p>

        <button
          type="submit"
          class="w-full rounded-xl bg-brick px-4 py-3 font-bold text-white disabled:opacity-60"
          :disabled="busy"
        >
          {{ busy ? 'Accesso…' : 'Entra' }}
        </button>
      </form>
    </div>
  </main>
</template>
