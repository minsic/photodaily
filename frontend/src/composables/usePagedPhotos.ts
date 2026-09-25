import { computed, ref } from 'vue'

import type { Paginated, Photo } from '@/api/types'

/** Foto per pagina: la timeline ne carica 50 alla volta mentre si scorre. */
export const PAGE_SIZE = 50

/** Limite di per_page lato API (IndexPhotosRequest). */
const MAX_PER_PAGE = 500

/** Ordine della timeline: data più recente prima, a parità di data l'ultima caricata. */
export function byDateDesc(a: Photo, b: Photo): number {
  return a.data === b.data ? b.id - a.id : b.data.localeCompare(a.data)
}

/**
 * Elenco di foto caricato a pagine, condiviso dalla timeline privata e da
 * quella pubblica. `fetchPage` fa la richiesta con i filtri correnti.
 *
 * Ogni caricamento da capo apre una "generazione": le risposte di pagine
 * chieste prima di un cambio di filtri arrivano tardi e vengono scartate,
 * invece di mescolare anni diversi.
 */
export function usePagedPhotos(fetchPage: (page: number, perPage: number) => Promise<Paginated<Photo>>) {
  const items = ref<Photo[]>([])
  const page = ref(0)
  const lastPage = ref(0)
  const loadingMore = ref(false)
  const moreError = ref(false)
  /** Quando sono state caricate le foto mostrate: gli URL delle immagini scadono dopo un'ora. */
  const loadedAt = ref<number | null>(null)

  const hasMore = computed(() => page.value < lastPage.value)

  let generation = 0

  /** Prima pagina, al posto di tutto quello che c'era. Gli errori li gestisce chi chiama. */
  async function loadFirst(): Promise<void> {
    const current = ++generation
    const response = await fetchPage(1, PAGE_SIZE)

    if (current !== generation) {
      return
    }

    items.value = response.data
    page.value = response.meta.current_page
    lastPage.value = response.meta.last_page
    moreError.value = false
    loadedAt.value = Date.now()
  }

  /** Pagina successiva, in coda. Non fa nulla se è già in corso o se non c'è altro. */
  async function loadMore(): Promise<void> {
    if (!hasMore.value || loadingMore.value) {
      return
    }

    const current = generation

    loadingMore.value = true
    moreError.value = false

    try {
      const response = await fetchPage(page.value + 1, PAGE_SIZE)

      if (current !== generation) {
        return
      }

      // Se nel frattempo si è aggiunta o tolta una foto le pagine sono
      // scivolate di una posizione: i doppioni si scartano.
      const known = new Set(items.value.map((photo) => photo.id))

      items.value = [...items.value, ...response.data.filter((photo) => !known.has(photo.id))].sort(byDateDesc)
      page.value = response.meta.current_page
      lastPage.value = response.meta.last_page
    } catch {
      if (current === generation) {
        moreError.value = true
      }
    } finally {
      if (current === generation) {
        loadingMore.value = false
      }
    }
  }

  /**
   * Ricarica in una sola richiesta tutte le foto già mostrate (per avere URL
   * nuovi) senza perdere la posizione nello scorrimento.
   */
  async function reloadLoaded(): Promise<void> {
    const current = ++generation
    const shown = Math.max(page.value, 1) * PAGE_SIZE
    const response = await fetchPage(1, Math.min(shown, MAX_PER_PAGE))

    if (current !== generation) {
      return
    }

    items.value = response.data
    page.value = Math.ceil(response.data.length / PAGE_SIZE) || 1
    lastPage.value = Math.max(Math.ceil(response.meta.total / PAGE_SIZE), page.value)
    loadedAt.value = Date.now()
    loadingMore.value = false
  }

  /**
   * Inserisce una foto nuova o modificata al suo posto. Se è più vecchia
   * dell'ultima caricata e ci sono altre pagine, arriverà con lo scorrimento.
   */
  function insertSorted(photo: Photo): void {
    const last = items.value[items.value.length - 1]

    if (hasMore.value && last && byDateDesc(photo, last) > 0) {
      return
    }

    items.value = [...items.value, photo].sort(byDateDesc)
  }

  function clear(): void {
    generation++
    items.value = []
    page.value = 0
    lastPage.value = 0
    loadingMore.value = false
    moreError.value = false
    loadedAt.value = null
  }

  return { items, hasMore, loadingMore, moreError, loadedAt, loadFirst, loadMore, reloadLoaded, insertSorted, clear }
}
