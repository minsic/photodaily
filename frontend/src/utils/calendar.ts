import type { PhotoCalendar } from '@/api/types'

/**
 * Stato di un giorno nella griglia del calendario:
 * - `pieno`: c'è almeno una foto pubblicata (si apre quella);
 * - `bozza`: c'è solo una bozza;
 * - `vuoto`: dovrebbe avere una foto e non ce l'ha (si apre il caricamento);
 * - `fuori`: prima della nascita/prima foto, o nel futuro.
 */
export type DayState = 'pieno' | 'bozza' | 'vuoto' | 'fuori'

export interface CalendarDay {
  date: string
  day: number
  state: DayState
  photoId: number | null
  photos: number
  special: boolean
}

export interface CalendarMonth {
  month: number
  /** Caselle vuote prima del giorno 1: le settimane iniziano di lunedì. */
  offset: number
  days: CalendarDay[]
}

function pad(value: number): string {
  return String(value).padStart(2, '0')
}

/** I dodici mesi dell'anno, giorno per giorno, con lo stato di ognuno. */
export function buildCalendar(calendar: PhotoCalendar): CalendarMonth[] {
  const full = new Map(calendar.giorni.map((day) => [day.data, day]))
  const drafts = new Map(calendar.bozze.map((day) => [day.data, day]))

  return Array.from({ length: 12 }, (_, index) => {
    const month = index + 1
    const length = new Date(Date.UTC(calendar.anno, month, 0)).getUTCDate()
    const offset = (new Date(Date.UTC(calendar.anno, index, 1)).getUTCDay() + 6) % 7

    const days = Array.from({ length }, (_, dayIndex): CalendarDay => {
      const date = `${calendar.anno}-${pad(month)}-${pad(dayIndex + 1)}`
      const photo = full.get(date)
      const draft = drafts.get(date)
      const inRange =
        calendar.inizio !== null && calendar.fine !== null && date >= calendar.inizio && date <= calendar.fine

      return {
        date,
        day: dayIndex + 1,
        state: photo ? 'pieno' : draft ? 'bozza' : inRange ? 'vuoto' : 'fuori',
        photoId: photo?.foto_id ?? draft?.foto_id ?? null,
        photos: photo?.foto ?? 0,
        special: photo?.speciale ?? false,
      }
    })

    return { month, offset, days }
  })
}

/** "312 giorni su 365", o null se non c'è ancora niente da contare. */
export function describeProgress(calendar: PhotoCalendar): string | null {
  if (calendar.totali === 0) {
    return null
  }

  return `${calendar.pieni} ${calendar.pieni === 1 ? 'giorno' : 'giorni'} su ${calendar.totali}`
}
