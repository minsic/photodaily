import { describe, expect, it } from 'vitest'

import { isValidSlug, slugify } from './slug'

describe('slugify', () => {
  it('toglie accenti, maiuscole e simboli', () => {
    expect(slugify('Famiglia Rossi')).toBe('famiglia-rossi')
    expect(slugify("  L'estate di Niccolò!  ")).toBe('l-estate-di-niccolo')
    expect(slugify('Gio & Pellino 2026')).toBe('gio-pellino-2026')
  })

  it('resta entro 30 caratteri senza trattini ai bordi', () => {
    const slug = slugify('Il diario fotografico della famiglia Esposito')

    expect(slug.length).toBeLessThanOrEqual(30)
    expect(slug.endsWith('-')).toBe(false)
    expect(isValidSlug(slug)).toBe(true)
  })

  it('riconosce gli indirizzi validi', () => {
    expect(isValidSlug('rossi')).toBe(true)
    expect(isValidSlug('ab')).toBe(false)
    expect(isValidSlug('-rossi')).toBe(false)
    expect(isValidSlug('Rossi')).toBe(false)
  })
})
