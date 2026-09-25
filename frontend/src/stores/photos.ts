import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import { photos as photosApi } from '@/api'
import { ApiError } from '@/api/client'
import type { Photo, YearSummary } from '@/api/types'
import { yearOf } from '@/utils/date'

type Stato = 'pubblicate' | 'bozze'

export const usePhotosStore = defineStore('photos', () => {
  const items = ref<Photo[]>([])
  const years = ref<YearSummary[]>([])
  const anno = ref<number | null>(null)
  const soloSpeciali = ref(false)
  const stato = ref<Stato>('pubblicate')

  const loading = ref(false)
  const error = ref<string | null>(null)
  /** Filtri con cui è stato riempito `items`: evita richieste inutili. */
  const loadedKey = ref<string | null>(null)

  const filterKey = computed(() => `${anno.value}|${soloSpeciali.value}|${stato.value}`)
  const hasFilters = computed(() => soloSpeciali.value || stato.value === 'bozze')

  async function loadYears(): Promise<void> {
    years.value = await photosApi.years()

    if (anno.value === null || !years.value.some((year) => year.anno === anno.value)) {
      anno.value = years.value[0]?.anno ?? null
    }
  }

  /** Carica le foto solo se i filtri sono cambiati (o se si forza). */
  async function load(force = false): Promise<void> {
    if (!force && loadedKey.value === filterKey.value) {
      return
    }

    const key = filterKey.value

    loading.value = true
    error.value = null

    try {
      const response = await photosApi.list({
        anno: stato.value === 'bozze' ? null : anno.value,
        speciali: soloSpeciali.value,
        stato: stato.value,
        // Un anno non può avere più di 366 foto: si carica tutto in una volta.
        per_page: 500,
      })

      items.value = response.data
      loadedKey.value = key
    } catch (cause) {
      error.value = cause instanceof ApiError ? cause.message : 'Non riesco a caricare le foto.'
      items.value = []
      loadedKey.value = null
    } finally {
      loading.value = false
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

  /** Aggiorna (o inserisce) una foto già caricata, mantenendo l'ordine per data. */
  function upsert(photo: Photo): void {
    const index = items.value.findIndex((item) => item.id === photo.id)
    const belongs = fitsFilters(photo)

    if (index >= 0) {
      if (belongs) {
        items.value[index] = { ...items.value[index], ...photo }
      } else {
        items.value.splice(index, 1)
      }
    } else if (belongs) {
      items.value.push(photo)
    }

    items.value.sort((a, b) => (a.data === b.data ? b.id - a.id : b.data.localeCompare(a.data)))
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
    items.value = []
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
    error,
    hasFilters,
    loadYears,
    load,
    setAnno,
    toggleSpeciali,
    setStato,
    find,
    upsert,
    drop,
    refreshImage,
    reset,
  }
})
