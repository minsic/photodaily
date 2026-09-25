import { describe, expect, it } from 'vitest'

import { ageOn, describeAge } from './age'

describe('ageOn', () => {
  it('conta anni, mesi e giorni compiuti', () => {
    expect(ageOn('2023-11-14', '2025-02-18')).toEqual({ years: 1, months: 3, days: 4 })
  })

  it('il giorno della nascita è tutto a zero', () => {
    expect(ageOn('2023-11-14', '2023-11-14')).toEqual({ years: 0, months: 0, days: 0 })
  })

  it('è null prima della nascita o con date non valide', () => {
    expect(ageOn('2023-11-14', '2023-11-13')).toBeNull()
    expect(ageOn('14/11/2023', '2024-01-01')).toBeNull()
    expect(ageOn('2023-11-14', '')).toBeNull()
  })

  it('quando il giorno non esiste nel mese si ferma all\'ultimo giorno', () => {
    // 31 gennaio + 1 mese = 28 febbraio; al 1° marzo è passato un giorno in più.
    expect(ageOn('2025-01-31', '2025-02-28')).toEqual({ years: 0, months: 1, days: 0 })
    expect(ageOn('2025-01-31', '2025-03-01')).toEqual({ years: 0, months: 1, days: 1 })
    expect(ageOn('2025-01-31', '2025-03-31')).toEqual({ years: 0, months: 2, days: 0 })
  })

  it('chi nasce il 29 febbraio compie gli anni il 28 negli anni non bisestili', () => {
    expect(ageOn('2024-02-29', '2025-02-28')).toEqual({ years: 1, months: 0, days: 0 })
    expect(ageOn('2024-02-29', '2028-02-29')).toEqual({ years: 4, months: 0, days: 0 })
  })

  it('attraversa l\'ora legale senza perdere giorni', () => {
    expect(ageOn('2025-03-29', '2025-04-01')).toEqual({ years: 0, months: 0, days: 3 })
    expect(ageOn('2025-10-25', '2025-10-27')).toEqual({ years: 0, months: 0, days: 2 })
  })

  it('passa l\'anno a dicembre', () => {
    expect(ageOn('2024-12-20', '2025-01-05')).toEqual({ years: 0, months: 0, days: 16 })
    expect(ageOn('2024-12-20', '2025-12-19')).toEqual({ years: 0, months: 11, days: 29 })
  })
})

describe('describeAge', () => {
  it('scrive la frase completa', () => {
    expect(describeAge('2023-11-14', '2025-02-18', 'Gio')).toBe('Gio ha 1 anno, 3 mesi e 4 giorni')
  })

  it('usa singolare e plurale giusti', () => {
    expect(describeAge('2023-11-14', '2025-12-15', 'Gio')).toBe('Gio ha 2 anni, 1 mese e 1 giorno')
    expect(describeAge('2023-11-14', '2026-01-16', 'Gio')).toBe('Gio ha 2 anni, 2 mesi e 2 giorni')
  })

  it('salta le parti a zero', () => {
    expect(describeAge('2023-11-14', '2024-11-20', 'Gio')).toBe('Gio ha 1 anno e 6 giorni')
    expect(describeAge('2023-11-14', '2024-01-14', 'Gio')).toBe('Gio ha 2 mesi')
    expect(describeAge('2023-11-14', '2025-01-14', 'Gio')).toBe('Gio ha 1 anno e 2 mesi')
  })

  it('nel primo mese conta solo i giorni', () => {
    expect(describeAge('2023-11-14', '2023-11-15', 'Gio')).toBe('Gio ha 1 giorno')
    expect(describeAge('2023-11-14', '2023-12-13', 'Gio')).toBe('Gio ha 29 giorni')
  })

  it('il giorno della nascita e i compleanni hanno una frase loro', () => {
    expect(describeAge('2023-11-14', '2023-11-14', 'Gio')).toBe('Gio nasce oggi')
    expect(describeAge('2023-11-14', '2024-11-14', 'Gio')).toBe('Gio compie 1 anno')
    expect(describeAge('2023-11-14', '2026-11-14', 'Gio')).toBe('Gio compie 3 anni')
  })

  it('senza nome la frase regge lo stesso', () => {
    expect(describeAge('2023-11-14', '2023-11-20', null)).toBe('Ha 6 giorni')
    expect(describeAge('2023-11-14', '2023-11-14', '  ')).toBe('Nasce oggi')
    expect(describeAge('2023-11-14', '2024-11-14')).toBe('Compie 1 anno')
  })

  it('non dice niente senza data di nascita o prima della nascita', () => {
    expect(describeAge(null, '2024-01-01', 'Gio')).toBeNull()
    expect(describeAge(undefined, '2024-01-01', 'Gio')).toBeNull()
    expect(describeAge('', '2024-01-01', 'Gio')).toBeNull()
    expect(describeAge('2023-11-14', '2023-10-01', 'Gio')).toBeNull()
  })
})
