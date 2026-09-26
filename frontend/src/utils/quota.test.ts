import { describe, expect, it } from 'vitest'

import type { Quota } from '@/api/types'

import { describeStorage, formatStorage, uploadBlocked } from './quota'

const quota = (overrides: Partial<Quota> = {}): Quota => ({
  plan: 'Free',
  photos: 10,
  max_photos: 12,
  storage_used_mb: 820,
  max_storage_mb: 5120,
  blocked: null,
  ...overrides,
})

describe('formatStorage e describeStorage', () => {
  it('usa i MB sotto il GB e i GB con la virgola sopra', () => {
    expect(formatStorage(750.4)).toBe('750 MB')
    expect(formatStorage(1843)).toBe('1,8 GB')
    expect(formatStorage(12800)).toBe('13 GB')
  })

  it('dice il massimo del piano, o che non c’è limite', () => {
    expect(describeStorage(quota({ storage_used_mb: 1843 }))).toBe('Spazio usato 1,8 GB di 5 GB')
    expect(describeStorage(quota({ max_storage_mb: null }))).toBe('Spazio usato 820 MB (senza limite)')
  })
})

describe('uploadBlocked', () => {
  it('lascia caricare finché ci sono posti', () => {
    expect(uploadBlocked(quota())).toBeNull()
    expect(uploadBlocked(quota(), 2)).toBeNull()
    expect(uploadBlocked(quota({ max_photos: null }), 500)).toBeNull()
  })

  it('dice quante se ne possono ancora caricare', () => {
    expect(uploadBlocked(quota(), 5)).toContain('ne puoi caricare ancora 2')
  })

  it('ripete il messaggio del server quando è già tutto pieno', () => {
    expect(uploadBlocked(quota({ blocked: 'Spazio finito.' }))).toBe('Spazio finito.')
  })
})
