<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'

import { family as familyApi, invites as invitesApi } from '@/api'
import { ApiError, type ValidationErrors } from '@/api/client'
import type { AccessMode, CreatedInvite, Invite } from '@/api/types'
import AppHeader from '@/components/AppHeader.vue'
import AppIcon from '@/components/AppIcon.vue'
import AppSpinner from '@/components/AppSpinner.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import CopyButton from '@/components/CopyButton.vue'
import FamilyProfileForm from '@/components/FamilyProfileForm.vue'
import { useAuthStore } from '@/stores/auth'
import { useToastsStore } from '@/stores/toasts'
import { formatShortDate } from '@/utils/date'

const auth = useAuthStore()
const toasts = useToastsStore()

const invites = ref<Invite[]>([])
const loadingInvites = ref(true)
const invitesError = ref<string | null>(null)

const email = ref('')
const inviting = ref(false)
const inviteErrors = ref<ValidationErrors>({})
const lastInvite = ref<CreatedInvite | null>(null)
const revoking = ref<Invite | null>(null)
const revokeBusy = ref(false)

const accessMode = ref<AccessMode>(auth.family?.access_mode ?? 'private')
const sharedPassword = ref('')
const savingAccess = ref(false)
const accessErrors = ref<ValidationErrors>({})
const confirmingAccess = ref(false)

const currentMode = computed<AccessMode>(() => auth.family?.access_mode ?? 'private')
const publicUrl = computed(() =>
  auth.family ? `${window.location.origin}/pub/${auth.family.slug}` : '',
)
const modeChanged = computed(() => accessMode.value !== currentMode.value)

const modes: Array<{ value: AccessMode; title: string; description: string; icon: 'lock' | 'globe' }> = [
  {
    value: 'private',
    title: 'Privato',
    description: 'Solo chi ha un account della famiglia può vedere le foto.',
    icon: 'lock',
  },
  {
    value: 'password',
    title: 'Con password',
    description: 'Chi ha il link vede le foto solo dopo aver inserito la password condivisa.',
    icon: 'lock',
  },
  {
    value: 'public',
    title: 'Pubblico',
    description: 'Chiunque abbia il link vede le foto, senza password.',
    icon: 'globe',
  },
]

onMounted(loadInvites)

async function loadInvites(): Promise<void> {
  loadingInvites.value = true
  invitesError.value = null

  try {
    invites.value = await invitesApi.list()
  } catch (cause) {
    invitesError.value = cause instanceof ApiError ? cause.message : 'Non riesco a caricare gli inviti.'
  } finally {
    loadingInvites.value = false
  }
}

async function invite(): Promise<void> {
  inviting.value = true
  inviteErrors.value = {}

  try {
    lastInvite.value = await invitesApi.create(email.value)
    email.value = ''
    toasts.success('Invito inviato.')
    await loadInvites()
  } catch (cause) {
    if (cause instanceof ApiError && cause.isValidation) {
      inviteErrors.value = cause.errors
    } else {
      toasts.error(cause instanceof ApiError ? cause.message : 'Non riesco a inviare l\'invito.')
    }
  } finally {
    inviting.value = false
  }
}

async function revoke(): Promise<void> {
  if (!revoking.value) {
    return
  }

  revokeBusy.value = true

  try {
    await invitesApi.revoke(revoking.value.id)

    if (lastInvite.value?.id === revoking.value.id) {
      lastInvite.value = null
    }

    toasts.success('Invito revocato.')
    await loadInvites()
  } catch (cause) {
    toasts.error(cause instanceof ApiError ? cause.message : 'Non riesco a revocare l\'invito.')
  } finally {
    revokeBusy.value = false
    revoking.value = null
  }
}

async function saveAccessMode(): Promise<void> {
  savingAccess.value = true
  accessErrors.value = {}

  try {
    const updated = await familyApi.setAccessMode(
      accessMode.value,
      accessMode.value === 'password' ? sharedPassword.value : undefined,
    )

    if (auth.user?.family) {
      auth.user.family.access_mode = updated.access_mode
    }

    sharedPassword.value = ''
    confirmingAccess.value = false
    toasts.success('Modalità di accesso aggiornata.')
  } catch (cause) {
    confirmingAccess.value = false

    if (cause instanceof ApiError && cause.isValidation) {
      accessErrors.value = cause.errors
    } else {
      toasts.error(cause instanceof ApiError ? cause.message : 'Non riesco a salvare la modalità.')
    }
  } finally {
    savingAccess.value = false
  }
}

/** Il salvataggio passa sempre da una conferma: cambia chi può vedere le foto. */
function askConfirmation(): void {
  accessErrors.value = {}

  if (accessMode.value === 'password' && sharedPassword.value.length < 8) {
    accessErrors.value = { password: ['La password condivisa deve avere almeno 8 caratteri.'] }

    return
  }

  confirmingAccess.value = true
}

const confirmationMessage = computed(() => {
  switch (accessMode.value) {
    case 'public':
      return 'Le foto saranno visibili a chiunque abbia il link, senza password. Le bozze restano private.'
    case 'password':
      return currentMode.value === 'password'
        ? 'La nuova password sostituisce quella attuale: i link già aperti con la vecchia smetteranno di funzionare.'
        : 'Chi ha il link potrà vedere le foto inserendo questa password.'
    default:
      return 'Il diario torna privato: i link condivisi smetteranno di funzionare subito.'
  }
})

function inviteError(field: string): string | undefined {
  return inviteErrors.value[field]?.[0]
}
</script>

<template>
  <AppHeader>
    <template #left>
      <RouterLink
        :to="{ name: 'timeline' }"
        class="flex items-center gap-1.5 rounded-lg py-1 font-bold text-ink"
      >
        <AppIcon name="back" class="size-5" />
        Timeline
      </RouterLink>
    </template>
  </AppHeader>

  <main class="mx-auto max-w-2xl space-y-10 px-4 pb-20">
    <header class="mt-6">
      <h1 class="text-2xl font-bold tracking-tight">Impostazioni famiglia</h1>
      <p class="mt-1 text-sm text-muted">
        {{ auth.family?.name }} · {{ auth.family?.photos_count ?? 0 }} foto ·
        {{ auth.family?.storage_used_mb ?? 0 }} MB
      </p>
    </header>

    <FamilyProfileForm />

    <section>
      <h2 class="text-lg font-bold">Membri e inviti</h2>
      <p class="mt-1 text-sm text-muted">
        Chi accetta l'invito entra come membro: può vedere e caricare foto, ma non cambiare queste
        impostazioni.
      </p>

      <form class="mt-4 flex flex-wrap items-start gap-2" novalidate @submit.prevent="invite">
        <div class="min-w-48 flex-1">
          <label for="email" class="sr-only">Email da invitare</label>
          <input
            id="email"
            v-model="email"
            type="email"
            required
            placeholder="nonna@example.com"
            class="w-full rounded-xl border-2 border-line bg-card px-3 py-2.5"
          />
          <p v-if="inviteError('email')" class="mt-1 text-sm text-brick">{{ inviteError('email') }}</p>
        </div>
        <button
          type="submit"
          class="flex items-center gap-1.5 rounded-xl bg-brick px-4 py-2.5 font-bold text-white disabled:opacity-60"
          :disabled="inviting"
        >
          <AppIcon name="mail" class="size-4" />
          {{ inviting ? 'Invio…' : 'Invita' }}
        </button>
      </form>

      <div v-if="lastInvite" class="mt-3 rounded-xl bg-card p-3 text-sm card-shadow">
        <p class="font-bold">Link per {{ lastInvite.email }}</p>
        <p class="mt-1 text-muted">
          L'invito è stato mandato via email. Puoi anche condividere il link a mano:
        </p>
        <div class="mt-2 flex items-center gap-2">
          <code class="flex-1 truncate rounded-lg bg-paper px-2 py-1.5 text-xs">{{ lastInvite.url }}</code>
          <CopyButton :value="lastInvite.url" />
        </div>
      </div>

      <AppSpinner v-if="loadingInvites" label="Carico gli inviti…" />

      <p v-else-if="invitesError" class="mt-4 rounded-xl bg-brick/10 px-4 py-3 text-sm text-brick">
        {{ invitesError }}
      </p>

      <p v-else-if="invites.length === 0" class="mt-4 text-sm text-muted">
        Nessun invito ancora mandato.
      </p>

      <ul v-else class="mt-4 divide-y divide-line rounded-xl bg-card card-shadow">
        <li v-for="item in invites" :key="item.id" class="flex items-center gap-3 px-4 py-3">
          <div class="min-w-0 flex-1">
            <p class="truncate font-bold">{{ item.email }}</p>
            <p class="text-xs text-muted">
              <span
                class="mr-1 rounded px-1.5 py-0.5 font-bold uppercase tracking-wide"
                :class="{
                  'bg-azure text-white': item.stato === 'pendente',
                  'bg-line text-ink': item.stato === 'scaduto',
                  'bg-ink text-paper': item.stato === 'accettato',
                }"
              >
                {{ item.stato }}
              </span>
              <template v-if="item.stato === 'pendente'">
                scade il {{ formatShortDate(item.expires_at.slice(0, 10)) }}
              </template>
              <template v-else-if="item.stato === 'accettato' && item.accepted_at">
                il {{ formatShortDate(item.accepted_at.slice(0, 10)) }}
              </template>
              <template v-else> il {{ formatShortDate(item.expires_at.slice(0, 10)) }} </template>
            </p>
          </div>

          <button
            v-if="item.stato !== 'accettato'"
            type="button"
            class="shrink-0 rounded-lg border-2 border-line px-2.5 py-1.5 text-sm font-bold text-muted hover:border-brick hover:text-brick"
            @click="revoking = item"
          >
            Revoca
          </button>
        </li>
      </ul>
    </section>

    <section>
      <h2 class="text-lg font-bold">Chi può vedere il diario</h2>
      <p class="mt-1 text-sm text-muted">
        Caricare, modificare ed eliminare foto resta sempre riservato ai membri con account.
      </p>

      <div class="mt-4 space-y-2">
        <label
          v-for="mode in modes"
          :key="mode.value"
          class="flex cursor-pointer items-start gap-3 rounded-xl border-2 p-3 transition"
          :class="accessMode === mode.value ? 'border-brick bg-card' : 'border-line hover:border-muted'"
        >
          <input v-model="accessMode" type="radio" :value="mode.value" class="sr-only" />
          <AppIcon
            :name="mode.icon"
            class="mt-0.5 size-5 shrink-0"
            :class="accessMode === mode.value ? 'text-brick' : 'text-muted'"
          />
          <span class="min-w-0">
            <span class="block font-bold">{{ mode.title }}</span>
            <span class="block text-sm text-muted">{{ mode.description }}</span>
          </span>
        </label>
      </div>

      <div v-if="accessMode === 'password'" class="mt-4">
        <label for="shared-password" class="mb-1 block text-sm font-bold">
          {{ currentMode === 'password' ? 'Nuova password condivisa' : 'Password condivisa' }}
        </label>
        <input
          id="shared-password"
          v-model="sharedPassword"
          type="text"
          autocomplete="off"
          minlength="8"
          placeholder="almeno 8 caratteri"
          class="w-full rounded-xl border-2 border-line bg-card px-3 py-2.5"
        />
        <p v-if="accessErrors.password" class="mt-1 text-sm text-brick">
          {{ accessErrors.password[0] }}
        </p>
        <p class="mt-1 text-xs text-muted">
          È una password da condividere con chi guarda: non è la password di un account.
        </p>
      </div>

      <p
        v-if="accessMode === 'public' && modeChanged"
        class="mt-4 flex gap-2 rounded-xl bg-brick/10 px-3 py-2.5 text-sm text-brick"
      >
        <AppIcon name="warning" class="mt-0.5 size-4 shrink-0" />
        <span>
          Con "pubblico" chiunque abbia il link vede tutte le foto pubblicate, senza password e senza
          account.
        </span>
      </p>

      <button
        type="button"
        class="mt-4 rounded-xl bg-brick px-4 py-2.5 font-bold text-white disabled:opacity-60"
        :disabled="savingAccess || (!modeChanged && accessMode !== 'password')"
        @click="askConfirmation"
      >
        {{ savingAccess ? 'Salvo…' : 'Salva modalità' }}
      </button>

      <div v-if="currentMode !== 'private'" class="mt-5 rounded-xl bg-card p-3 text-sm card-shadow">
        <p class="flex items-center gap-1.5 font-bold">
          <AppIcon name="link" class="size-4" />
          Link da condividere
        </p>
        <div class="mt-2 flex items-center gap-2">
          <code class="flex-1 truncate rounded-lg bg-paper px-2 py-1.5 text-xs">{{ publicUrl }}</code>
          <CopyButton :value="publicUrl" />
        </div>
        <p class="mt-2 text-xs text-muted">
          {{
            currentMode === 'password'
              ? 'Chi apre il link deve inserire la password condivisa.'
              : 'Chiunque apra il link vede le foto, senza password.'
          }}
        </p>
      </div>
    </section>
  </main>

  <ConfirmDialog
    v-if="confirmingAccess"
    title="Confermi la nuova modalità?"
    :message="confirmationMessage"
    confirm-label="Salva"
    :busy="savingAccess"
    @confirm="saveAccessMode"
    @cancel="confirmingAccess = false"
  />

  <ConfirmDialog
    v-if="revoking"
    title="Revocare l'invito?"
    :message="`Il link mandato a ${revoking.email} smetterà di funzionare.`"
    confirm-label="Revoca invito"
    :busy="revokeBusy"
    @confirm="revoke"
    @cancel="revoking = null"
  />
</template>
