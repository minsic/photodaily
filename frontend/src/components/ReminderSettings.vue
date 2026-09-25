<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'

import { push as pushApi } from '@/api'
import { ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useToastsStore } from '@/stores/toasts'
import { pushSupport, vapidKeyToBytes } from '@/utils/push'

/**
 * Promemoria serale (Web Push). Il permesso del browser si chiede solo qui,
 * dopo aver spiegato a cosa serve, e mai all'apertura dell'app.
 */
const auth = useAuthStore()
const toasts = useToastsStore()

const support = pushSupport()
const permission = ref<NotificationPermission>('Notification' in window ? Notification.permission : 'default')
const publicKey = ref<string | null>(null)
/** Questo dispositivo riceve già le notifiche? */
const deviceSubscribed = ref(false)
const explaining = ref(false)
const busy = ref(false)
const orario = ref(auth.user?.promemoria.orario ?? '20:30')

const attivo = computed(() => auth.user?.promemoria.attivo ?? false)
const serverReady = computed(() => publicKey.value !== null)

async function registration(): Promise<ServiceWorkerRegistration | null> {
  return 'serviceWorker' in navigator ? navigator.serviceWorker.ready : null
}

onMounted(async () => {
  if (support !== 'ok') {
    return
  }

  publicKey.value = await pushApi
    .config()
    .then((config) => config.public_key)
    .catch(() => null)

  // getRegistration e non ready: senza service worker (sviluppo) ready non risponde mai.
  const current = await (await navigator.serviceWorker.getRegistration())?.pushManager.getSubscription()
  deviceSubscribed.value = Boolean(current)
})

async function savePreferences(preferences: { attivo?: boolean; orario?: string }): Promise<void> {
  const user = await pushApi.setPreferences(preferences)

  if (auth.user) {
    auth.user.promemoria = user.promemoria
  }
}

/** Dopo la spiegazione: permesso del browser, iscrizione del dispositivo, preferenza attiva. */
async function enable(): Promise<void> {
  busy.value = true

  try {
    permission.value = await Notification.requestPermission()

    if (permission.value !== 'granted') {
      explaining.value = false

      return
    }

    const reg = await registration()

    if (!reg || !publicKey.value) {
      throw new Error('push non disponibile')
    }

    const subscription =
      (await reg.pushManager.getSubscription()) ??
      (await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: vapidKeyToBytes(publicKey.value) }))

    await pushApi.subscribe(subscription)
    deviceSubscribed.value = true
    await savePreferences({ attivo: true, orario: orario.value })

    explaining.value = false
    toasts.success(`Fatto: se manca la foto, te lo ricordiamo alle ${orario.value}.`)
  } catch (cause) {
    toasts.error(cause instanceof ApiError ? cause.message : 'Non riesco ad attivare il promemoria. Riprova tra poco.')
  } finally {
    busy.value = false
  }
}

/** Spento per l'utente, e questo dispositivo smette di ricevere. */
async function disable(): Promise<void> {
  busy.value = true

  try {
    await savePreferences({ attivo: false })

    const subscription = await (await registration())?.pushManager.getSubscription()

    if (subscription) {
      await pushApi.unsubscribe(subscription.endpoint).catch(() => undefined)
      await subscription.unsubscribe().catch(() => undefined)
    }

    deviceSubscribed.value = false
    toasts.success('Promemoria spento.')
  } catch (cause) {
    toasts.error(cause instanceof ApiError ? cause.message : 'Non riesco a spegnere il promemoria.')
  } finally {
    busy.value = false
  }
}

async function saveTime(): Promise<void> {
  if (!/^\d{2}:\d{2}$/.test(orario.value)) {
    return
  }

  try {
    await savePreferences({ orario: orario.value })
    toasts.success(`Il promemoria arriverà alle ${orario.value}.`)
  } catch (cause) {
    toasts.error(cause instanceof ApiError ? cause.message : "Non riesco a salvare l'orario.")
  }
}
</script>

<template>
  <section>
    <h2 class="text-lg font-bold">Promemoria serale</h2>
    <p class="mt-1 text-sm text-muted">
      Se la sera manca ancora la foto del giorno, PhotoDaily te lo ricorda con una notifica. Una volta
      sola, e solo se nessuno della famiglia l'ha già caricata.
    </p>

    <p v-if="support === 'ios-da-installare'" class="mt-4 rounded-xl bg-card px-4 py-3 text-sm card-shadow">
      Su iPhone le notifiche arrivano solo se PhotoDaily è installata sulla schermata Home (serve iOS 16.4
      o successivo). Qui sotto trovi come fare: poi riapri l'app dall'icona e torna in questa pagina.
    </p>

    <p v-else-if="support === 'no'" class="mt-4 rounded-xl bg-card px-4 py-3 text-sm card-shadow">
      Questo browser non sa ricevere notifiche. Prova con Chrome, Edge o Firefox, oppure con Safari su
      iPhone dopo aver installato l'app.
    </p>

    <p v-else-if="publicKey === null && !attivo" class="mt-4 text-sm text-muted">
      Il promemoria non è ancora disponibile: riprova più tardi.
    </p>

    <p v-else-if="permission === 'denied'" class="mt-4 rounded-xl bg-card px-4 py-3 text-sm card-shadow">
      Le notifiche per PhotoDaily sono bloccate su questo dispositivo. Per riattivarle apri le
      impostazioni del sito (il lucchetto accanto all'indirizzo, oppure le impostazioni dell'app) e
      consenti le notifiche, poi torna qui.
    </p>

    <template v-else>
      <div v-if="!attivo && !explaining" class="mt-4">
        <button
          type="button"
          class="rounded-xl bg-brick px-4 py-2.5 font-bold text-white disabled:opacity-60"
          :disabled="!serverReady"
          @click="explaining = true"
        >
          Attiva il promemoria
        </button>
      </div>

      <div v-if="explaining" class="mt-4 rounded-2xl bg-card p-4 card-shadow">
        <p class="text-sm">
          Adesso il telefono ti chiederà se PhotoDaily può mandarti notifiche: rispondi
          <strong>Consenti</strong>. Ne arriverà al massimo una al giorno, all'orario che scegli.
        </p>

        <label for="reminder-time-first" class="mb-1 mt-4 block text-sm font-bold">Orario</label>
        <input
          id="reminder-time-first"
          v-model="orario"
          type="time"
          class="rounded-xl border-2 border-line bg-card px-3 py-2 text-ink"
        />

        <div class="mt-4 flex flex-wrap gap-3">
          <button
            type="button"
            class="rounded-xl bg-brick px-4 py-2.5 font-bold text-white disabled:opacity-60"
            :disabled="busy"
            @click="enable"
          >
            {{ busy ? 'Un attimo…' : 'Continua' }}
          </button>
          <button type="button" class="px-2 font-bold text-muted" @click="explaining = false">Non ora</button>
        </div>
      </div>

      <div v-if="attivo" class="mt-4 space-y-4">
        <div>
          <label for="reminder-time" class="mb-1 block text-sm font-bold">Ricordamelo alle</label>
          <input
            id="reminder-time"
            v-model="orario"
            type="time"
            class="rounded-xl border-2 border-line bg-card px-3 py-2 text-ink"
            @change="saveTime"
          />
        </div>

        <p v-if="!deviceSubscribed" class="text-sm text-muted">
          Il promemoria è attivo, ma su questo dispositivo non arriva ancora.
          <button type="button" class="font-bold text-brick underline" :disabled="busy" @click="enable">
            Ricevilo anche qui
          </button>
        </p>

        <button
          type="button"
          class="rounded-xl border-2 border-line px-4 py-2 font-bold disabled:opacity-60"
          :disabled="busy"
          @click="disable"
        >
          Spegni il promemoria
        </button>
      </div>
    </template>
  </section>
</template>
