import { defineStore } from 'pinia'
import { ref } from 'vue'

import { publicDiary as publicApi } from '@/api'
import { ApiError } from '@/api/client'
import type { Photo, YearSummary } from '@/api/types'
import { signedUrlsAreStale } from '@/utils/signedUrls'

/**
 * Diario di una famiglia in sola lettura.
 *
 * - `password`: serve la password condivisa (401 dall'API).
 * - `unavailable`: famiglia privata o slug inesistente (404): non si dice quale.
 *
 * Il token di sola lettura è separato da quello dell'utente e vive per slug,
 * così si possono aprire diari diversi senza interferenze.
 */
type State = 'loading' | 'password' | 'unavailable' | 'ready'

const tokenKey = (slug: string) => `photodaily.read-token.${slug}`

export const usePublicDiaryStore = defineStore('public-diary', () => {
  const slug = ref('')
  const token = ref<string | null>(null)
  const state = ref<State>('loading')

  const items = ref<Photo[]>([])
  const years = ref<YearSummary[]>([])
  const anno = ref<number | null>(null)
  const soloSpeciali = ref(false)

  const loading = ref(false)
  const error = ref<string | null>(null)
  /** Quando è stato riempito `items`: gli URL delle immagini scadono dopo un'ora. */
  const loadedAt = ref<number | null>(null)
  const unlocking = ref(false)
  const unlockError = ref<string | null>(null)

  async function open(nextSlug: string): Promise<void> {
    if (slug.value !== nextSlug) {
      reset()
      slug.value = nextSlug
      token.value = readStoredToken(nextSlug)
    }

    state.value = 'loading'

    try {
      years.value = await publicApi.years(slug.value, token.value)
      anno.value = years.value[0]?.anno ?? null
      state.value = 'ready'

      await load()
    } catch (cause) {
      handleAccessError(cause)
    }
  }

  async function unlock(password: string): Promise<void> {
    unlocking.value = true
    unlockError.value = null

    try {
      const access = await publicApi.unlock(slug.value, password)

      token.value = access.token
      storeToken(slug.value, access.token)

      await open(slug.value)
    } catch (cause) {
      unlockError.value =
        cause instanceof ApiError && cause.status === 401
          ? 'Password non corretta.'
          : cause instanceof ApiError
            ? cause.message
            : 'Non riesco a verificare la password.'
    } finally {
      unlocking.value = false
    }
  }

  /** Con `silent` non mostra il caricamento e, se fallisce, lascia le foto che ci sono. */
  async function load({ silent = false } = {}): Promise<void> {
    if (!silent) {
      loading.value = true
      error.value = null
    }

    try {
      const response = await publicApi.list(slug.value, token.value, {
        anno: anno.value,
        speciali: soloSpeciali.value,
        per_page: 500,
      })

      items.value = response.data
      loadedAt.value = Date.now()
    } catch (cause) {
      if (cause instanceof ApiError && (cause.status === 401 || cause.status === 404)) {
        handleAccessError(cause)

        return
      }

      if (silent) {
        return
      }

      error.value = cause instanceof ApiError ? cause.message : 'Non riesco a caricare le foto.'
      items.value = []
      loadedAt.value = null
    } finally {
      if (!silent) {
        loading.value = false
      }
    }
  }

  /** Se gli URL delle immagini stanno per scadere, ricarica la lista in silenzio. */
  async function refreshIfStale(): Promise<void> {
    if (state.value === 'ready' && !loading.value && signedUrlsAreStale(loadedAt.value)) {
      await load({ silent: true })
    }
  }

  /** Come nella timeline privata: URL nuovi per una foto la cui immagine non si carica più. */
  async function refreshImage(id: number): Promise<void> {
    const fresh = await publicApi.get(slug.value, token.value, id).catch(() => null)
    const index = items.value.findIndex((item) => item.id === id)

    if (fresh && index >= 0) {
      items.value[index] = {
        ...items.value[index],
        thumbnail_url: fresh.thumbnail_url,
        medium_url: fresh.medium_url,
        image_url: fresh.image_url,
      }
    }
  }

  function setAnno(value: number | null): void {
    anno.value = value
    void load()
  }

  function setSoloSpeciali(value: boolean): void {
    soloSpeciali.value = value
    void load()
  }

  function find(id: number): Photo | undefined {
    return items.value.find((photo) => photo.id === id)
  }

  function photo(id: number): Promise<Photo> {
    return publicApi.get(slug.value, token.value, id)
  }

  /**
   * 401 vuol dire che serve (o è scaduta) la password; 404 che il diario non
   * è pubblico, senza distinguere fra famiglia privata e slug inesistente.
   */
  function handleAccessError(cause: unknown): void {
    if (cause instanceof ApiError && cause.status === 401) {
      forgetToken()
      state.value = 'password'

      return
    }

    state.value = 'unavailable'
  }

  function forgetToken(): void {
    token.value = null
    safely(() => localStorage.removeItem(tokenKey(slug.value)))
  }

  function reset(): void {
    items.value = []
    years.value = []
    anno.value = null
    soloSpeciali.value = false
    error.value = null
    loadedAt.value = null
    unlockError.value = null
  }

  return {
    slug,
    state,
    items,
    years,
    anno,
    soloSpeciali,
    loading,
    error,
    unlocking,
    unlockError,
    open,
    unlock,
    load,
    setAnno,
    setSoloSpeciali,
    find,
    photo,
    refreshIfStale,
    refreshImage,
  }
})

function readStoredToken(slug: string): string | null {
  return safely(() => localStorage.getItem(tokenKey(slug))) ?? null
}

function storeToken(slug: string, token: string): void {
  safely(() => localStorage.setItem(tokenKey(slug), token))
}

/** In navigazione privata l'accesso a localStorage può lanciare. */
function safely<T>(action: () => T): T | null {
  try {
    return action()
  } catch {
    return null
  }
}
