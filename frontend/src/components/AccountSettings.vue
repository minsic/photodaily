<script setup lang="ts">
import { ref } from 'vue'

import { auth as authApi } from '@/api'
import { ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useToastsStore } from '@/stores/toasts'

/**
 * Il proprio account: nome, email (confermata col link al nuovo indirizzo),
 * password, dispositivi collegati.
 */
const auth = useAuthStore()
const toasts = useToastsStore()

const name = ref(auth.user?.name ?? '')
const savingName = ref(false)
const nameError = ref<string | null>(null)

const current = ref('')
const password = ref('')
const confirmation = ref('')
const savingPassword = ref(false)
const passwordErrors = ref<Record<string, string>>({})

const closing = ref(false)

const newEmail = ref('')
const emailPassword = ref('')
const savingEmail = ref(false)
const emailErrors = ref<Record<string, string>>({})

function sessionsText(closed: number): string {
  if (closed === 0) {
    return 'non eri collegato da altri dispositivi'
  }

  return closed === 1 ? 'chiusa la sessione su un altro dispositivo' : `chiuse le sessioni su altri ${closed} dispositivi`
}

async function saveName(): Promise<void> {
  savingName.value = true
  nameError.value = null

  try {
    const user = await authApi.updateProfile(name.value)

    if (auth.user) {
      auth.user.name = user.name
    }

    name.value = user.name
    toasts.success('Nome aggiornato.')
  } catch (cause) {
    nameError.value = cause instanceof ApiError ? (cause.field('name') ?? cause.message) : 'Non riesco a salvare il nome.'
  } finally {
    savingName.value = false
  }
}

async function savePassword(): Promise<void> {
  if (password.value !== confirmation.value) {
    passwordErrors.value = { password: 'Le due password nuove non coincidono.' }

    return
  }

  savingPassword.value = true
  passwordErrors.value = {}

  try {
    const { sessioni_chiuse: closed } = await authApi.changePassword({
      password_attuale: current.value,
      password: password.value,
      password_confirmation: confirmation.value,
    })

    current.value = ''
    password.value = ''
    confirmation.value = ''
    toasts.success(`Password cambiata: ${sessionsText(closed)}.`)
  } catch (cause) {
    if (cause instanceof ApiError && cause.status === 429) {
      passwordErrors.value = { password_attuale: 'Troppi tentativi: riprova tra qualche minuto.' }
    } else if (cause instanceof ApiError) {
      passwordErrors.value = {
        password_attuale: cause.field('password_attuale') ?? '',
        password: cause.field('password') ?? '',
      }
    } else {
      passwordErrors.value = { password: 'Non riesco a cambiare la password. Riprova.' }
    }
  } finally {
    savingPassword.value = false
  }
}

async function requestEmail(): Promise<void> {
  savingEmail.value = true
  emailErrors.value = {}

  try {
    const user = await authApi.requestEmailChange({ email: newEmail.value.trim(), password_attuale: emailPassword.value })

    if (auth.user) {
      auth.user.email_in_attesa = user.email_in_attesa
    }

    newEmail.value = ''
    emailPassword.value = ''
  } catch (cause) {
    if (cause instanceof ApiError && cause.status === 429) {
      emailErrors.value = { password_attuale: 'Troppi tentativi: riprova tra qualche minuto.' }
    } else if (cause instanceof ApiError) {
      emailErrors.value = {
        email: cause.field('email') ?? '',
        password_attuale: cause.field('password_attuale') ?? '',
      }
    } else {
      emailErrors.value = { email: 'Non riesco a mandare la richiesta. Riprova.' }
    }
  } finally {
    savingEmail.value = false
  }
}

async function cancelEmail(): Promise<void> {
  try {
    const user = await authApi.cancelEmailChange()

    if (auth.user) {
      auth.user.email_in_attesa = user.email_in_attesa
    }

    toasts.success('Richiesta annullata: resta la tua email di prima.')
  } catch {
    toasts.error('Non riesco ad annullare la richiesta. Riprova.')
  }
}

async function closeOthers(): Promise<void> {
  closing.value = true

  try {
    const { sessioni_chiuse: closed } = await authApi.closeOtherSessions()
    toasts.success(closed === 0 ? 'Non eri collegato da altri dispositivi.' : `Fatto: ${sessionsText(closed)}.`)
  } catch {
    toasts.error('Non riesco a chiudere le altre sessioni. Riprova.')
  } finally {
    closing.value = false
  }
}
</script>

<template>
  <section class="space-y-8">
    <form class="space-y-2" novalidate @submit.prevent="saveName">
      <h2 class="text-lg font-bold">Il tuo nome</h2>
      <p class="text-sm text-muted">È quello che la famiglia vede accanto ai cuori e negli inviti.</p>
      <div class="flex gap-2">
        <label for="profile-name" class="sr-only">Nome</label>
        <input
          id="profile-name"
          v-model="name"
          type="text"
          autocomplete="name"
          maxlength="255"
          required
          class="min-w-0 flex-1 rounded-xl border-2 border-line bg-card px-3 py-2.5"
        />
        <button
          type="submit"
          class="rounded-xl bg-brick px-4 py-2.5 font-bold text-white disabled:opacity-60"
          :disabled="savingName || name.trim() === '' || name.trim() === auth.user?.name"
        >
          Salva
        </button>
      </div>
      <p v-if="nameError" class="text-sm text-brick">{{ nameError }}</p>
    </form>

    <div class="space-y-3">
      <h2 class="text-lg font-bold">Email</h2>
      <p class="text-sm text-muted">
        Entri con <strong class="text-ink">{{ auth.user?.email }}</strong>. Per cambiarla ti mandiamo un link al
        nuovo indirizzo: finché non lo apri resta questa.
      </p>

      <div v-if="auth.user?.email_in_attesa" class="rounded-xl bg-card px-4 py-3 text-sm card-shadow" role="status">
        <p>
          Abbiamo mandato un link a <strong>{{ auth.user.email_in_attesa }}</strong>: aprilo per confermare. Vale 24
          ore.
        </p>
        <button type="button" class="mt-2 font-bold text-brick underline" @click="cancelEmail">Annulla il cambio</button>
      </div>

      <form v-else class="space-y-3" novalidate @submit.prevent="requestEmail">
        <div>
          <label for="new-email" class="mb-1 block text-sm font-bold">Nuova email</label>
          <input
            id="new-email"
            v-model="newEmail"
            type="email"
            autocomplete="email"
            required
            class="w-full rounded-xl border-2 border-line bg-card px-3 py-2.5"
          />
          <p v-if="emailErrors.email" class="mt-1 text-sm text-brick">{{ emailErrors.email }}</p>
        </div>
        <div>
          <label for="email-password" class="mb-1 block text-sm font-bold">Password attuale</label>
          <input
            id="email-password"
            v-model="emailPassword"
            type="password"
            autocomplete="current-password"
            required
            class="w-full rounded-xl border-2 border-line bg-card px-3 py-2.5"
          />
          <p v-if="emailErrors.password_attuale" class="mt-1 text-sm text-brick">{{ emailErrors.password_attuale }}</p>
        </div>
        <button
          type="submit"
          class="w-full rounded-xl border-2 border-line px-4 py-2.5 font-bold disabled:opacity-60"
          :disabled="savingEmail || !newEmail || !emailPassword"
        >
          {{ savingEmail ? 'Invio…' : 'Mandami il link di conferma' }}
        </button>
      </form>
    </div>

    <form class="space-y-3" novalidate @submit.prevent="savePassword">
      <h2 class="text-lg font-bold">Password</h2>
      <p class="text-sm text-muted">Dopo il cambio si esce da tutti gli altri dispositivi, non da questo.</p>

      <div>
        <label for="current-password" class="mb-1 block text-sm font-bold">Password attuale</label>
        <input
          id="current-password"
          v-model="current"
          type="password"
          autocomplete="current-password"
          required
          class="w-full rounded-xl border-2 border-line bg-card px-3 py-2.5"
        />
        <p v-if="passwordErrors.password_attuale" class="mt-1 text-sm text-brick">{{ passwordErrors.password_attuale }}</p>
      </div>
      <div>
        <label for="new-password" class="mb-1 block text-sm font-bold">Nuova password</label>
        <input
          id="new-password"
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
        <label for="new-password-confirmation" class="mb-1 block text-sm font-bold">Ripeti la nuova password</label>
        <input
          id="new-password-confirmation"
          v-model="confirmation"
          type="password"
          autocomplete="new-password"
          required
          class="w-full rounded-xl border-2 border-line bg-card px-3 py-2.5"
        />
        <p v-if="passwordErrors.password" class="mt-1 text-sm text-brick">{{ passwordErrors.password }}</p>
      </div>
      <button
        type="submit"
        class="w-full rounded-xl bg-brick px-4 py-2.5 font-bold text-white disabled:opacity-60"
        :disabled="savingPassword || !current || !password"
      >
        {{ savingPassword ? 'Salvo…' : 'Cambia password' }}
      </button>
    </form>

    <div class="space-y-2">
      <h2 class="text-lg font-bold">Dispositivi</h2>
      <p class="text-sm text-muted">
        Hai perso il telefono o sei entrato da un computer non tuo? Chiudi le sessioni ovunque tranne qui.
      </p>
      <button
        type="button"
        class="w-full rounded-xl border-2 border-line px-4 py-2.5 font-bold disabled:opacity-60"
        :disabled="closing"
        @click="closeOthers"
      >
        Esci dagli altri dispositivi
      </button>
    </div>
  </section>
</template>
