<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'

import { photos as photosApi } from '@/api'
import { ApiError } from '@/api/client'
import AppHeader from '@/components/AppHeader.vue'
import AppIcon from '@/components/AppIcon.vue'
import CaptionTextarea from '@/components/CaptionTextarea.vue'
import PhotoAge from '@/components/PhotoAge.vue'
import { useFamilyToday } from '@/composables/useFamilyToday'
import { useAuthStore } from '@/stores/auth'
import { useIncomingFilesStore } from '@/stores/incomingFiles'
import { usePhotosStore } from '@/stores/photos'
import { formatLongDate } from '@/utils/date'
import { readPhotoDate } from '@/utils/photoDate'
import { createPreview } from '@/utils/preview'
import {
  afterExclusion,
  groupByDay,
  initialChoice,
  plannedUploads,
  type DayChoice,
  type DayGroup,
  type PickedPhoto,
} from '@/utils/recovery'
import { isDebug } from '@/utils/debug'
import { describeSaving, shrinkForUpload } from '@/utils/shrinkImage'
import { requeueFailed, runQueue, type QueueTask } from '@/utils/uploadQueue'

/**
 * Recupera i giorni mancanti: si scelgono tante foto dalla galleria, si
 * raggruppano per giorno di scatto e se ne carica una per giorno.
 */
type Step = 'scegli' | 'leggo' | 'rivedi' | 'carico'

interface UploadTask extends QueueTask {
  date: string
  file: File
  caption: string
  /** Byte prima e dopo la riduzione nel browser (per la modalità debug). */
  originalBytes?: number
  bytes?: number
}

const auth = useAuthStore()
const photos = usePhotosStore()
const incoming = useIncomingFilesStore()
const { today, timezone } = useFamilyToday()

const step = ref<Step>('scegli')
const readCount = ref(0)
const files = new Map<string, File>()
const picked = ref<PickedPhoto[]>([])
const previews = reactive(new Map<string, string>())
const groups = ref<DayGroup[]>([])
const choices = reactive(new Map<string, DayChoice>())
const excluded = reactive(new Set<string>())
const tasks = ref<UploadTask[]>([])
const running = ref(false)

const planned = computed(() => plannedUploads(groups.value, choices))
const skippedFull = computed(
  () => groups.value.filter((group) => group.alreadyFull && !choices.get(group.date)?.include).length,
)
const uncertain = computed(() => picked.value.filter((photo) => !photo.certain).length)
const done = computed(() => tasks.value.filter((task) => task.status === 'fatto').length)
const failed = computed(() => tasks.value.filter((task) => task.status === 'errore').length)
const debug = isDebug()
const saving = computed(() => {
  const shrunk = tasks.value.filter((task) => task.bytes !== undefined)

  return shrunk.length === 0
    ? null
    : describeSaving({
        originalBytes: shrunk.reduce((sum, task) => sum + task.originalBytes!, 0),
        bytes: shrunk.reduce((sum, task) => sum + task.bytes!, 0),
      })
})
const percent = computed(() =>
  tasks.value.length === 0 ? 0 : Math.round(((done.value + failed.value) / tasks.value.length) * 100),
)

async function onPick(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement
  const chosen = Array.from(input.files ?? [])

  input.value = ''

  if (chosen.length > 0) {
    await read(chosen)
  }
}

/** Legge le date di scatto, cerca i giorni già pieni e prepara la revisione. */
async function read(chosen: File[]): Promise<void> {
  step.value = 'leggo'
  readCount.value = 0

  const list: PickedPhoto[] = []

  for (const [index, file] of chosen.entries()) {
    const id = `${index}-${file.name}-${file.size}`
    const { date, certain } = await readPhotoDate(file, timezone())

    files.set(id, file)
    list.push({ id, date, certain })
    readCount.value++
  }

  const years = [...new Set(list.map((photo) => Number(photo.date.slice(0, 4))))]
  const calendars = await Promise.all(years.map((year) => photosApi.calendar(year).catch(() => null)))
  const full = new Set(calendars.flatMap((calendar) => calendar?.giorni.map((day) => day.data) ?? []))

  picked.value = list
  groups.value = groupByDay(list, full, today())
  choices.clear()
  excluded.clear()

  for (const group of groups.value) {
    choices.set(group.date, initialChoice(group))
  }

  step.value = 'rivedi'
  void makePreviews(list)
}

/** Una alla volta: sono le miniature per la revisione, non devono pesare. */
async function makePreviews(list: PickedPhoto[]): Promise<void> {
  for (const photo of list) {
    const file = files.get(photo.id)

    if (file && !previews.has(photo.id)) {
      previews.set(photo.id, await createPreview(file))
    }
  }
}

function choose(group: DayGroup, photo: PickedPhoto): void {
  if (!excluded.has(photo.id)) {
    choices.set(group.date, { ...choices.get(group.date)!, chosen: photo.id })
  }
}

function toggleExcluded(group: DayGroup, photo: PickedPhoto): void {
  if (excluded.has(photo.id)) {
    excluded.delete(photo.id)
  } else {
    excluded.add(photo.id)
  }

  const next = afterExclusion(group, choices.get(group.date)!, excluded)
  choices.set(group.date, next.chosen === null ? { ...next, include: false } : next)
}

function setInclude(group: DayGroup, include: boolean): void {
  choices.set(group.date, { ...choices.get(group.date)!, include })
}

function setCaption(group: DayGroup, caption: string): void {
  choices.set(group.date, { ...choices.get(group.date)!, caption })
}

function describeError(cause: unknown): string {
  if (cause instanceof ApiError && cause.status >= 500) {
    return "Il server non ce l'ha fatta: riprova tra poco."
  }

  if (cause instanceof ApiError && cause.status > 0) {
    return cause.field('image') ?? cause.field('data') ?? cause.message
  }

  return 'Connessione assente: riprova tra poco.'
}

async function startUpload(): Promise<void> {
  tasks.value = planned.value.map((upload) => ({
    id: upload.photoId,
    status: 'attesa',
    error: null,
    date: upload.date,
    file: files.get(upload.photoId)!,
    caption: upload.caption,
  }))
  step.value = 'carico'

  await upload()
}

/** Tre invii alla volta; quelli già arrivati restano anche se si chiude la pagina. */
async function upload(): Promise<void> {
  running.value = true

  try {
    await runQueue(
      tasks.value,
      async (task) => {
        // La riduzione va una foto alla volta (la serializza shrinkForUpload),
        // gli invii restano tre in parallelo.
        const shrunk = await shrinkForUpload(task.file)

        task.originalBytes = shrunk.originalBytes
        task.bytes = shrunk.bytes

        await photosApi.create(shrunk.file, {
          data: task.date,
          // La didascalia è già l'HTML minimale prodotto da CaptionTextarea.
          didascalia: task.caption || null,
          data_speciale: false,
          is_draft: false,
        })
      },
      3,
      describeError,
    )
  } finally {
    running.value = false
    photos.invalidate()
    await photos.loadYears().catch(() => undefined)
  }
}

async function retryFailed(): Promise<void> {
  if (requeueFailed(tasks.value) > 0) {
    await upload()
  }
}

function previewOf(task: UploadTask): string | undefined {
  return previews.get(task.id)
}

/** Chiudendo la pagina a metà si perdono solo quelle non ancora partite: lo si dice. */
function warnBeforeLeaving(event: BeforeUnloadEvent): void {
  if (running.value) {
    event.preventDefault()
  }
}

onMounted(() => {
  window.addEventListener('beforeunload', warnBeforeLeaving)

  // Foto arrivate dalla condivisione o dal pulsante "Carica" con più file.
  const waiting = incoming.take()

  if (waiting.length > 0) {
    void read(waiting)
  }
})

onBeforeUnmount(() => {
  window.removeEventListener('beforeunload', warnBeforeLeaving)

  for (const url of previews.values()) {
    URL.revokeObjectURL(url)
  }
})
</script>

<template>
  <AppHeader>
    <template #left>
      <RouterLink
        :to="{ name: 'calendar' }"
        class="flex items-center gap-1.5 rounded-lg py-1 font-bold text-ink"
      >
        <AppIcon name="back" class="size-5" />
        Calendario
      </RouterLink>
    </template>
  </AppHeader>

  <main class="mx-auto max-w-2xl px-4 pb-32">
    <h1 class="mt-6 text-2xl font-bold tracking-tight">Recupera i giorni mancanti</h1>

    <section v-if="step === 'scegli'" class="mt-4">
      <p class="text-muted">
        Scegli dalla galleria le foto dei giorni che mancano, anche tante insieme. Le mettiamo in
        ordine per giorno e per ognuno ne carichi una: poi decidi tu quale.
      </p>

      <label
        class="mt-6 flex cursor-pointer items-center justify-center gap-2 rounded-2xl bg-brick px-4 py-4 text-lg font-bold text-white"
      >
        <AppIcon name="camera" class="size-6" />
        Scegli le foto
        <input type="file" accept="image/*" multiple class="sr-only" @change="onPick" />
      </label>
    </section>

    <section v-else-if="step === 'leggo'" class="mt-10 text-center text-muted" role="status">
      <p class="font-bold text-ink">Guardo quando sono state scattate…</p>
      <p class="mt-1 text-sm">{{ readCount }} foto lette</p>
    </section>

    <section v-else-if="step === 'rivedi'" class="mt-4">
      <p class="text-muted">
        {{ picked.length }} foto per {{ groups.length }} {{ groups.length === 1 ? 'giorno' : 'giorni' }}.
        Tocca una foto per sceglierla al posto della prima.
      </p>
      <p v-if="skippedFull > 0" class="mt-2 text-sm text-muted">
        {{ skippedFull }} {{ skippedFull === 1 ? 'giorno ha' : 'giorni hanno' }} già la loro foto: li
        lasciamo com'erano, ma puoi caricarli lo stesso.
      </p>
      <p v-if="uncertain > 0" class="mt-2 text-sm text-muted">
        Per {{ uncertain }} {{ uncertain === 1 ? 'foto' : 'foto' }} non c'era la data di scatto: abbiamo
        usato la data del file, controlla che il giorno sia giusto.
      </p>

      <ol class="mt-6 space-y-6">
        <li
          v-for="group in groups"
          :key="group.date"
          class="rounded-2xl bg-card p-4 card-shadow"
          :class="{ 'opacity-60': !choices.get(group.date)?.include }"
        >
          <div class="flex flex-wrap items-baseline justify-between gap-2">
            <div>
              <h2 class="font-bold">{{ formatLongDate(group.date) }}</h2>
              <PhotoAge :date="group.date" :protagonist="auth.family?.protagonist" />
            </div>

            <span v-if="group.future" class="text-xs font-bold text-brick">
              Data nel futuro: forse l'orologio della fotocamera era sbagliato
            </span>
            <label v-else-if="group.alreadyFull" class="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                :checked="choices.get(group.date)?.include"
                :disabled="!choices.get(group.date)?.chosen"
                @change="setInclude(group, ($event.target as HTMLInputElement).checked)"
              />
              Ha già una foto · carica lo stesso
            </label>
            <label v-else class="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                :checked="choices.get(group.date)?.include"
                :disabled="!choices.get(group.date)?.chosen"
                @change="setInclude(group, ($event.target as HTMLInputElement).checked)"
              />
              Carica questo giorno
            </label>
          </div>

          <ul class="mt-3 flex gap-2 overflow-x-auto pb-1">
            <li v-for="photo in group.photos" :key="photo.id" class="relative shrink-0">
              <button
                type="button"
                class="block size-24 overflow-hidden rounded-xl bg-line"
                :class="{
                  'ring-4 ring-brick': choices.get(group.date)?.chosen === photo.id,
                  'opacity-30': excluded.has(photo.id),
                }"
                :aria-pressed="choices.get(group.date)?.chosen === photo.id"
                :aria-label="`Scegli questa foto per il ${formatLongDate(group.date)}`"
                @click="choose(group, photo)"
              >
                <img
                  v-if="previews.get(photo.id)"
                  :src="previews.get(photo.id)"
                  alt=""
                  class="size-full object-cover"
                />
              </button>
              <button
                type="button"
                class="absolute -right-1 -top-1 flex size-6 items-center justify-center rounded-full bg-ink text-paper"
                :aria-label="excluded.has(photo.id) ? 'Rimetti questa foto' : 'Escludi questa foto'"
                @click="toggleExcluded(group, photo)"
              >
                <AppIcon :name="excluded.has(photo.id) ? 'plus' : 'close'" class="size-3.5" />
              </button>
              <span
                v-if="!photo.certain"
                class="absolute bottom-1 left-1 rounded bg-ink/70 px-1 text-[10px] font-bold text-paper"
              >
                data incerta
              </span>
            </li>
          </ul>

          <div v-if="choices.get(group.date)?.include" class="mt-3">
            <CaptionTextarea
              :model-value="choices.get(group.date)?.caption ?? ''"
              placeholder="Didascalia (facoltativa)"
              @update:model-value="(html) => setCaption(group, html)"
            />
          </div>
        </li>
      </ol>

      <div class="fixed inset-x-0 bottom-0 z-20 border-t border-line bg-paper/95 px-4 py-3 backdrop-blur">
        <div class="mx-auto flex max-w-2xl items-center justify-between gap-3">
          <label class="cursor-pointer text-sm font-bold text-muted underline">
            Scegline altre
            <input type="file" accept="image/*" multiple class="sr-only" @change="onPick" />
          </label>
          <button
            type="button"
            class="rounded-xl bg-brick px-5 py-3 font-bold text-white disabled:opacity-50"
            :disabled="planned.length === 0"
            @click="startUpload"
          >
            Carica {{ planned.length }} {{ planned.length === 1 ? 'foto' : 'foto' }}
          </button>
        </div>
      </div>
    </section>

    <section v-else class="mt-6">
      <div
        class="h-3 overflow-hidden rounded-full bg-line"
        role="progressbar"
        :aria-valuenow="percent"
        aria-valuemin="0"
        aria-valuemax="100"
        aria-label="Avanzamento del caricamento"
      >
        <div class="h-full rounded-full bg-brick transition-all" :style="{ width: `${percent}%` }" />
      </div>

      <p class="mt-3 font-bold" role="status">
        <template v-if="running">Carico… {{ done }} di {{ tasks.length }}</template>
        <template v-else-if="failed === 0">
          Fatto! {{ done }} {{ done === 1 ? 'giorno ha' : 'giorni hanno' }} ritrovato la loro foto.
        </template>
        <template v-else>
          {{ done }} caricate, {{ failed }} non ce l'hanno fatta. Le altre sono già al sicuro.
        </template>
      </p>
      <p v-if="running" class="mt-1 text-sm text-muted">
        Puoi restare qui: quelle già arrivate restano anche se chiudi la pagina.
      </p>

      <div v-if="!running" class="mt-4 flex flex-wrap gap-3">
        <button
          v-if="failed > 0"
          type="button"
          class="rounded-xl bg-brick px-4 py-2.5 font-bold text-white"
          @click="retryFailed"
        >
          Riprova le {{ failed }} rimaste
        </button>
        <RouterLink :to="{ name: 'calendar' }" class="rounded-xl border-2 border-line px-4 py-2.5 font-bold">
          Torna al calendario
        </RouterLink>
      </div>

      <p v-if="debug && saving" class="mt-4 text-xs text-muted">Debug · ridotte nel browser: {{ saving }}</p>

      <ul class="mt-6 grid grid-cols-3 gap-2 sm:grid-cols-5">
        <li v-for="task in tasks" :key="task.id" class="relative">
          <img
            v-if="previewOf(task)"
            :src="previewOf(task)"
            alt=""
            class="aspect-square w-full rounded-xl object-cover"
            :class="{ 'opacity-40': task.status !== 'fatto' }"
          />
          <span
            class="absolute inset-x-1 bottom-1 rounded bg-ink/70 px-1 text-center text-[10px] font-bold text-paper"
          >
            {{
              task.status === 'fatto'
                ? '✓ ' + task.date.slice(8) + '/' + task.date.slice(5, 7)
                : task.status === 'errore'
                  ? 'da riprovare'
                  : task.status === 'invio'
                    ? 'invio…'
                    : 'in attesa'
            }}
          </span>
          <p v-if="task.error" class="mt-1 text-[11px] text-brick">{{ task.error }}</p>
        </li>
      </ul>
    </section>
  </main>
</template>
