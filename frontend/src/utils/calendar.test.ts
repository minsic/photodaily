import { describe, expect, it } from 'vitest'

import type { PhotoCalendar } from '@/api/types'

import { buildCalendar, describeProgress } from './calendar'
import { isIsoDate, todayIso } from './date'
import { vapidKeyToBytes } from './push'

const calendar: PhotoCalendar = {
  anno: 2026,
  oggi: '2026-03-15',
  inizio: '2026-03-01',
  fine: '2026-03-15',
  giorni: [
    { data: '2026-03-01', foto_id: 'f10', foto: 1, speciale: false },
    { data: '2026-03-03', foto_id: 'f12', foto: 2, speciale: true },
  ],
  bozze: [{ data: '2026-03-10', foto_id: 'f20' }],
  vuoti: [],
  pieni: 2,
  totali: 15,
}

describe('buildCalendar', () => {
  const months = buildCalendar(calendar)

  it('ha dodici mesi con i giorni giusti, anche a febbraio', () => {
    expect(months).toHaveLength(12)
    expect(months.map((month) => month.days.length)).toEqual([31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31])
  })

  it('le settimane cominciano di lunedì', () => {
    // 1 gennaio 2026 è giovedì, 1 marzo 2026 è domenica.
    expect(months[0]!.offset).toBe(3)
    expect(months[2]!.offset).toBe(6)
  })

  it('dà a ogni giorno il suo stato', () => {
    const march = months[2]!.days
    const day = (n: number) => march[n - 1]!

    expect(day(1)).toMatchObject({ state: 'pieno', photoId: 'f10', photos: 1 })
    expect(day(3)).toMatchObject({ state: 'pieno', photoId: 'f12', photos: 2, special: true })
    expect(day(10)).toMatchObject({ state: 'bozza', photoId: 'f20' })
    expect(day(2)).toMatchObject({ state: 'vuoto', photoId: null })
    expect(day(15).state).toBe('vuoto')
    expect(day(16).state).toBe('fuori')
    expect(months[1]!.days[27]!.state).toBe('fuori')
  })

  it('senza intervallo non ci sono giorni vuoti', () => {
    const empty = buildCalendar({ ...calendar, inizio: null, fine: null, giorni: [], bozze: [] })

    expect(empty.flatMap((month) => month.days).every((day) => day.state === 'fuori')).toBe(true)
  })
})

describe('describeProgress', () => {
  it('conta i giorni con la foto, senza serie', () => {
    expect(describeProgress({ ...calendar, pieni: 312, totali: 365 })).toBe('312 giorni su 365')
    expect(describeProgress({ ...calendar, pieni: 1, totali: 1 })).toBe('1 giorno su 1')
    expect(describeProgress({ ...calendar, pieni: 0, totali: 0 })).toBeNull()
  })
})

describe('todayIso', () => {
  // 23:30 UTC del 14 marzo: a Roma è già il 15, a New York è ancora il 14.
  const now = new Date(Date.UTC(2026, 2, 14, 23, 30))

  it('usa il fuso della famiglia', () => {
    expect(todayIso('Europe/Rome', now)).toBe('2026-03-15')
    expect(todayIso('America/New_York', now)).toBe('2026-03-14')
  })

  it('con un fuso sconosciuto ripiega su Europe/Rome', () => {
    expect(todayIso('Europa/Roma', now)).toBe('2026-03-15')
  })
})

describe('isIsoDate', () => {
  it('accetta solo date vere in formato YYYY-MM-DD', () => {
    expect(isIsoDate('2026-03-15')).toBe(true)
    expect(isIsoDate('2024-02-29')).toBe(true)
    expect(isIsoDate('2025-02-29')).toBe(false)
    expect(isIsoDate('15/03/2026')).toBe(false)
    expect(isIsoDate(undefined)).toBe(false)
  })
})

describe('vapidKeyToBytes', () => {
  it('decodifica il base64 url-safe senza padding della chiave VAPID', () => {
    // "AQID_-8" = byte 1, 2, 3, 255, 239
    expect([...vapidKeyToBytes('AQID_-8')]).toEqual([1, 2, 3, 255, 239])
  })
})
