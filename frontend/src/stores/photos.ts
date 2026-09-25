import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import { photos as photosApi } from '@/api'
import { ApiError } from '@/api/client'
import type { Photo, YearSummary } from '@/api/types'
import { byDateDesc, usePagedPhotos } from '@/composables/usePagedPhotos'
import { yearOf } from '@/utils/date'
import { signedUrlsAreStale } from '@/utils/signedUrls'

type Stato = 'pubblicate' | 'bozze'

export const usePhotosStore = defineStore('photos', () => {
  const years = ref<YearSummary[]>([])
  const anno = ref<number | null>(null)
  const soloSpeciali = ref(false)
  const stato = ref<Stato>('pubblicate')

  const loading = ref(false)
  const error = ref<string | null>(null)
  /** Filtri con cui è stato riempito l'elenco: evita richieste inutili. */
  const loadedKey = ref<string | null>(null)

  const filterKey = computed(() => `${anno.value}|${soloSpeciali.value}|${stato.value}`)
  const hasFilters = computed(() => soloSpeciali.value || stato.value === 'bozze')

  const paged = usePagedPhotos((page, perPage) =>
    photosApi.list({
      anno: stato.value === 'bozze' ? null : anno.value,
      speciali: soloSpeciali.value,
      stato: stato.value,
      page,
      per_page: perPage,
    }),
  )
  const items = paged.items

  /**
   * Anni con foto pubblicate. Si parte sempre dal più recente: l'anno scelto
   * resta solo finché l'app è aperta (tornando da una foto si ritrova).
   */
  async function loadYears(): Promise<void> {
    years.value = await photosApi.years()

    if (anno.value === null || !years.value.some((year) => year.anno === anno.value)) {
      anno.value = years.value[0]?.anno ?? null
    }
  }

  /**
   * Carica la prima pagina solo se i filtri sono cambiati (o se si forza).
   * Le pagine successive arrivano con loadMore mentre si scorre.
   */
  async function load(force = false): Promise<void> {
    if (!force && loadedKey.value === filterKey.value) {
      return
    }

    const key = filterKey.value

    loading.value = true
    error.value = null

    try {
      await paged.loadFirst()
      loadedKey.value = key
    } catch (cause) {
      error.value = cause instanceof ApiError ? cause.message : 'Non riesco a caricare le foto.'
      paged.clear()
      loadedKey.value = null
    } finally {
      loading.value = false
    }
  }

  function loadMore(): Promise<void> {
    return loading.value ? Promise.resolve() : paged.loadMore()
  }

  /**
   * Se gli URL delle immagini stanno per scadere, ricarica in silenzio le
   * foto già mostrate: niente spinner e, se fallisce, restano quelle che ci sono.
   */
  async function refreshIfStale(): Promise<void> {
    if (loadedKey.value !== null && !loading.value && signedUrlsAreStale(paged.loadedAt.value)) {
      await paged.reloadLoaded().catch(() => undefined)
    }
  }

  function setAnno(value: number | null): void {
    anno.value = value
  }

  function toggleSpeciali(): void {
    soloSpeciali.value = !soloSpeciali.value
  }

  function setStato(value: Stato): void {
    stato.value = value
  }

  function find(id: number): Photo | undefined {
    return items.value.find((photo) => photo.id === id)
  }

  /**
   * Aggiorna una foto già in elenco (al suo posto, poi riordinando) o ne
   * inserisce una nuova, mantenendo l'ordine per data.
   */
  function upsert(photo: Photo): void {
    const index = items.value.findIndex((item) => item.id === photo.id)
    const belongs = fitsFilters(photo)

    if (index < 0) {
      if (belongs) {
        paged.insertSorted(photo)
      }

      return
    }

    if (belongs) {
      items.value[index] = { ...items.value[index], ...photo }
      items.value.sort(byDateDesc)
    } else {
      items.value.splice(index, 1)
    }
  }

  function drop(id: number): void {
    items.value = items.value.filter((photo) => photo.id !== id)
  }

  /**
   * Gli URL delle immagini sono firmati e scadono: quando il browser non
   * riesce più a caricarne una, si richiede la foto per avere un URL nuovo.
   */
  async function refreshImage(id: number): Promise<void> {
    const fresh = await photosApi.get(id).catch(() => null)

    if (!fresh) {
      return
    }

    const index = items.value.findIndex((photo) => photo.id === id)

    if (index >= 0) {
      items.value[index] = {
        ...items.value[index],
        thumbnail_url: fresh.thumbnail_url,
        medium_url: fresh.medium_url,
        image_url: fresh.image_url,
      }
    }
  }

  function fitsFilters(photo: Photo): boolean {
    if (stato.value === 'bozze') {
      return photo.is_draft
    }

    if (photo.is_draft) {
      return false
    }

    if (soloSpeciali.value && !photo.data_speciale) {
      return false
    }

    return anno.value === null || yearOf(photo.data) === anno.value
  }

  function reset(): void {
    paged.clear()
    years.value = []
    anno.value = null
    soloSpeciali.value = false
    stato.value = 'pubblicate'
    loadedKey.value = null
    error.value = null
  }

  return {
    items,
    years,
    anno,
    soloSpeciali,
    stato,
    loading,
    loadingMore: paged.loadingMore,
    moreError: paged.moreError,
    hasMore: paged.hasMore,
    error,
    hasFilters,
    loadYears,
    load,
    loadMore,
    setAnno,
    toggleSpeciali,
    setStato,
    find,
    upsert,
    drop,
    refreshImage,
    refreshIfStale,
    reset,
  }
})
