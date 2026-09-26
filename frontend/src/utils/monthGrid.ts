import type { MonthDay, PhotoMonth } from '@/api/types'

/**
 * Stato di un giorno nella vista Mese:
 * - `pieno`: c'è almeno una foto pubblicata;
 * - `bozza`: c'è solo una bozza;
 * - `vuoto`: passato (o oggi) e senza foto: chi può caricare vede il "+";
 * - `prima`: prima dell'inizio del diario;
 * - `futuro`: non ancora arrivato.
 */
export type MonthCellState = 'pieno' | 'bozza' | 'vuoto' | 'prima' | 'futuro'

export interface MonthCell {
  date: string
  day: number
  state: MonthCellState
  photo: MonthDay | null
}

export interface MonthGrid {
  year: number
  month: number
  /** Caselle vuote prima del giorno 1: la settimana inizia di lunedì. */
  offset: number
  cells: MonthCell[]
}

const pad = (value: number) => String(value).padStart(2, '0')

export function monthKey(year: number, month: number): string {
  return `${year}-${pad(month)}`
}

export function daysInMonth(year: number, month: number): number {
  return new Date(Date.UTC(year, month, 0)).getUTCDate()
}

export function buildMonthGrid(data: PhotoMonth): MonthGrid {
  const photos = new Map(data.giorni.map((day) => [day.data, day]))
  const offset = (new Date(Date.UTC(data.anno, data.mese - 1, 1)).getUTCDay() + 6) % 7

  const cells = Array.from({ length: daysInMonth(data.anno, data.mese) }, (_, index): MonthCell => {
    const date = `${data.anno}-${pad(data.mese)}-${pad(index + 1)}`
    const photo = photos.get(date) ?? null

    let state: MonthCellState

    if (photo) {
      state = photo.foto > 0 ? 'pieno' : 'bozza'
    } else if (date > data.oggi) {
      state = 'futuro'
    } else if (data.inizio_diario !== null && date < data.inizio_diario) {
      state = 'prima'
    } else {
      state = 'vuoto'
    }

    return { date, day: index + 1, state, photo }
  })

  return { year: data.anno, month: data.mese, offset, cells }
}

/** "24 foto su 30 giorni": giorni con la foto su quelli che dovevano averla. */
export function describeMonthCount(data: Pick<PhotoMonth, 'pieni' | 'totali'>): string | null {
  if (data.totali === 0) {
    return null
  }

  return `${data.pieni} foto su ${data.totali} ${data.totali === 1 ? 'giorno' : 'giorni'}`
}

export function previousMonth(year: number, month: number): { year: number; month: number } {
  return month === 1 ? { year: year - 1, month: 12 } : { year, month: month - 1 }
}

export function nextMonth(year: number, month: number): { year: number; month: number } {
  return month === 12 ? { year: year + 1, month: 1 } : { year, month: month + 1 }
}

/** Il mese viene prima di quello in cui inizia il diario: si smette di scorrere. */
export function isBeforeDiary(year: number, month: number, start: string | null): boolean {
  return start !== null && monthKey(year, month) < start.slice(0, 7)
}

const monthName = new Intl.DateTimeFormat('it-IT', { month: 'long', year: 'numeric', timeZone: 'UTC' })

/** "marzo 2025" */
export function formatMonth(year: number, month: number): string {
  return monthName.format(new Date(Date.UTC(year, month - 1, 1)))
}
