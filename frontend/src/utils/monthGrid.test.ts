import { describe, expect, it } from 'vitest'

import type { PhotoMonth } from '@/api/types'

import { buildMonthGrid, describeMonthCount, formatMonth, isBeforeDiary, nextMonth, previousMonth } from './monthGrid'

const march: PhotoMonth = {
  anno: 2026,
  mese: 3,
  oggi: '2026-03-15',
  inizio_diario: '2026-03-02',
  giorni: [
    { data: '2026-03-03', id: 'a', thumbnail_url: 't', foto: 2, bozze: 0, speciale: true },
    { data: '2026-03-10', id: 'b', thumbnail_url: 't', foto: 0, bozze: 1, speciale: false },
  ],
  pieni: 1,
  totali: 14,
}

describe('buildMonthGrid', () => {
  it('parte di lunedì e dà a ogni giorno il suo stato', () => {
    const grid = buildMonthGrid(march)
    const state = (day: number) => grid.cells[day - 1]!.state

    // Il 1° marzo 2026 è domenica: sei caselle vuote prima.
    expect(grid.offset).toBe(6)
    expect(grid.cells).toHaveLength(31)
    expect(state(1)).toBe('prima')
    expect(state(2)).toBe('vuoto')
    expect(state(3)).toBe('pieno')
    expect(grid.cells[2]!.photo?.id).toBe('a')
    expect(state(10)).toBe('bozza')
    expect(state(15)).toBe('vuoto')
    expect(state(16)).toBe('futuro')
  })

  it('senza inizio del diario tutti i giorni passati si possono riempire', () => {
    expect(buildMonthGrid({ ...march, inizio_diario: null }).cells[0]!.state).toBe('vuoto')
  })
})

describe('conteggio e navigazione fra i mesi', () => {
  it('scrive "N foto su M giorni", niente per i mesi senza giorni da contare', () => {
    expect(describeMonthCount({ pieni: 24, totali: 30 })).toBe('24 foto su 30 giorni')
    expect(describeMonthCount({ pieni: 1, totali: 1 })).toBe('1 foto su 1 giorno')
    expect(describeMonthCount({ pieni: 0, totali: 0 })).toBeNull()
  })

  it('passa da un anno all’altro', () => {
    expect(previousMonth(2026, 1)).toEqual({ year: 2025, month: 12 })
    expect(nextMonth(2025, 12)).toEqual({ year: 2026, month: 1 })
  })

  it('sa quando il mese è prima del diario', () => {
    expect(isBeforeDiary(2026, 2, '2026-03-02')).toBe(true)
    expect(isBeforeDiary(2026, 3, '2026-03-02')).toBe(false)
    expect(isBeforeDiary(1999, 1, null)).toBe(false)
  })

  it('scrive il mese in italiano', () => {
    expect(formatMonth(2025, 3)).toBe('marzo 2025')
  })
})
