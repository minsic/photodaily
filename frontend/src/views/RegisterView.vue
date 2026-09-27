<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'

import { auth as authApi } from '@/api'
import { ApiError, type ValidationErrors } from '@/api/client'
import LegalLinks from '@/components/LegalLinks.vue'
import AppLogo from '@/components/logo/AppLogo.vue'
import { DEFAULT_TIMEZONE } from '@/utils/date'
import { isValidSlug, slugify } from '@/utils/slug'

/**
 * Nuovo diario dall'indirizzo principale. Finita la registrazione si passa al
 * sottodominio appena creato, dove /entra apre la sessione.
 */
const familyName = ref('')
const slug = ref('')
const name = ref('')
const email = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const terms = ref(false)

const busy = ref(false)
const errors = ref<ValidationErrors>({})
const error = ref<string | null>(null)

/** Finché non lo si tocca, l'indirizzo segue il nome del diario. */
const slugEdited = ref(false)

watch(familyName, (value) => {
  if (!slugEdited.value) {
    slug.value = slugify(value)
  }
})

function onSlugInput(event: Event): void {
  slugEdited.value = true
  slug.value = (event.target as HTMLInputElement).value.toLowerCase().replace(/[^a-z0-9-]/g, '')
}

/** L'host principale è quello da cui si sta registrando. */
const preview = computed(() => `${slug.value || 'nome'}.${window.location.host}`)
const slugLooksWrong = computed(() => slug.value !== '' && !isValidSlug(slug.value))

async function submit(): Promise<void> {
  busy.value = true
  errors.value = {}
  error.value = null

  try {
    const result = await authApi.register({
      family_name: familyName.value.trim(),
      slug: slug.value,
      name: name.value.trim(),
      email: email.value.trim(),
      password: password.value,
      password_confirmation: passwordConfirmation.value,
      terms: terms.value,
      timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || DEFAULT_TIMEZONE,
    })

    window.location.assign(result.handoff_url)
  } catch (cause) {
    if (cause instanceof ApiError && cause.isValidation) {
      errors.value = cause.errors
    } else if (cause instanceof ApiError && cause.status === 429) {
      error.value = 'Troppe registrazioni da questa connessione: riprova più tardi.'
    } else {
      error.value = cause instanceof ApiError ? cause.message : 'Non riesco a creare il diario. Riprova.'
    }

    busy.value = false
  }
}

function fieldError(field: string): string | undefined {
  return errors.value[field]?.[0]
}
</script>

<template>
  <main class="flex min-h-dvh flex-col items-center px-4 py-10">
    <div class="w-full max-w-sm">
      <h1 class="flex justify-center text-ink">
        <RouterLink :to="{ name: 'home' }" aria-label="PhotoDaily">
          <AppLogo variant="verticale" class="h-24" />
        </RouterLink>
      </h1>
      <h2 class="mt-6 text-center text-xl font-bold">Crea il tuo diario</h2>
      <p class="mt-1 text-center text-sm text-muted">Gratis fino a 100 foto. Il diario è privato finché non decidi tu.</p>

      <form class="mt-8 space-y-4" novalidate @submit.prevent="submit">
        <div>
          <label for="family_name" class="mb-1 block text-sm font-bold">Nome del diario</label>
          <input
            id="family_name"
            v-model="familyName"
            type="text"
            placeholder="Famiglia Rossi"
            required
            class="w-full rounded-xl border-2 border-line bg-card px-3 py-2.5"
          />
          <p v-if="fieldError('family_name')" class="mt-1 text-sm text-brick">{{ fieldError('family_name') }}</p>
        </div>

        <div>
          <label for="slug" class="mb-1 block text-sm font-bold">Indirizzo</label>
          <input
            id="slug"
            :value="slug"
            type="text"
            inputmode="url"
            autocapitalize="none"
            autocorrect="off"
            spellcheck="false"
            maxlength="30"
            required
            aria-describedby="slug-preview"
            class="w-full rounded-xl border-2 border-line bg-card px-3 py-2.5"
            @input="onSlugInput"
          />
          <p id="slug-preview" class="mt-1 text-sm break-all text-muted">{{ preview }}</p>
          <p v-if="fieldError('slug')" class="mt-1 text-sm text-brick">{{ fieldError('slug') }}</p>
          <p v-else-if="slugLooksWrong" class="mt-1 text-sm text-brick">
            Da 3 a 30 caratteri fra minuscole, cifre e trattini, senza trattini all'inizio o alla fine.
          </p>
        </div>

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
          <label for="email" class="mb-1 block text-sm font-bold">Email</label>
          <input
            id="email"
            v-model="email"
            type="email"
            autocomplete="email"
            required
            class="w-full rounded-xl border-2 border-line bg-card px-3 py-2.5"
          />
          <p v-if="fieldError('email')" class="mt-1 text-sm text-brick">{{ fieldError('email') }}</p>
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
          <p v-if="fieldError('password')" class="mt-1 text-sm text-brick">{{ fieldError('password') }}</p>
        </div>

        <div>
          <label for="password_confirmation" class="mb-1 block text-sm font-bold">Ripeti la password</label>
          <input
            id="password_confirmation"
            v-model="passwordConfirmation"
            type="password"
            autocomplete="new-password"
            required
            class="w-full rounded-xl border-2 border-line bg-card px-3 py-2.5"
          />
        </div>

        <div>
          <label class="flex items-start gap-2 text-sm">
            <input v-model="terms" type="checkbox" class="mt-0.5 size-4 shrink-0 accent-brick" />
            <span>
              Accetto i <RouterLink :to="{ name: 'terms' }" class="underline" target="_blank">termini</RouterLink> e
              ho letto l'<RouterLink :to="{ name: 'privacy' }" class="underline" target="_blank">informativa sulla
              privacy</RouterLink>.
            </span>
          </label>
          <p v-if="fieldError('terms')" class="mt-1 text-sm text-brick">{{ fieldError('terms') }}</p>
        </div>

        <p v-if="error" class="rounded-xl bg-brick/10 px-3 py-2 text-sm text-brick">{{ error }}</p>

        <button
          type="submit"
          class="w-full rounded-xl bg-brick px-4 py-3 font-bold text-white disabled:opacity-60"
          :disabled="busy"
        >
          {{ busy ? 'Creo il diario…' : 'Crea il diario' }}
        </button>
      </form>

      <p class="mt-6 text-center text-sm">
        Hai già un diario?
        <RouterLink :to="{ name: 'login' }" class="font-bold text-muted underline hover:text-ink">Entra</RouterLink>
      </p>
    </div>

    <footer class="mt-10">
      <LegalLinks />
    </footer>
  </main>
</template>
