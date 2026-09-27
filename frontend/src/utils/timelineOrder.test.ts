import { describe, expect, it } from 'vitest'

import type { Photo } from '@/api/types'

import { byDateAsc, byDateDesc, readTimelineOrder, saveTimelineOrder, withYearBreaks } from './timelineOrder'

function memoryStorage(initial: Record<string, string> = {}) {
  const data = { ...initial }

  return {
    getItem: (key: string) => data[key] ?? null,
    setItem: (key: string, value: string) => {
      data[key] = value
    },
  }
}

const photo = (data: string, id: string) => ({ data, id }) as Photo

describe('ordine della timeline', () => {
  it('parte dal più recente e ricorda la scelta', () => {
    const storage = memoryStorage()

    expect(readTimelineOrder(storage)).toBe('desc')
    saveTimelineOrder('asc', storage)
    expect(readTimelineOrder(storage)).toBe('asc')
    expect(readTimelineOrder(memoryStorage({ 'photodaily.ordine': 'boh' }))).toBe('desc')
  })

  it('non si rompe se il browser blocca lo storage', () => {
    const broken = {
      getItem: () => {
        throw new Error('bloccato')
      },
      setItem: () => {
        throw new Error('bloccato')
      },
    }

    expect(readTimelineOrder(broken)).toBe('desc')
    expect(() => saveTimelineOrder('asc', broken)).not.toThrow()
  })

  it('ordina per data e, nello stesso giorno, per caricamento', () => {
    const list = [photo('2025-03-01', 'B'), photo('2024-12-31', 'A'), photo('2025-03-01', 'C')]

    expect([...list].sort(byDateDesc).map((p) => p.id)).toEqual(['C', 'B', 'A'])
    expect([...list].sort(byDateAsc).map((p) => p.id)).toEqual(['A', 'B', 'C'])
  })
})

describe('separatori d\'anno', () => {
  it('segna la prima foto di ogni anno, in tutti e due i versi', () => {
    const desc = [photo('2026-01-02', 'D'), photo('2025-12-31', 'C'), photo('2025-06-01', 'B'), photo('2024-01-01', 'A')]

    expect(withYearBreaks(desc).map((item) => item.year)).toEqual([2026, 2025, null, 2024])
    expect(withYearBreaks([...desc].reverse()).map((item) => item.year)).toEqual([2024, 2025, null, 2026])
    expect(withYearBreaks([])).toEqual([])
  })
})
