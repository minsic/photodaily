<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'

import { admin as adminApi } from '@/api'
import { ApiError } from '@/api/client'
import type { AccessMode, AdminFamily, AdminPlan } from '@/api/types'
import AppHeader from '@/components/AppHeader.vue'
import AppIcon from '@/components/AppIcon.vue'
import AppSpinner from '@/components/AppSpinner.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { useAuthStore } from '@/stores/auth'
import { useToastsStore } from '@/stores/toasts'
import { formatShortDate } from '@/utils/date'

/** Pannello del servizio: tutti i diari, piano, spazio, sospensione. */
const auth = useAuthStore()
const toasts = useToastsStore()
const router = useRouter()

const families = ref<AdminFamily[]>([])
const plans = ref<AdminPlan[]>([])
const loading = ref(true)
const loadError = ref<string | null>(null)
const busySlug = ref<string | null>(null)
const suspending = ref<AdminFamily | null>(null)

const accessLabels: Record<AccessMode, string> = {
  private: 'privato',
  password: 'con password',
  public: 'pubblico',
}

const totals = computed(() => ({
  families: families.value.length,
  suspended: families.value.filter((family) => family.suspended_at).length,
  photos: families.value.reduce((sum, family) => sum + family.photos_count, 0),
  storage: families.value.reduce((sum, family) => sum + family.storage_used_mb, 0),
}))

onMounted(async () => {
  try {
    ;[families.value, plans.value] = await Promise.all([adminApi.families(), adminApi.plans()])
  } catch {
    loadError.value = 'Non riesco a caricare i diari. Riprova.'
  } finally {
    loading.value = false
  }
})

function planLabel(plan: AdminPlan): string {
  const photos = plan.max_photos === null ? 'foto illimitate' : `${plan.max_photos} foto`
  const storage = plan.max_storage_mb === null ? 'spazio illimitato' : `${plan.max_storage_mb} MB`

  return `${plan.name} (${photos}, ${storage})`
}

function formatMb(mb: number): string {
  return mb >= 1024
    ? `${(mb / 1024).toLocaleString('it-IT', { maximumFractionDigits: 1 })} GB`
    : `${mb.toLocaleString('it-IT', { maximumFractionDigits: 1 })} MB`
}

function formatDay(iso: string | null): string {
  return iso ? formatShortDate(iso.slice(0, 10)) : 'mai'
}

async function update(family: AdminFamily, payload: { plan?: string; sospesa?: boolean }): Promise<void> {
  busySlug.value = family.slug

  try {
    const updated = await adminApi.updateFamily(family.slug, payload)
    families.value = families.value.map((item) => (item.slug === updated.slug ? updated : item))
    toasts.success(
      payload.plan !== undefined
        ? `${updated.name}: piano cambiato.`
        : updated.suspended_at
          ? `Il diario ${updated.name} è sospeso.`
          : `Il diario ${updated.name} è di nuovo attivo.`,
    )
  } catch (cause) {
    toasts.error(
      cause instanceof ApiError ? (cause.field('sospesa') ?? cause.field('plan') ?? cause.message) : 'Modifica non riuscita.',
    )
  } finally {
    busySlug.value = null
  }
}

function onPlanChange(family: AdminFamily, event: Event): void {
  void update(family, { plan: (event.target as HTMLSelectElement).value })
}

async function logout(): Promise<void> {
  await auth.logout()
  await router.replace({ name: 'home' })
}

async function confirmSuspend(): Promise<void> {
  if (suspending.value) {
    await update(suspending.value, { sospesa: true })
  }

  suspending.value = null
}
</script>

<template>
  <AppHeader>
    <template #right>
      <RouterLink
        :to="{ name: 'my-diary' }"
        class="flex items-center gap-1.5 rounded-xl border-2 border-line px-3 py-1.5 text-sm font-bold"
      >
        <AppIcon name="camera" class="size-4" />
        Il mio diario
      </RouterLink>
      <button
        type="button"
        class="flex items-center gap-1.5 rounded-xl border-2 border-line px-3 py-1.5 text-sm font-bold"
        @click="logout"
      >
        <AppIcon name="logout" class="size-4" />
        Esci
      </button>
    </template>
  </AppHeader>

  <main class="mx-auto max-w-3xl px-4 pb-20">
    <header class="mt-6">
      <h1 class="text-2xl font-bold tracking-tight">Pannello del servizio</h1>
      <p class="mt-1 text-sm text-muted">Tutti i diari PhotoDaily. Da qui non si vedono le foto.</p>
    </header>

    <AppSpinner v-if="loading" label="Carico i diari…" />
    <p v-else-if="loadError" class="mt-6 rounded-xl bg-brick/10 px-3 py-2 text-sm text-brick">{{ loadError }}</p>

    <template v-else>
      <dl class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="rounded-2xl bg-card px-4 py-3 card-shadow">
          <dt class="text-xs text-muted">Diari</dt>
          <dd class="text-xl font-bold">{{ totals.families }}</dd>
        </div>
        <div class="rounded-2xl bg-card px-4 py-3 card-shadow">
          <dt class="text-xs text-muted">Sospesi</dt>
          <dd class="text-xl font-bold">{{ totals.suspended }}</dd>
        </div>
        <div class="rounded-2xl bg-card px-4 py-3 card-shadow">
          <dt class="text-xs text-muted">Foto</dt>
          <dd class="text-xl font-bold">{{ totals.photos.toLocaleString('it-IT') }}</dd>
        </div>
        <div class="rounded-2xl bg-card px-4 py-3 card-shadow">
          <dt class="text-xs text-muted">Spazio</dt>
          <dd class="text-xl font-bold">{{ formatMb(totals.storage) }}</dd>
        </div>
      </dl>

      <ul class="mt-6 space-y-3">
        <li
          v-for="family in families"
          :key="family.slug"
          class="rounded-2xl bg-card px-4 py-4 card-shadow"
          :class="{ 'opacity-70': family.suspended_at }"
        >
          <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
            <h2 class="font-bold">
              {{ family.name }}
              <span v-if="family.suspended_at" class="ml-1 rounded-full bg-brick/15 px-2 py-0.5 text-xs text-brick">
                sospeso dal {{ formatDay(family.suspended_at) }}
              </span>
            </h2>
            <a :href="family.url" class="text-sm break-all text-azure underline" target="_blank" rel="noopener">
              {{ family.url.replace(/^https?:\/\//, '') }}
            </a>
          </div>

          <p class="mt-1 text-sm text-muted">
            {{ family.photos_count.toLocaleString('it-IT') }} foto · {{ formatMb(family.storage_used_mb) }} ·
            {{ family.users_count }} account ·
            {{ accessLabels[family.access_mode] }}
          </p>
          <p class="mt-0.5 text-sm text-muted">
            Creato il {{ formatDay(family.created_at) }} · ultimo caricamento {{ formatDay(family.last_upload_at) }}
          </p>
          <p v-if="family.admins.length" class="mt-0.5 text-sm break-all text-muted">
            Admin: {{ family.admins.join(', ') }}
          </p>

          <div class="mt-3 flex flex-wrap items-center gap-2">
            <label :for="`plan-${family.slug}`" class="sr-only">Piano di {{ family.name }}</label>
            <select
              :id="`plan-${family.slug}`"
              :value="family.plan ?? ''"
              :disabled="busySlug === family.slug"
              class="min-w-0 flex-1 rounded-xl border-2 border-line bg-card px-3 py-2 text-sm"
              @change="onPlanChange(family, $event)"
            >
              <option v-for="plan in plans" :key="plan.slug" :value="plan.slug">{{ planLabel(plan) }}</option>
            </select>

            <button
              v-if="family.suspended_at"
              type="button"
              class="rounded-xl border-2 border-line px-3 py-2 text-sm font-bold disabled:opacity-60"
              :disabled="busySlug === family.slug"
              @click="update(family, { sospesa: false })"
            >
              Riattiva
            </button>
            <button
              v-else-if="family.slug !== auth.family?.slug"
              type="button"
              class="rounded-xl border-2 border-brick px-3 py-2 text-sm font-bold text-brick disabled:opacity-60"
              :disabled="busySlug === family.slug"
              @click="suspending = family"
            >
              Sospendi
            </button>
          </div>
        </li>
      </ul>
    </template>
  </main>

  <ConfirmDialog
    v-if="suspending"
    :title="`Sospendere ${suspending.name}?`"
    message="Nessuno potrà più entrare, il diario pubblico sparisce e non partono promemoria né riepiloghi. Foto e account restano: puoi riattivarlo quando vuoi."
    confirm-label="Sospendi"
    :busy="busySlug === suspending.slug"
    @confirm="confirmSuspend"
    @cancel="suspending = null"
  />
</template>
