import { describe, expect, it } from 'vitest'

import { describeHearts } from './hearts'

describe('describeHearts', () => {
  it('mette "te" per primo e unisce i nomi in italiano', () => {
    expect(describeHearts(false, [])).toBeNull()
    expect(describeHearts(true, [])).toBe('Piace a te')
    expect(describeHearts(false, ['Nonna'])).toBe('Piace a Nonna')
    expect(describeHearts(true, ['Nonna'])).toBe('Piace a te e Nonna')
    expect(describeHearts(true, ['Nonna', 'Zia'])).toBe('Piace a te, Nonna e Zia')
  })

  it('oltre tre nomi riassume', () => {
    expect(describeHearts(true, ['Nonna', 'Zia', 'Papà', 'Nonno'])).toBe('Piace a te, Nonna e altri 3')
  })
})
