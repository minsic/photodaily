<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'

import { invites as invitesApi } from '@/api'
import { ApiError, type ValidationErrors } from '@/api/client'
import type { InvitePreview } from '@/api/types'
import AppSpinner from '@/components/AppSpinner.vue'
import { useNoIndex } from '@/composables/useNoIndex'
import { useAuthStore } from '@/stores/auth'
import { useToastsStore } from '@/stores/toasts'
import { formatShortDate } from '@/utils/date'

const props = defineProps<{ token: string }>()

const auth = useAuthStore()
const toasts = useToastsStore()
const router = useRouter()

useNoIndex()

const invite = ref<InvitePreview | null>(null)
const loading = ref(true)
const invalid = ref(false)

const name = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const busy = ref(false)
const errors = ref<ValidationErrors>({})
const error = ref<string | null>(null)

onMounted(async () => {
  try {
    invite.value = await invitesApi.preview(props.token)
  } catch {
    invalid.value = true
  } finally {
    loading.value = false
  }
})

async function submit(): Promise<void> {
  busy.value = true
  errors.value = {}
  error.value = null

  try {
    const session = await invitesApi.accept(props.token, {
      name: name.value,
      password: password.value,
      password_confirmation: passwordConfirmation.value,
    })

    auth.applySession(session)
    toasts.success(`Benvenuto in ${session.user.family?.name ?? 'PhotoDaily'}!`)
    await router.replace({ name: 'timeline' })
  } catch (cause) {
    if (cause instanceof ApiError && cause.isValidation) {
      errors.value = cause.errors
    } else if (cause instanceof ApiError && cause.status === 404) {
      invalid.value = true
    } else {
      error.value = cause instanceof ApiError ? cause.message : 'Non riesco a completare la registrazione.'
    }
  } finally {
    busy.value = false
  }
}

function fieldError(field: string): string | undefined {
  return errors.value[field]?.[0]
}
</script>

<template>
  <main class="flex min-h-dvh flex-col items-center justify-center px-4 py-10">
    <div class="w-full max-w-sm">
      <h1 class="text-center text-3xl font-bold tracking-tight">
        Photo<span class="text-brick">Daily</span>
      </h1>

      <AppSpinner v-if="loading" label="Controllo l'invito…" />

      <div v-else-if="invalid" class="mt-8 text-center">
        <p class="font-bold">Invito non valido</p>
        <p class="mt-2 text-sm text-muted">
          Questo invito è scaduto o è già stato usato. Chiedi a chi te l'ha mandato di rifarlo.
        </p>
      </div>

      <template v-else-if="invite">
        <p class="mt-3 text-center text-muted">
          Ti hanno invitato a <strong class="text-ink">{{ invite.family.name }}</strong
          >.
        </p>
        <p class="mt-1 text-center text-sm text-muted">
          Il tuo accesso sarà <strong class="text-ink">{{ invite.email }}</strong
          >. Scegli una password per completare.
        </p>

        <form class="mt-8 space-y-4" novalidate @submit.prevent="submit">
          <div>
            <label for="name" class="mb-1 block text-sm font-bold">Come ti chiami</label>
            <input
              id="name"
              v-model="name"
              type="text"
              autocomplete="name"
              required
              class="w-full rounded-xl border-2 border-line bg-card px-3 py-2.5"
            />
            <p v-if="fieldError('name')" class="mt-1 text-sm text-brick">{{ fieldError('name') }}</p>
          </div>

          <div>
            <label for="password" class="mb-1 block text-sm font-bold">Password</label>
            <input
              id="password"
              v-model="password"
              type="password"
              autocomplete="new-password"
              required
              class="w-full rounded-xl border-2 border-line bg-card px-3 py-2.5"
            />
            <p v-if="fieldError('password')" class="mt-1 text-sm text-brick">
              {{ fieldError('password') }}
            </p>
          </div>

          <div>
            <label for="password_confirmation" class="mb-1 block text-sm font-bold">
              Ripeti la password
            </label>
            <input
              id="password_confirmation"
              v-model="passwordConfirmation"
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
            :disabled="busy"
          >
            {{ busy ? 'Attendi…' : 'Entra nel diario' }}
          </button>

          <p class="text-center text-xs text-muted">
            L'invito scade il {{ formatShortDate(invite.expires_at.slice(0, 10)) }}.
          </p>
        </form>
      </template>
    </div>
  </main>
</template>
