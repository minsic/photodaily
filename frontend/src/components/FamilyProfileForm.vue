<script setup lang="ts">
import { computed, ref } from 'vue'

import { family as familyApi } from '@/api'
import { ApiError, type ValidationErrors } from '@/api/client'
import { useFamilyToday } from '@/composables/useFamilyToday'
import { useAuthStore } from '@/stores/auth'
import { useToastsStore } from '@/stores/toasts'
import { describeAge } from '@/utils/age'
import { DEFAULT_TIMEZONE } from '@/utils/date'

/** Di chi è il diario: nome e data di nascita, per l'età sotto le foto. Solo admin. */
const auth = useAuthStore()
const toasts = useToastsStore()

const name = ref(auth.family?.protagonist?.name ?? '')
const birthdate = ref(auth.family?.protagonist?.birthdate ?? '')
const timezone = ref(auth.family?.timezone ?? DEFAULT_TIMEZONE)
const saving = ref(false)
const errors = ref<ValidationErrors>({})

const { today: familyToday } = useFamilyToday()
const today = familyToday()

/** I fusi che il browser conosce, con quello attuale sempre presente. */
const timezones = (() => {
  const known = typeof Intl.supportedValuesOf === 'function' ? Intl.supportedValuesOf('timeZone') : []

  return [...new Set([timezone.value, DEFAULT_TIMEZONE, ...known])].sort()
})()
const preview = computed(() => describeAge(birthdate.value || null, today, name.value))

async function save(): Promise<void> {
  saving.value = true
  errors.value = {}

  try {
    const updated = await familyApi.update({
      protagonist_name: name.value.trim() || null,
      protagonist_birthdate: birthdate.value || null,
      timezone: timezone.value,
    })

    if (auth.user?.family) {
      auth.user.family.protagonist = updated.protagonist
      auth.user.family.timezone = updated.timezone
    }

    toasts.success('Salvato.')
  } catch (cause) {
    if (cause instanceof ApiError && cause.isValidation) {
      errors.value = cause.errors
    } else {
      toasts.error(cause instanceof ApiError ? cause.message : 'Non riesco a salvare.')
    }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <section>
    <h2 class="text-lg font-bold">Il diario di chi?</h2>
    <p class="mt-1 text-sm text-muted">
      Con nome e data di nascita, sotto ogni foto compare quanti anni, mesi e giorni aveva. Sono
      facoltativi.
    </p>

    <form class="mt-4 grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="save">
      <div>
        <label for="protagonist-name" class="mb-1 block text-sm font-bold">Nome</label>
        <input
          id="protagonist-name"
          v-model="name"
          type="text"
          maxlength="60"
          autocomplete="off"
          placeholder="Gio"
          class="w-full rounded-xl border-2 border-line bg-card px-3 py-2.5"
        />
        <p v-if="errors.protagonist_name" class="mt-1 text-sm text-brick">{{ errors.protagonist_name[0] }}</p>
      </div>

      <div>
        <label for="protagonist-birthdate" class="mb-1 block text-sm font-bold">Data di nascita</label>
        <input
          id="protagonist-birthdate"
          v-model="birthdate"
          type="date"
          :max="today"
          class="w-full rounded-xl border-2 border-line bg-card px-3 py-2 text-ink"
        />
        <p v-if="errors.protagonist_birthdate" class="mt-1 text-sm text-brick">
          {{ errors.protagonist_birthdate[0] }}
        </p>
      </div>

      <p v-if="preview" class="text-sm text-muted sm:col-span-2">Oggi: {{ preview }}.</p>

      <div class="sm:col-span-2">
        <label for="family-timezone" class="mb-1 block text-sm font-bold">Fuso orario</label>
        <select
          id="family-timezone"
          v-model="timezone"
          class="w-full rounded-xl border-2 border-line bg-card px-3 py-2.5 text-ink sm:w-auto"
        >
          <option v-for="zone in timezones" :key="zone" :value="zone">{{ zone.replace(/_/g, ' ') }}</option>
        </select>
        <p class="mt-1 text-sm text-muted">
          Decide quando comincia un giorno nuovo: il calendario e i promemoria seguono questo.
        </p>
        <p v-if="errors.timezone" class="mt-1 text-sm text-brick">{{ errors.timezone[0] }}</p>
      </div>

      <div class="sm:col-span-2">
        <button
          type="submit"
          class="rounded-xl bg-brick px-4 py-2.5 font-bold text-white disabled:opacity-60"
          :disabled="saving"
        >
          {{ saving ? 'Salvo…' : 'Salva' }}
        </button>
      </div>
    </form>
  </section>
</template>
