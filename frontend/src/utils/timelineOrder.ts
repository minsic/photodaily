import type { Photo } from '@/api/types'

/**
 * Ordine della timeline scelto su questo dispositivo: dal più recente
 * (predefinito) o dal primo giorno, per rileggere la storia dall'inizio.
 */
export type TimelineOrder = 'desc' | 'asc'

const KEY = 'photodaily.ordine'

type Storage = Pick<globalThis.Storage, 'getItem' | 'setItem'>

function defaultStorage(): Storage | null {
  try {
    return globalThis.localStorage ?? null
  } catch {
    return null
  }
}

export function readTimelineOrder(storage: Storage | null = defaultStorage()): TimelineOrder {
  try {
    return storage?.getItem(KEY) === 'asc' ? 'asc' : 'desc'
  } catch {
    return 'desc'
  }
}

export function saveTimelineOrder(order: TimelineOrder, storage: Storage | null = defaultStorage()): void {
  try {
    storage?.setItem(KEY, order)
  } catch {
    // Solo una comodità: senza, si riparte dal più recente.
  }
}

/** Data più recente prima, a parità di data l'ultima caricata. */
export function byDateDesc(a: Photo, b: Photo): number {
  return a.data === b.data ? b.id.localeCompare(a.id) : b.data.localeCompare(a.data)
}

/** Stesso ordine dell'API con ordine=asc: data, poi prima caricata. */
export function byDateAsc(a: Photo, b: Photo): number {
  return byDateDesc(b, a)
}

export function comparatorFor(order: TimelineOrder): (a: Photo, b: Photo) => number {
  return order === 'asc' ? byDateAsc : byDateDesc
}

/**
 * Le foto con, davanti alla prima di ogni anno, l'anno da mostrare come
 * separatore: mescolando gli anni la data da sola ("SAB 14 MARZO") non basta.
 */
export function withYearBreaks<T extends Pick<Photo, 'data'>>(photos: T[]): Array<{ photo: T; year: number | null }> {
  let previous: string | null = null

  return photos.map((photo) => {
    const year = photo.data.slice(0, 4)
    const isBreak = year !== previous

    previous = year

    return { photo, year: isBreak ? Number(year) : null }
  })
}
