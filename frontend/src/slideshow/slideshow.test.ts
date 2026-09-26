import { describe, expect, it, vi } from 'vitest'

import type { SequenceItem, SequencePage } from '@/api/types'

import { ImageBuffer, REFILL_WHEN_LEFT, scopeFilters, SlideFeed } from './slideshow'

describe('scopeFilters', () => {
  it('traduce le scelte del pannello nei filtri dell’endpoint', () => {
    expect(scopeFilters('da-qui', '2024-02-10')).toEqual({ da: '2024-02-10' })
    expect(scopeFilters('mese', '2024-02-10')).toEqual({ da: '2024-02-01', a: '2024-02-29' })
    expect(scopeFilters('mese', '2023-12-31')).toEqual({ da: '2023-12-01', a: '2023-12-31' })
    expect(scopeFilters('anno', '2024-02-10')).toEqual({ da: '2024-01-01', a: '2024-12-31' })
    expect(scopeFilters('speciali', '2024-02-10')).toEqual({ speciali: true })
    expect(scopeFilters('tutto', '2024-02-10')).toEqual({})
  })
})

function pages(total: number, size = 100) {
  const items: SequenceItem[] = Array.from({ length: total }, (_, i) => ({
    id: `f${i}`,
    data: '2024-01-01',
    data_speciale: false,
    medium_url: `m${i}`,
    thumbnail_url: `t${i}`,
    medium_width: null,
    medium_height: null,
  }))

  return vi.fn(async (filters: { dopo?: string }): Promise<SequencePage> => {
    const start = filters.dopo ? items.findIndex((item) => item.id === filters.dopo) + 1 : 0
    const data = items.slice(start, start + size)

    return { data, next: start + size < total ? data.at(-1)!.id : null }
  })
}

describe('SlideFeed', () => {
  it('chiede il blocco successivo solo quando ne restano pochi', async () => {
    const fetch = pages(250)
    const feed = new SlideFeed(fetch, { da: '2024-01-01' })

    await feed.ensure(0)
    expect(feed.items).toHaveLength(100)
    expect(fetch).toHaveBeenLastCalledWith({ da: '2024-01-01' })

    await feed.ensure(100 - REFILL_WHEN_LEFT - 1)
    expect(fetch).toHaveBeenCalledTimes(1)

    await feed.ensure(100 - REFILL_WHEN_LEFT)
    expect(feed.items).toHaveLength(200)
    expect(fetch).toHaveBeenLastCalledWith({ da: '2024-01-01', dopo: 'f99' })

    await feed.ensure(199)
    await feed.ensure(249)
    expect(feed.items).toHaveLength(250)
    expect(feed.complete).toBe(true)
    expect(fetch).toHaveBeenCalledTimes(3)
  })

  it('non raddoppia le richieste in corso e riparte da capo col ciclo', async () => {
    const fetch = pages(30)
    const feed = new SlideFeed(fetch, {})

    await Promise.all([feed.ensure(0), feed.ensure(0)])
    expect(fetch).toHaveBeenCalledTimes(1)
    expect(feed.complete).toBe(true)

    feed.reset()
    await feed.ensure(0)
    expect(feed.items).toHaveLength(30)
    expect(fetch).toHaveBeenCalledTimes(2)
  })
})

describe('ImageBuffer', () => {
  it('dice quando la prossima è pronta, salta quelle rotte e dimentica le passate', async () => {
    const resolvers = new Map<string, { ok: () => void; ko: () => void }>()
    const buffer = new ImageBuffer(
      (src) => new Promise<void>((ok, ko) => resolvers.set(src, { ok, ko: () => ko(new Error(src)) })),
    )

    buffer.fill(['a', 'b', 'a'])
    expect(resolvers.size).toBe(2)
    expect(buffer.settled('a')).toBe(false)

    resolvers.get('a')!.ok()
    resolvers.get('b')!.ko()
    await Promise.resolve()
    await Promise.resolve()

    expect(buffer.settled('a')).toBe(true)
    expect(buffer.settled('b')).toBe(true)
    expect(buffer.failed('b')).toBe(true)

    buffer.keepOnly(new Set(['b']))
    expect(buffer.settled('a')).toBe(false)
  })
})
